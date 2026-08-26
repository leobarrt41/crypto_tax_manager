<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use App\Models\CryptoAsset;
use App\Models\TradingPair;
use App\Services\CryptoPriceService;
use CryptoAssetPrice;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Carbon\Carbon;

class CryptoAssetController extends Controller
{
    /**
     * Lista de criptoativos com filtros e paginação
     */
    public function index(Request $request)
    {
        try {
            $query = CryptoAsset::query();

            // Filtros
            if ($request->filled('search')) {
                $search = $request->get('search');
                $query->where(function ($q) use ($search) {
                    $q->where('symbol', 'like', "%{$search}%")
                      ->orWhere('name', 'like', "%{$search}%");
                });
            }

            if ($request->filled('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            if ($request->filled('is_stablecoin')) {
                $query->where('is_stablecoin', $request->boolean('is_stablecoin'));
            }

            if ($request->filled('blockchain')) {
                $query->where('blockchain', $request->get('blockchain'));
            }

            // Ordenação
            $sortBy = $request->get('sort_by', 'market_cap');
            $sortDirection = $request->get('sort_direction', 'desc');
            
            $allowedSorts = ['symbol', 'name', 'current_price_brl', 'price_change_24h', 'market_cap', 'volume_24h'];
            if (in_array($sortBy, $allowedSorts)) {
                $query->orderBy($sortBy, $sortDirection);
            }

            // Paginação
            $perPage = min($request->get('per_page', 20), 100);
            $cryptoAssets = $query->paginate($perPage);

            return Inertia::render('CryptoAssets/Index', [
                'cryptoAssets' => $cryptoAssets,
                'filters' => $request->only(['search', 'is_active', 'is_stablecoin', 'blockchain']),
                'sort' => ['sort_by' => $sortBy, 'sort_direction' => $sortDirection],
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao listar criptoativos: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Erro ao carregar lista de criptoativos.']);
        }
    }

    /**
     * Exibe detalhes de um criptoativo específico
     */
    public function show(CryptoAsset $cryptoAsset)
    {
        try {
            $cryptoAsset->load(['fromTransactions', 'toTransactions', 'walletBalances']);

            return Inertia::render('CryptoAssets/Show', [
                'cryptoAsset' => $cryptoAsset,
                'priceHistory' => $this->getPriceHistory($cryptoAsset),
                'marketStats' => $this->getMarketStats($cryptoAsset),
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao exibir criptoativo: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Erro ao carregar detalhes do criptoativo.']);
        }
    }

    /**
     * Cria um novo criptoativo
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'symbol' => 'required|string|max:20|unique:crypto_assets,symbol',
            'name' => 'nullable|string|max:255',
            'contract_address' => 'nullable|string|max:255|unique:crypto_assets,contract_address',
            'blockchain' => 'nullable|string|max:50',
            'website' => 'nullable|url',
            'description' => 'nullable|string|max:1000',
            'is_stablecoin' => 'boolean',
            'is_defi' => 'boolean',
            'is_nft' => 'boolean',
            'listed_at' => 'nullable|date',
            'delisted_at' => 'nullable|date|after_or_equal:listed_at',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        try {
            $cryptoAsset = CryptoAsset::create($request->validated());

            // Tentar buscar dados de mercado automaticamente
            $this->updateAssetMarketData($cryptoAsset);

            return redirect()->route('crypto-assets.show', $cryptoAsset)
                           ->with('success', 'Criptoativo criado com sucesso!');

        } catch (\Exception $e) {
            Log::error('Erro ao criar criptoativo: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Erro ao criar criptoativo.'])->withInput();
        }
    }

    /**
     * Atualiza um criptoativo existente
     */
    public function update(Request $request, CryptoAsset $cryptoAsset)
    {
        $validator = Validator::make($request->all(), [
            'symbol' => 'required|string|max:20|unique:crypto_assets,symbol,' . $cryptoAsset->id,
            'name' => 'nullable|string|max:255',
            'contract_address' => 'nullable|string|max:255|unique:crypto_assets,contract_address,' . $cryptoAsset->id,
            'blockchain' => 'nullable|string|max:50',
            'website' => 'nullable|url',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
            'is_stablecoin' => 'boolean',
            'is_defi' => 'boolean',
            'is_nft' => 'boolean',
            'listed_at' => 'nullable|date',
            'delisted_at' => 'nullable|date|after_or_equal:listed_at',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        try {
            $cryptoAsset->update($request->validated());

            return back()->with('success', 'Criptoativo atualizado com sucesso!');

        } catch (\Exception $e) {
            Log::error('Erro ao atualizar criptoativo: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Erro ao atualizar criptoativo.']);
        }
    }

    /**
     * Remove um criptoativo (soft delete)
     */
    public function destroy(CryptoAsset $cryptoAsset)
    {
        try {
            // Verificar se há transações relacionadas
            $hasTransactions = $cryptoAsset->fromTransactions()->exists() || 
                             $cryptoAsset->toTransactions()->exists();

            if ($hasTransactions) {
                return back()->withErrors(['error' => 'Não é possível excluir um criptoativo que possui transações relacionadas.']);
            }

            $cryptoAsset->update(['is_active' => false]);

            return redirect()->route('crypto-assets.index')
                           ->with('success', 'Criptoativo desativado com sucesso!');

        } catch (\Exception $e) {
            Log::error('Erro ao desativar criptoativo: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Erro ao desativar criptoativo.']);
        }
    }

    /**
     * Importa moedas de uma exchange específica
     */
  public function importCryptoAssets(Request $request)
{
    Log::info('Iniciando importação de criptoativos', ['request' => $request->all()]);


 $exchange = $request->route('exchange');

$validator = Validator::make(
    ['exchange' => $exchange] + $request->all(),
    [
        'exchange' => 'required|string|in:binance,binance_smart_chain,coinbase,kraken',
        'limit' => 'nullable|integer|min:1|max:5000',
    ]
);


    if ($validator->fails()) {
        Log::warning('Validação falhou na importação de criptoativos', ['errors' => $validator->errors()]);
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

    try {
        $exchange = $request->route('exchange');
        $limit = $request->get('limit', 100);

        Log::info("Buscando dados da exchange: $exchange", ['limit' => $limit]);

        $apiData = $this->fetchExchangeData($exchange, $limit);
        
        if (!$apiData) {
            Log::error("Nenhum dado retornado da API para a exchange: $exchange");
            return response()->json(['success' => false, 'message' => 'Erro ao buscar dados da API.']);
        }

        if ($exchange === 'binance') {
            [$importedPairs, $updatedPairs] = $this->importBinanceTradingPairs($apiData);

            Log::info('Importação de pares Binance concluída com sucesso', [
                'exchange' => $exchange,
                'imported_pairs' => $importedPairs,
                'updated_pairs' => $updatedPairs,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Importação concluída: {$importedPairs} pares novos, {$updatedPairs} pares atualizados."
            ]);
        }

        $imported = 0;
        $updated = 0;
        $parsedAssets = $this->parseCryptoAssets($exchange, $apiData);
        Log::info("Criptoativos extraídos da resposta da API", ['total' => count($parsedAssets)]);

        foreach ($parsedAssets as $crypto) {
            $asset = CryptoAsset::updateOrCreate(['symbol' => $crypto['symbol']], $crypto);
            if ($asset->wasRecentlyCreated) {
                $imported++;
            } else {
                $updated++;
            }
        }

        Log::info('Importação concluída com sucesso', [
            'exchange' => $exchange,
            'imported' => $imported,
            'updated' => $updated,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Importação concluída: {$imported} novos ativos, {$updated} atualizados."
        ]);

    } catch (\Exception $e) {
        Log::error('Erro na importação de moedas', [
            'exchange' => $exchange ?? 'unknown',
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}
    /**
     * Atualiza preços de todos os ativos ativos
     */
    public function updatePrices(Request $request)
    {
        try {
            $symbols = $request->get('symbols', []);
            
            if (empty($symbols)) {
                $symbols = CryptoAsset::active()
                    ->pluck('symbol')
                    ->take(100) // Limitar para evitar timeout
                    ->toArray();
            }

            $updated = $this->updateAssetsPrices($symbols);

            return response()->json([
                'success' => true,
                'message' => "Preços atualizados para {$updated} ativos.",
                'updated_count' => $updated,
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao atualizar preços: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erro ao atualizar preços.'], 500);
        }
    }

    /**
     * API endpoint para buscar ativos
     */
    public function apiSearch(Request $request)
    {
        try {
            $search = $request->get('q', '');
            $limit = min($request->get('limit', 10), 50);

            $assets = CryptoAsset::active()
                ->where(function ($query) use ($search) {
                    $query->where('symbol', 'like', "%{$search}%")
                          ->orWhere('name', 'like', "%{$search}%");
                })
                ->orderBy('market_cap', 'desc')
                ->limit($limit)
                ->get(['id', 'symbol', 'name', 'logo_url', 'current_price_brl']);

            return response()->json($assets);

        } catch (\Exception $e) {
            Log::error('Erro na busca de ativos: ' . $e->getMessage());
            return response()->json(['error' => 'Erro na busca'], 500);
        }
    }

    /**
     * Métodos privados auxiliares
     */

    private function fetchExchangeData(string $exchange, int $limit = 100): ?array
    {
        if ($exchange === 'binance') {
            return $this->fetchBinanceTradingCatalogSymbols();
        }

        $apiUrl = match($exchange) {
            'binance' => 'https://api.binance.com/api/v3/ticker/price',
            'binance_smart_chain' => 'https://api.bscscan.com/api?module=token&action=listtokens&apikey=' . env('BSCSCAN_API_KEY'),
            'coinbase' => 'https://api.pro.coinbase.com/products',
            'kraken' => 'https://api.kraken.com/0/public/AssetPairs',
            default => null,
        };

        if (!$apiUrl) {
            return null;
        }

        $response = Http::timeout(30)->get($apiUrl);

        if (!$response->successful()) {
            Log::error("Erro ao buscar dados da API {$exchange}", ['response' => $response->body()]);
            return null;
        }

        return $response->json();
    }

   private function parseCryptoAssets(string $exchange, array $data): array
{
    $result = [];

    switch ($exchange) {
        case 'binance':
            if (isset($data[0]['asset'])) {
                foreach ($data as $balance) {
                    $symbol = strtoupper(trim((string)($balance['asset'] ?? '')));
                    if (!$this->isValidBinanceAssetSymbol($symbol)) {
                        continue;
                    }

                    $result[$symbol] = [
                        'symbol' => $symbol,
                        'name' => $symbol,
                        'blockchain' => 'Binance',
                    ];
                }
                break;
            }

            foreach ($data as $crypto) {
                if (empty($crypto['symbol'])) {
                    continue;
                }

                $assets = $this->splitSymbol($crypto['symbol']);

                if (count($assets) === 2) {
                    [$base, $quote] = $assets;

                    foreach ([$base, $quote] as $symbol) {
                        if (!$this->isValidBinanceAssetSymbol($symbol)) {
                            continue;
                        }

                        if (!isset($result[$symbol])) {
                            $result[$symbol] = [
                                'symbol' => $symbol,
                                'name' => $symbol,
                                'blockchain' => 'Binance',
                                'current_price_usd' => in_array($symbol, ['USDT', 'USD', 'BTC', 'ETH']) ? $crypto['price'] : null,
                            ];
                        }
                    }
                } else {
                    $symbol = $assets[0];
                    if ($this->isValidBinanceAssetSymbol($symbol)) {
                        if (!isset($result[$symbol])) {
                            $result[$symbol] = [
                                'symbol' => $symbol,
                                'name' => $symbol,
                                'blockchain' => 'Binance',
                                'current_price_usd' => $crypto['price'] ?? null,
                            ];
                        }
                    }
                }
            }
            break;

            case 'binance_smart_chain':
                if (isset($data['result'])) {
                    foreach ($data['result'] as $crypto) {
                        $result[] = [
                            'symbol' => $crypto['symbol'],
                            'name' => $crypto['tokenName'],
                            'contract_address' => $crypto['contractAddress'],
                            'blockchain' => 'BSC',
                        ];
                    }
                }
                break;

            case 'coinbase':
                foreach ($data as $crypto) {
                    $result[] = [
                        'symbol' => $crypto['id'],
                        'name' => $crypto['display_name'],
                        'blockchain' => 'Coinbase',
                    ];
                }
                break;

            case 'kraken':
                if (isset($data['result'])) {
                    foreach ($data['result'] as $key => $crypto) {
                        $result[] = [
                            'symbol' => $key,
                            'name' => $crypto['altname'],
                            'blockchain' => 'Kraken',
                        ];
                    }
                }
                break;
        }

         return array_values($result); // para evitar index string
    }

    /**
     * Atualiza cotações atuais usando somente a Binance. Valores em BRL são
     * obtidos do par direto em BRL ou da conversão USD/BRL pela PTAX do BCB.
     */
    private function updateAssetsPrices(array $symbols): int
    {
        if (empty($symbols)) {
            return 0;
        }

        try {
            $response = Http::timeout(30)->get('https://api.binance.com/api/v3/ticker/24hr');
            if (!$response->successful()) {
                Log::warning('Falha ao obter ticker de 24 horas da Binance.');
                return 0;
            }

            $tickers = collect($response->json())->keyBy('symbol');
            $ptax = app(CryptoPriceService::class)->getUsdToBrlRate(Carbon::now('America/Sao_Paulo'));
            $stablecoins = ['USDT', 'USDC', 'BUSD', 'DAI', 'TUSD', 'FDUSD'];
            $usdQuotes = ['USDT', 'BUSD', 'FDUSD', 'USDC'];
            $updated = 0;

            foreach (array_unique(array_map(fn ($symbol) => strtoupper(trim((string) $symbol)), $symbols)) as $symbol) {
                $asset = CryptoAsset::where('symbol', $symbol)->first();
                if (!$asset) {
                    continue;
                }

                $tickerUsd = null;
                foreach ($usdQuotes as $quote) {
                    $candidate = $tickers->get("{$symbol}{$quote}");
                    if ($candidate && (float) ($candidate['lastPrice'] ?? 0) > 0) {
                        $tickerUsd = $candidate;
                        break;
                    }
                }

                $tickerBrl = $tickers->get("{$symbol}BRL");
                $priceUsd = in_array($symbol, $stablecoins, true)
                    ? 1.0
                    : (float) ($tickerUsd['lastPrice'] ?? 0);
                $priceBrl = (float) ($tickerBrl['lastPrice'] ?? 0);

                if ($priceBrl <= 0 && $priceUsd > 0 && $ptax !== null) {
                    $priceBrl = round($priceUsd * $ptax, 10);
                }

                if ($priceUsd <= 0 && $priceBrl <= 0) {
                    continue;
                }

                $asset->updatePriceData([
                    'current_price_usd' => $priceUsd > 0 ? $priceUsd : null,
                    'current_price_brl' => $priceBrl > 0 ? $priceBrl : null,
                    'price_change_24h' => $tickerUsd['priceChangePercent'] ?? $tickerBrl['priceChangePercent'] ?? null,
                ]);
                $updated++;
            }

            return $updated;
        } catch (\Exception $e) {
            Log::warning('Erro ao atualizar preços pela Binance: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * No cadastro manual, tenta preencher somente os preços disponíveis na Binance.
     * Metadados como logo e descrição permanecem sob controle do usuário.
     */
    private function updateAssetMarketData(CryptoAsset $asset): void
    {
        $this->updateAssetsPrices([$asset->symbol]);
    }

    private function getPriceHistory(CryptoAsset $cryptoAsset): array
    {
        // Implementar busca de histórico de preços
        // Por enquanto, retornar dados mock
        return [];
    }

    private function getMarketStats(CryptoAsset $cryptoAsset): array
    {
        return [
            'price_updated' => $cryptoAsset->isPriceUpdated(),
            'market_data_updated' => $cryptoAsset->isMarketDataUpdated(),
            'formatted_price_brl' => $cryptoAsset->getFormattedPriceBrl(),
            'formatted_price_usd' => $cryptoAsset->getFormattedPriceUsd(),
            'formatted_market_cap' => $cryptoAsset->getFormattedMarketCap(),
            'formatted_change_24h' => $cryptoAsset->getFormattedPriceChange('24h'),
            'is_positive_performance' => $cryptoAsset->isPositivePerformance('24h'),
        ];
    }


private function splitSymbol(string $symbol): array
{
    // Busca quote assets únicos da Binance com cache de 24h
    $quoteAssets = Cache::remember('binance_quote_assets', 60 * 24, function () {
        $response = Http::timeout(15)->get('https://api.binance.com/api/v3/exchangeInfo');

        if (!$response->successful()) {
            Log::warning('Falha ao buscar quote assets da Binance, usando fallback.');
            return ['USDT', 'BUSD', 'BTC', 'ETH', 'BNB', 'TRY', 'EUR', 'BRL', 'USD', 'TUSD', 'FDUSD', 'DAI'];
        }

        $symbols = $response->json('symbols');

        // Extrai todos os quoteAssets únicos e os ordena por tamanho decrescente (evita conflitos como USD e USDT)
        return collect($symbols)
            ->pluck('quoteAsset')
            ->unique()
            ->sortByDesc(fn($asset) => strlen($asset))
            ->values()
            ->toArray();
    });

    foreach ($quoteAssets as $quote) {
        if (str_ends_with($symbol, $quote)) {
            $base = substr($symbol, 0, -strlen($quote));
            return [$base, $quote];
        }
    }

    return [$symbol];
}

public function all()
{
    return TradingPair::query()
        ->where('status', 'TRADING')
        ->orderBy('symbol')
        ->get(['id', 'symbol', 'base_asset', 'quote_asset', 'status']);
}

private function isValidBinanceAssetSymbol(?string $symbol): bool
{
    if (!$symbol) {
        return false;
    }

    $symbol = strtoupper(trim($symbol));

    // Aceita símbolos Binance padrão (inclui alfanuméricos como 1INCH, 1000PEPE, FDUSD etc.)
    return (bool) preg_match('/^[A-Z0-9]{2,20}$/', $symbol);
}

private function fetchBinanceTradingCatalogSymbols(): array
{
    try {
        $response = Http::timeout(30)->get('https://api.binance.com/api/v3/exchangeInfo');

        if (!$response->successful()) {
            Log::warning('Importação Binance catálogo: falha ao consultar /exchangeInfo.', [
                'status' => $response->status(),
            ]);
            return [];
        }

        $symbols = collect($response->json('symbols', []))
            ->filter(fn($item) => ($item['status'] ?? null) === 'TRADING')
            ->values()
            ->all();

        Log::info('Importação Binance catálogo: pares vigentes recuperados.', [
            'total' => count($symbols),
        ]);

        return $symbols;
    } catch (\Exception $e) {
        Log::error('Importação Binance catálogo: erro ao buscar exchangeInfo.', [
            'error' => $e->getMessage(),
        ]);
        return [];
    }
}

private function importBinanceTradingPairs(array $symbols): array
{
    $imported = 0;
    $updated = 0;

    foreach ($symbols as $symbolInfo) {
        $symbol = strtoupper(trim((string)($symbolInfo['symbol'] ?? '')));
        $baseAsset = strtoupper(trim((string)($symbolInfo['baseAsset'] ?? '')));
        $quoteAsset = strtoupper(trim((string)($symbolInfo['quoteAsset'] ?? '')));

        if (!$symbol || !$baseAsset || !$quoteAsset) {
            continue;
        }

        $pair = TradingPair::updateOrCreate(
            ['symbol' => $symbol],
            [
                'base_asset' => $baseAsset,
                'quote_asset' => $quoteAsset,
                'status' => $symbolInfo['status'] ?? 'TRADING',
                'is_spot_trading_allowed' => (bool)($symbolInfo['isSpotTradingAllowed'] ?? false),
                'is_margin_trading_allowed' => (bool)($symbolInfo['isMarginTradingAllowed'] ?? false),
                'filters' => $symbolInfo['filters'] ?? null,
                'listed_at' => TradingPair::where('symbol', $symbol)->value('listed_at') ?? now(),
                'delisted_at' => null,
            ]
        );

        if ($pair->wasRecentlyCreated) {
            $imported++;
        } else {
            $updated++;
        }
    }

    return [$imported, $updated];
}



}
