<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use App\Models\CryptoAsset;
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

 $exchange = $request->route('exchange');

$validator = Validator::make(
    ['exchange' => $exchange] + $request->all(),
    [
        'exchange' => 'required|string|in:binance,binance_smart_chain,coinbase,kraken,coingecko',
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

        $imported = 0;
        $updated = 0;

        $parsedAssets = $this->parseCryptoAssets($exchange, $apiData);
        Log::info("Criptoativos extraídos da resposta da API", ['total' => count($parsedAssets)]);

        foreach ($parsedAssets as $crypto) {
            $asset = CryptoAsset::updateOrCreate(
                ['symbol' => $crypto['symbol']],
                $crypto
            );

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
        $apiUrl = match($exchange) {
            'binance' => 'https://api.binance.com/api/v3/ticker/price',
            'binance_smart_chain' => 'https://api.bscscan.com/api?module=token&action=listtokens&apikey=' . env('BSCSCAN_API_KEY'),
            'coinbase' => 'https://api.pro.coinbase.com/products',
            'kraken' => 'https://api.kraken.com/0/public/AssetPairs',
            'coingecko' => "https://api.coingecko.com/api/v3/coins/markets?vs_currency=usd&order=market_cap_desc&per_page={$limit}&page=1",
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
            foreach ($data as $crypto) {
                $assets = $this->splitSymbol($crypto['symbol']);

                if (count($assets) === 2) {
                    [$base, $quote] = $assets;

                    // 🔒 Ignorar tokens suspeitos (como 1000XXX, XXXUSDC, etc.)
                    foreach ([$base, $quote] as $symbol) {
                        if (strlen($symbol) > 10 || preg_match('/(USDC|FD)$/i', $symbol)) {
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
                    if (strlen($symbol) <= 10 && !preg_match('/(USDC|FD)$/i', $symbol)) {
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

            case 'coingecko':
                foreach ($data as $crypto) {
                    $result[] = [
                        'symbol' => strtoupper($crypto['symbol']),
                        'name' => $crypto['name'],
                        'current_price_usd' => $crypto['current_price'],
                        'price_change_24h' => $crypto['price_change_percentage_24h'],
                        'market_cap' => $crypto['market_cap'],
                        'volume_24h' => $crypto['total_volume'],
                        'logo_url' => $crypto['image'],
                    ];
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

    private function updateAssetsPrices(array $symbols): int
    {
        if (empty($symbols)) {
            return 0;
        }

        // Usar CoinGecko para atualizar preços
        $symbolsString = implode(',', array_map('strtolower', $symbols));
        $url = "https://api.coingecko.com/api/v3/simple/price?ids={$symbolsString}&vs_currencies=usd,brl&include_24hr_change=true";

        $response = Http::timeout(30)->get($url);

        if (!$response->successful()) {
            return 0;
        }

        $priceData = $response->json();
        $updated = 0;

        foreach ($priceData as $coinId => $prices) {
            $asset = CryptoAsset::where('symbol', strtoupper($coinId))->first();
            
            if ($asset) {
                $asset->updatePriceData([
                    'current_price_usd' => $prices['usd'] ?? null,
                    'current_price_brl' => $prices['brl'] ?? null,
                    'price_change_24h' => $prices['usd_24h_change'] ?? null,
                ]);
                $updated++;
            }
        }

        return $updated;
    }

    private function updateAssetMarketData(CryptoAsset $asset): void
    {
        try {
            $url = "https://api.coingecko.com/api/v3/coins/{$asset->symbol}";
            $response = Http::timeout(15)->get($url);

            if ($response->successful()) {
                $data = $response->json();
                
                $asset->updateMarketData([
                    'current_price_usd' => $data['market_data']['current_price']['usd'] ?? null,
                    'current_price_brl' => $data['market_data']['current_price']['brl'] ?? null,
                    'price_change_24h' => $data['market_data']['price_change_percentage_24h'] ?? null,
                    'market_cap' => $data['market_data']['market_cap']['usd'] ?? null,
                    'volume_24h' => $data['market_data']['total_volume']['usd'] ?? null,
                    'logo_url' => $data['image']['large'] ?? null,
                    'description' => $data['description']['en'] ?? null,
                    'website' => $data['links']['homepage'][0] ?? null,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning("Erro ao atualizar dados de mercado para {$asset->symbol}: " . $e->getMessage());
        }
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
    return CryptoAsset::orderBy('name')->get();
}



}
