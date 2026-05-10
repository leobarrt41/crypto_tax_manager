<?php

namespace App\Services;

use App\Models\CryptoAssetPrice;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CryptoPriceService
{
    /**
     * Lista de stablecoins que sempre valem $1 USD
     */
    private const STABLECOINS = ['USDT', 'USDC', 'BUSD', 'DAI', 'TUSD', 'FDUSD'];

    /**
     * Cache em memória para cotações USD/BRL já consultadas
     */
    private array $usdBrlCache = [];

    /**
     * Obtém ou cria o preço de um ativo em uma data específica.
     * Retorna um objeto com price_usd e price_brl.
     */
    public function getOrCreatePrice(string $symbol, Carbon $date): object
    {
        $dateString = $date->toDateString();
        $symbol = strtoupper($symbol);

        // 1. Verificar se já existe no banco de dados
        $existing = CryptoAssetPrice::where('symbol', $symbol)
            ->whereDate('recorded_at', $dateString)
            ->first();

        if ($existing && $existing->price_brl !== null && $existing->price_usdt !== null) {
            Log::debug("[Preço] Encontrado no banco para {$symbol} em {$dateString}.");
            return (object)[
                'price_usd' => (float)$existing->price_usdt,
                'price_brl' => (float)$existing->price_brl
            ];
        }

        // 2. TRATAMENTO ESPECIAL: Stablecoins sempre valem $1
        if (in_array($symbol, self::STABLECOINS)) {
            Log::info("[Preço] {$symbol} é stablecoin. Valor USD = 1.0");
            $priceUsd = 1.0;
            $priceBrl = $this->getUsdToBrlRate($date);

            if ($priceBrl) {
                CryptoAssetPrice::updateOrCreate(
                    ['symbol' => $symbol, 'recorded_at' => $dateString],
                    ['price_usdt' => $priceUsd, 'price_brl' => $priceBrl]
                );
                Log::info("[Preço] Stablecoin {$symbol} salva: \$1 = R\$ {$priceBrl}");
                return (object)['price_usd' => $priceUsd, 'price_brl' => $priceBrl];
            } else {
                Log::error("[Preço] Falha ao obter cotação USD/BRL para {$dateString}");
                return (object)['price_usd' => 1.0, 'price_brl' => 0];
            }
        }

        // 3. Para outras criptos, buscar na Binance (klines)
        Log::info("[Binance] Buscando preço de {$symbol} em {$dateString}...");
        $priceUsd = $this->getBinanceHistoricalPrice($symbol, $date);

        if ($priceUsd) {
            // Calcular o preço em BRL
            $priceBrl = $this->getUsdToBrlRate($date);
            if ($priceBrl) {
                $priceBrl = $priceUsd * $priceBrl;
            } else {
                $priceBrl = 0;
            }

            // Salvar no banco
            CryptoAssetPrice::updateOrCreate(
                ['symbol' => $symbol, 'recorded_at' => $dateString],
                ['price_usdt' => $priceUsd, 'price_brl' => $priceBrl]
            );
            Log::info("[Preço] {$symbol} salvo via Binance: \${$priceUsd} = R\$ {$priceBrl}");
            return (object)['price_usd' => (float)$priceUsd, 'price_brl' => (float)$priceBrl];
        }

        // 4. Se falhou, retornar valores zero
        Log::error("[Preço] Impossível obter preço para {$symbol} em {$dateString}");
        return (object)['price_usd' => 0, 'price_brl' => 0];
    }

    /**
     * Busca o preço histórico de um ativo na Binance usando klines.
     * Retorna o preço de fechamento do dia em USD.
     */
    private function getBinanceHistoricalPrice(string $symbol, Carbon $date): ?float
    {
        $pair = "{$symbol}USDT";
        
        try {
            $response = Http::timeout(10)->get("https://api.binance.com/api/v3/klines", [
                'symbol' => $pair,
                'interval' => '1d',
                'startTime' => $date->startOfDay()->getTimestampMs(),
                'endTime' => $date->endOfDay()->getTimestampMs(),
                'limit' => 1
            ]);

            if ($response->successful() && count($response->json())) {
                $kline = $response->json()[0];
                $closePrice = (float)$kline[4]; // Preço de fechamento
                Log::info("[Binance] Preço de {$pair} em {$date->toDateString()}: \${$closePrice}");
                return $closePrice;
            } else {
                Log::warning("[Binance] Nenhum kline encontrado para {$pair} em {$date->toDateString()}");
                return null;
            }
        } catch (\Exception $e) {
            Log::error("[Binance] Erro ao buscar kline para {$pair}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtém a taxa de câmbio USD para BRL em uma data específica.
     * Usa a API Frankfurter (gratuita, sem limites, sem chave).
     */
    private function getUsdToBrlRate(Carbon $date): ?float
    {
        $dateString = $date->toDateString();

        // Verificar cache em memória
        if (isset($this->usdBrlCache[$dateString])) {
            Log::debug("[Câmbio] USD/BRL para {$dateString} encontrado no cache.");
            return $this->usdBrlCache[$dateString];
        }

        // Chamar API Frankfurter
        Log::info("[Frankfurter] Buscando taxa USD→BRL para {$dateString}...");
        
        try {
            $response = Http::timeout(10)->get("https://api.frankfurter.app/{$dateString}", [
                'from' => 'USD',
                'to' => 'BRL'
            ]);

            if ($response->successful()) {
                $rate = data_get($response->json(), 'rates.BRL');
                if ($rate) {
                    $this->usdBrlCache[$dateString] = (float)$rate;
                    Log::info("[Câmbio] Taxa USD→BRL para {$dateString}: R\$ {$rate}");
                    return (float)$rate;
                }
            } else {
                Log::warning("[Frankfurter] Falha ao obter taxa para {$dateString}", [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
            }
        } catch (\Exception $e) {
            Log::error("[Frankfurter] Exceção ao buscar taxa para {$dateString}", [
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }
}