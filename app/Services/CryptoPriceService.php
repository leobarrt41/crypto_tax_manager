<?php

namespace App\Services;

use App\Models\CryptoAssetPrice;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CryptoPriceService
 *
 * Responsável por obter e armazenar preços históricos de criptoativos em BRL.
 *
 * Fontes de câmbio USD/BRL (em ordem de prioridade):
 *  1. Cache em memória (evita requisições repetidas na mesma execução)
 *  2. Banco de dados local (tabela crypto_asset_prices — cotação do símbolo "USD")
 *  3. API PTAX do Banco Central do Brasil — cotação oficial exigida pela Receita Federal
 *     Endpoint: CotacaoDolarDia  → cotação exata do dia
 *     Fallback: CotacaoDolarPeriodo → último dia útil disponível (fins de semana e feriados)
 *
 * A Receita Federal exige a cotação PTAX de venda (cotacaoVenda) para conversão de
 * ativos adquiridos em moeda estrangeira (Lei 14.754/2023, IN RFB 2.180/2024).
 */
class CryptoPriceService
{
    /**
     * Stablecoins que sempre valem exatamente $1 USD.
     */
    private const STABLECOINS = ['USDT', 'USDC', 'BUSD', 'DAI', 'TUSD', 'FDUSD'];

    /**
     * Cache em memória: ['YYYY-MM-DD' => float]
     * Evita múltiplas chamadas à API do BCB para a mesma data na mesma execução.
     */
    private array $usdBrlCache = [];

    // ─── API pública ──────────────────────────────────────────────────────────

    /**
     * Obtém (ou cria) o preço de um ativo em uma data específica.
     *
     * Fluxo:
     *  1. Banco local → retorna imediatamente se já existir
     *  2. Stablecoin  → price_usd = 1.0, price_brl = PTAX do dia
     *  3. Binance klines → price_usd do fechamento × PTAX do dia
     *  4. Falha total → retorna zeros (registrado no log)
     *
     * @return object {price_usd: float, price_brl: float}
     */
    public function getOrCreatePrice(string $symbol, Carbon $date): object
    {
        $dateStr = $date->toDateString();
        $symbol  = strtoupper($symbol);

        // 1. Verificar cache no banco
        $existing = CryptoAssetPrice::where('symbol', $symbol)
            ->whereDate('recorded_at', $dateStr)
            ->first();

        if ($existing && $existing->price_brl !== null && $existing->price_usdt !== null) {
            Log::debug("[Preço] Cache banco: {$symbol} em {$dateStr}.");
            return (object)[
                'price_usd' => (float) $existing->price_usdt,
                'price_brl' => (float) $existing->price_brl,
            ];
        }

        // 2. Stablecoins: USD = 1, BRL = PTAX
        if (in_array($symbol, self::STABLECOINS)) {
            return $this->handleStablecoin($symbol, $date, $dateStr);
        }

        // 3. Buscar preço em USD na Binance
        Log::info("[Binance] Buscando preço de {$symbol} em {$dateStr}...");
        $priceUsd = $this->getBinanceHistoricalPrice($symbol, $date);

        if ($priceUsd !== null && $priceUsd > 0) {
            $ptax     = $this->getUsdToBrlRate($date);
            $priceBrl = $ptax ? round($priceUsd * $ptax, 10) : 0;

            CryptoAssetPrice::updateOrCreate(
                ['symbol' => $symbol, 'recorded_at' => $dateStr],
                ['price_usdt' => $priceUsd, 'price_brl' => $priceBrl]
            );

            Log::info("[Preço] {$symbol} via Binance: USD {$priceUsd} × PTAX {$ptax} = R\$ {$priceBrl}");
            return (object)['price_usd' => (float) $priceUsd, 'price_brl' => (float) $priceBrl];
        }

        // 4. Falha total
        Log::error("[Preço] Impossível obter preço para {$symbol} em {$dateStr}.");
        return (object)['price_usd' => 0, 'price_brl' => 0];
    }

    // ─── Câmbio PTAX (BCB) ────────────────────────────────────────────────────

    /**
     * Retorna a cotação PTAX de venda (USD→BRL) para uma data.
     *
     * Estratégia:
     *  1. Cache em memória
     *  2. Banco local (símbolo "USD")
     *  3. API BCB — CotacaoDolarDia (cotação exata do dia)
     *  4. API BCB — CotacaoDolarPeriodo (último dia útil dos 7 dias anteriores)
     *
     * A Receita Federal aceita a cotação do último dia útil anterior quando
     * a data solicitada é feriado ou fim de semana (IN RFB 2.180/2024, art. 5º).
     */
    public function getUsdToBrlRate(Carbon $date): ?float
    {
        $dateStr = $date->toDateString();

        // 1. Cache em memória
        if (isset($this->usdBrlCache[$dateStr])) {
            Log::debug("[PTAX] Cache memória: {$dateStr} = {$this->usdBrlCache[$dateStr]}");
            return $this->usdBrlCache[$dateStr];
        }

        // 2. Banco local (símbolo reservado "USD" = cotação PTAX salva anteriormente)
        $cached = CryptoAssetPrice::where('symbol', 'USD')
            ->whereDate('recorded_at', $dateStr)
            ->first();

        if ($cached && $cached->price_brl !== null) {
            $rate = (float) $cached->price_brl;
            $this->usdBrlCache[$dateStr] = $rate;
            Log::debug("[PTAX] Cache banco: {$dateStr} = {$rate}");
            return $rate;
        }

        // 3. API BCB — cotação exata do dia
        $rate = $this->fetchPtaxDia($date);

        // 4. Fallback: último dia útil dos 7 dias anteriores
        if ($rate === null) {
            Log::info("[PTAX] Sem cotação para {$dateStr} (feriado/fim de semana). Buscando último dia útil...");
            $rate = $this->fetchPtaxUltimoDiaUtil($date);
        }

        if ($rate !== null) {
            // Persistir no banco para evitar nova chamada ao BCB
            CryptoAssetPrice::updateOrCreate(
                ['symbol' => 'USD', 'recorded_at' => $dateStr],
                ['price_brl' => $rate, 'price_usdt' => 1.0]
            );
            $this->usdBrlCache[$dateStr] = $rate;
            Log::info("[PTAX] Taxa USD→BRL para {$dateStr}: R\$ {$rate}");
        } else {
            Log::error("[PTAX] Falha ao obter cotação BCB para {$dateStr}.");
        }

        return $rate;
    }

    // ─── Métodos privados ─────────────────────────────────────────────────────

    /**
     * Trata stablecoins: price_usd = 1.0, price_brl = PTAX do dia.
     */
    private function handleStablecoin(string $symbol, Carbon $date, string $dateStr): object
    {
        Log::info("[Preço] {$symbol} é stablecoin. USD = 1.0");
        $ptax = $this->getUsdToBrlRate($date);

        if ($ptax) {
            CryptoAssetPrice::updateOrCreate(
                ['symbol' => $symbol, 'recorded_at' => $dateStr],
                ['price_usdt' => 1.0, 'price_brl' => $ptax]
            );
            Log::info("[Preço] Stablecoin {$symbol}: \$1 = R\$ {$ptax}");
            return (object)['price_usd' => 1.0, 'price_brl' => (float) $ptax];
        }

        Log::error("[PTAX] Falha ao obter cotação para stablecoin {$symbol} em {$dateStr}.");
        return (object)['price_usd' => 1.0, 'price_brl' => 0];
    }

    /**
     * Busca a cotação PTAX de venda exata do dia via endpoint CotacaoDolarDia.
     *
     * Formato da data na API BCB: MM-DD-YYYY
     * Retorna cotacaoVenda (exigido pela Receita Federal para conversão de ativos).
     */
    private function fetchPtaxDia(Carbon $date): ?float
    {
        // BCB usa formato MM-DD-YYYY
        $bcbDate = $date->format('m-d-Y');

        Log::info("[BCB PTAX] Buscando cotação do dia {$bcbDate}...");

        try {
            $response = Http::timeout(10)->get(
                "https://olinda.bcb.gov.br/olinda/servico/PTAX/versao/v1/odata/CotacaoDolarDia(dataCotacao=@dataCotacao)",
                [
                    '@dataCotacao' => "'{$bcbDate}'",
                    '$format'      => 'json',
                    '$select'      => 'cotacaoVenda,dataHoraCotacao',
                ]
            );

            if ($response->successful()) {
                $values = data_get($response->json(), 'value', []);
                if (!empty($values)) {
                    $rate = (float) $values[0]['cotacaoVenda'];
                    Log::info("[BCB PTAX] Cotação de venda em {$bcbDate}: R\$ {$rate}");
                    return $rate;
                }
                // value vazio = feriado ou fim de semana
                Log::info("[BCB PTAX] Sem cotação para {$bcbDate} (feriado/fim de semana).");
            } else {
                Log::warning("[BCB PTAX] Resposta inesperada para {$bcbDate}: HTTP {$response->status()}");
            }
        } catch (\Exception $e) {
            Log::error("[BCB PTAX] Exceção ao buscar cotação do dia {$bcbDate}: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Busca a cotação PTAX do último dia útil disponível nos 7 dias anteriores à data.
     * Usado como fallback para fins de semana e feriados.
     *
     * Endpoint: CotacaoDolarPeriodo — retorna todas as cotações do intervalo.
     * Ordenamos por dataHoraCotacao desc e pegamos a primeira (mais recente).
     */
    private function fetchPtaxUltimoDiaUtil(Carbon $date): ?float
    {
        // Janela: 7 dias antes até o dia anterior (não inclui o próprio dia)
        $dataFinal   = $date->copy()->subDay()->format('m-d-Y');
        $dataInicial = $date->copy()->subDays(7)->format('m-d-Y');

        Log::info("[BCB PTAX] Buscando último dia útil entre {$dataInicial} e {$dataFinal}...");

        try {
            $response = Http::timeout(10)->get(
                "https://olinda.bcb.gov.br/olinda/servico/PTAX/versao/v1/odata/CotacaoDolarPeriodo(dataInicial=@dataInicial,dataFinalCotacao=@dataFinalCotacao)",
                [
                    '@dataInicial'        => "'{$dataInicial}'",
                    '@dataFinalCotacao'   => "'{$dataFinal}'",
                    '$format'             => 'json',
                    '$select'             => 'cotacaoVenda,dataHoraCotacao',
                    '$orderby'            => 'dataHoraCotacao desc',
                    '$top'                => 1,
                ]
            );

            if ($response->successful()) {
                $values = data_get($response->json(), 'value', []);
                if (!empty($values)) {
                    $rate      = (float) $values[0]['cotacaoVenda'];
                    $dataHora  = $values[0]['dataHoraCotacao'] ?? 'desconhecida';
                    Log::info("[BCB PTAX] Último dia útil encontrado ({$dataHora}): R\$ {$rate}");
                    return $rate;
                }
                Log::warning("[BCB PTAX] Nenhuma cotação encontrada no período {$dataInicial} a {$dataFinal}.");
            } else {
                Log::warning("[BCB PTAX] Falha no endpoint de período: HTTP {$response->status()}");
            }
        } catch (\Exception $e) {
            Log::error("[BCB PTAX] Exceção ao buscar período: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Busca o preço de fechamento diário de um ativo na Binance.
     *
     * Estratégia de pares (em ordem de preferência):
     *  1. {SYMBOL}USDT  — par principal (stablecoin USD)
     *  2. {SYMBOL}BUSD  — par alternativo (stablecoin USD, comum até 2023)
     *  3. {SYMBOL}BRL   — par direto em BRL (retorna preço em BRL, não USD)
     *  4. {SYMBOL}BTC   — par em BTC; converte via preço BTC/USDT do mesmo dia
     *
     * A Binance mantém dados históricos de klines mesmo para pares deslistados
     * (ex: LUNAUSDT, FTTUSDT), portanto esta estratégia cobre a maioria dos casos
     * sem necessidade de APIs externas pagas.
     *
     * @return float|null  Preço em USD (ou null se todos os pares falharem)
     */
    private function getBinanceHistoricalPrice(string $symbol, Carbon $date): ?float
    {
        // Pares USD-equivalentes: retornam preço diretamente em USD
        $usdPairs = ["{$symbol}USDT", "{$symbol}BUSD", "{$symbol}FDUSD", "{$symbol}USDC"];

        foreach ($usdPairs as $pair) {
            $price = $this->fetchBinanceKlineClose($pair, $date);
            if ($price !== null && $price > 0) {
                Log::info("[Binance] Fechamento de {$pair} em {$date->toDateString()}: USD {$price}");
                return $price;
            }
        }

        // Fallback 1: par em BRL — converte para USD via PTAX
        $brlPair  = "{$symbol}BRL";
        $priceBrl = $this->fetchBinanceKlineClose($brlPair, $date);
        if ($priceBrl !== null && $priceBrl > 0) {
            $ptax = $this->getUsdToBrlRate($date);
            if ($ptax && $ptax > 0) {
                $priceUsd = round($priceBrl / $ptax, 10);
                Log::info("[Binance] {$brlPair} = R\$ {$priceBrl} ÷ PTAX {$ptax} = USD {$priceUsd}");
                return $priceUsd;
            }
        }

        // Fallback 2: par em BTC — converte via preço BTC/USDT do mesmo dia
        $btcPair   = "{$symbol}BTC";
        $priceInBtc = $this->fetchBinanceKlineClose($btcPair, $date);
        if ($priceInBtc !== null && $priceInBtc > 0) {
            $btcUsd = $this->fetchBinanceKlineClose('BTCUSDT', $date);
            if ($btcUsd !== null && $btcUsd > 0) {
                $priceUsd = round($priceInBtc * $btcUsd, 10);
                Log::info("[Binance] {$btcPair} = {$priceInBtc} BTC × USD {$btcUsd} = USD {$priceUsd}");
                return $priceUsd;
            }
        }

        // Fallback 3: CoinGecko Demo (últimos 365 dias, gratuito com chave demo)
        $cgPrice = $this->getCoinGeckoHistoricalPrice($symbol, $date);
        if ($cgPrice !== null && $cgPrice > 0) {
            return $cgPrice;
        }

        Log::warning("[Binance] Nenhum par encontrado para {$symbol} em {$date->toDateString()}.");
        return null;
    }

    /**
     * Busca o preço de fechamento de um par específico na Binance via klines.
     * Retorna null se o par não existir, estiver deslistado ou sem dados na data.
     */
    private function fetchBinanceKlineClose(string $pair, Carbon $date): ?float
    {
        try {
            $response = Http::timeout(8)->get('https://api.binance.com/api/v3/klines', [
                'symbol'    => $pair,
                'interval'  => '1d',
                'startTime' => $date->copy()->startOfDay()->getTimestampMs(),
                'endTime'   => $date->copy()->endOfDay()->getTimestampMs(),
                'limit'     => 1,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (is_array($data) && count($data) > 0 && isset($data[0][4])) {
                    return (float) $data[0][4]; // índice 4 = preço de fechamento
                }
            }
        } catch (\Exception $e) {
            Log::debug("[Binance] Exceção ao buscar {$pair}: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Fallback via CoinGecko Demo API (gratuito, últimos 365 dias).
     *
     * Requer chave demo configurada em COINGECKO_API_KEY no .env.
     * Sem chave, a API retorna 429 (rate limit). Com chave demo gratuita:
     *   - 10.000 requisições/mês
     *   - Histórico limitado a 365 dias
     *
     * Mapeamento de símbolos Binance → ID CoinGecko para moedas conhecidas.
     * Para símbolos não mapeados, tenta o símbolo em minúsculas como ID.
     */
    private function getCoinGeckoHistoricalPrice(string $symbol, Carbon $date): ?float
    {
        $apiKey = config('services.coingecko.api_key', env('COINGECKO_API_KEY'));
        if (!$apiKey) {
            Log::debug("[CoinGecko] COINGECKO_API_KEY não configurada. Pulando fallback.");
            return null;
        }

        // Mapeamento símbolo Binance → ID CoinGecko
        // Adicionar aqui moedas que tenham ID diferente do símbolo
        $idMap = [
            'BTC'   => 'bitcoin',
            'ETH'   => 'ethereum',
            'BNB'   => 'binancecoin',
            'SOL'   => 'solana',
            'ADA'   => 'cardano',
            'DOT'   => 'polkadot',
            'LUNA'  => 'terra-luna',        // LUNA clássica (colapsou mai/2022)
            'LUNC'  => 'terra-luna',        // LUNA Classic (pós-colapso)
            'LUNAC' => 'terra-luna',
            'FTT'   => 'ftx-token',
            'ATOM'  => 'cosmos',
            'AVAX'  => 'avalanche-2',
            'MATIC' => 'matic-network',
            'SHIB'  => 'shiba-inu',
            'DOGE'  => 'dogecoin',
            'XRP'   => 'ripple',
            'LTC'   => 'litecoin',
            'LINK'  => 'chainlink',
            'UNI'   => 'uniswap',
            'AAVE'  => 'aave',
            'ALGO'  => 'algorand',
            'VET'   => 'vechain',
            'XLM'   => 'stellar',
            'TRX'   => 'tron',
            'ETC'   => 'ethereum-classic',
            'FIL'   => 'filecoin',
            'NEAR'  => 'near',
            'ICP'   => 'internet-computer',
            'APT'   => 'aptos',
            'ARB'   => 'arbitrum',
            'OP'    => 'optimism',
        ];

        $coinId  = $idMap[$symbol] ?? strtolower($symbol);
        // CoinGecko usa DD-MM-YYYY
        $cgDate  = $date->format('d-m-Y');

        Log::info("[CoinGecko] Buscando {$symbol} (id={$coinId}) em {$cgDate}...");

        try {
            $response = Http::timeout(10)
                ->withHeaders(['x-cg-demo-api-key' => $apiKey])
                ->get("https://api.coingecko.com/api/v3/coins/{$coinId}/history", [
                    'date'         => $cgDate,
                    'localization' => 'false',
                ]);

            if ($response->successful()) {
                $priceUsd = data_get($response->json(), 'market_data.current_price.usd');
                if ($priceUsd !== null && $priceUsd > 0) {
                    Log::info("[CoinGecko] {$symbol} em {$cgDate}: USD {$priceUsd}");
                    return (float) $priceUsd;
                }
                Log::warning("[CoinGecko] Sem market_data para {$coinId} em {$cgDate}.");
            } elseif ($response->status() === 429) {
                Log::warning('[CoinGecko] Rate limit atingido (429). Verifique a cota da chave demo.');
            } else {
                Log::warning("[CoinGecko] HTTP {$response->status()} para {$coinId} em {$cgDate}.");
            }
        } catch (\Exception $e) {
            Log::error("[CoinGecko] Exceção para {$coinId}: " . $e->getMessage());
        }

        return null;
    }
}
