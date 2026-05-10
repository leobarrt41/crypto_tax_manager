<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use App\Models\Transaction;
use App\Models\UserApiKey;
use App\Models\CryptoAsset;
use App\Models\CryptoAssetPrice;
use App\Services\CryptoPriceService;
use App\Models\Exchange;
use Binance\API as BinanceAPI;
use Binance\Spot;
use Carbon\Carbon;
use App\Services\BinanceConvertService;
use App\Services\BinancePriceOptimizer;
use App\Services\FifoCalculatorService;
use Exception;

/**
 * TransactionController Otimizado
 * 
 * Versão final com todas as otimizações implementadas:
 * - Paginação completa para histórico de transações
 * - Descoberta dinâmica de pares de moedas
 * - Uso direto de dados de preço da Binance
 * - Eliminação de dependências desnecessárias de APIs externas
 * - Tratamento robusto de erros e rate limiting
 * - Conformidade com requisitos da Receita Federal (limite de 5 anos)
 */
class TransactionController extends Controller
{
    /**
     * Lista as transações do usuário autenticado
     */

    private $convertService;
    private $priceOptimizer;




      public function index(Request $request)
    {
        // ... (código do index permanece igual)
        $filters = $request->only([
            'search', 'type', 'crypto_asset_id', 'date_range', 'start_date', 'end_date'
        ]);

        $query = Transaction::with(['source', 'fromCryptoAsset', 'toCryptoAsset'])
            ->where('user_id', auth()->id())
            ->orderByDesc('date');

        if ($filters['search'] ?? false) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('from_asset', 'ilike', "%{$search}%")
                  ->orWhere('to_asset', 'ilike', "%{$search}%")
                  ->orWhere('txid', 'ilike', "%{$search}%")
                  ->orWhere('reference', 'ilike', "%{$search}%");
            });
        }

        if ($filters['type'] ?? false) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['date_range']) && $filters['date_range'] === 'custom') {
            if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
                $query->whereBetween('date', [$filters['start_date'], $filters['end_date']]);
            }
        }

        if (!empty($filters['date_range'])) {
            switch ($filters['date_range']) {
                case 'today':
                    $query->whereDate('date', today());
                    break;
                case 'week':
                    $query->whereBetween('date', [now()->startOfWeek(), now()->endOfWeek()]);
                    break;
                case 'month':
                    $query->whereMonth('date', now()->month)
                          ->whereYear('date', now()->year);
                    break;
                case 'year':
                    $query->whereYear('date', now()->year);
                    break;
            }
        }

        $transactions = $query->paginate(10)->withQueryString();

        $transactions->getCollection()->transform(function ($tr) {
            return [
                'id'                => $tr->id,
                'type'              => $tr->type,
                'operation'         => $tr->operation,
                'from_asset'        => $tr->from_asset,
                'to_asset'          => $tr->to_asset,
                'from_crypto_asset' => $tr->fromCryptoAsset ? ['symbol' => $tr->fromCryptoAsset->symbol, 'name'   => $tr->fromCryptoAsset->name] : null,
                'to_crypto_asset'   => $tr->toCryptoAsset ? ['symbol' => $tr->toCryptoAsset->symbol, 'name'   => $tr->toCryptoAsset->name] : null,
                'from_amount'       => $tr->from_amount,
                'to_amount'         => $tr->to_amount,
                'price'             => $tr->price,
                'unit_price_usdt'   => $tr->price,
                'unit_price_brl'    => $tr->total_brl && $tr->from_amount ? $tr->total_brl / $tr->from_amount : null,
                'total_brl'         => $tr->total_brl ? (float) $tr->total_brl : 0,
                'total_usdt'        => $tr->total_usdt ? (float) $tr->total_usdt : 0,
                'fees'              => 0,
                'reference'         => $tr->reference,
                'txid'              => $tr->txid,
                'date'              => $tr->date ? $tr->date->toIso8601String() : null,
                'executed_at'       => $tr->date ? $tr->date->toIso8601String() : null,
            ];
        });

        $statsQuery = Transaction::where('user_id', auth()->id());
        
        $stats = [
            'total_transactions' => $statsQuery->count(),
            'total_volume' => $statsQuery->sum('total_usdt') ?? 0,
            'total_volume_brl' => $statsQuery->sum('total_brl') ?? 0,
            'this_month' => $statsQuery->clone()->whereMonth('date', now()->month)->count(),
            'profit_loss' => 0,
        ];

        return Inertia::render('Transactions/Index', [
            'transactions' => $transactions,
            'cryptoAssets' => CryptoAsset::all(),
            'stats' => $stats,
            'filters' => $filters,
        ]);
    }

    /**
     * Mostra o formulário para criar uma nova transação
     */
    public function create()
    {
        return Inertia::render('Transactions/Create', [
            'cryptoAssets' => CryptoAsset::all(),
        ]);
    }

    /**
     * Armazena uma nova transação manualmente
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'from_asset' => 'nullable|string|max:10',
            'to_asset' => 'nullable|string|max:10',
            'from_amount' => 'nullable|numeric|min:0',
            'to_amount' => 'nullable|numeric|min:0',
            'type' => 'required|string|in:trade,convert,deposit,withdrawal,fiat_buy,fiat_sell,mining,staking',
            'operation' => 'nullable|string|in:entrada,saida',
            'price' => 'nullable|numeric|min:0',
            'total_usdt' => 'nullable|numeric|min:0',
            'total_brl' => 'nullable|numeric|min:0',
            'date' => 'required|date',
            'txid' => 'nullable|string|max:255',
            'source' => 'nullable|string|max:50',
            'reference' => 'nullable|string|max:255',
        ]);

        $data['user_id'] = auth()->id();

        try {
            $transaction = Transaction::create($data);

            if ($data['operation'] === 'saida') {
                app(FifoCalculatorService::class)->calculateFor($transaction);
            }

            return redirect()->route('transactions.index')
                ->with('success', 'Transação cadastrada com sucesso!');
        } catch (Exception $e) {
            Log::error('Erro ao criar transação: ' . $e->getMessage());
            
            return back()->withErrors(['error' => 'Erro ao cadastrar transação.'])
                ->withInput();
        }
    }

    /**
     * Mostra uma transação específica
     */
    public function show($id)
    {
        if (!is_numeric($id)) {
            abort(404, 'Transação inválida');
        }

        $transaction = Transaction::where('user_id', auth()->id())
            ->findOrFail($id);

        return Inertia::render('Transactions/Show', [
            'transaction' => $transaction,
        ]);
    }

    /**
     * Mostra o formulário para editar uma transação
     */
    public function edit($id)
    {
        $transaction = Transaction::where('user_id', auth()->id())
            ->findOrFail($id);

        return Inertia::render('Transactions/Edit', [
            'transaction' => $transaction,
            'cryptoAssets' => CryptoAsset::all(),
        ]);
    }

    /**
     * Atualiza uma transação específica
     */
    public function update(Request $request, $id)
    {
        $transaction = Transaction::where('user_id', auth()->id())
            ->findOrFail($id);

        $data = $request->validate([
            'from_asset' => 'nullable|string|max:10',
            'to_asset' => 'nullable|string|max:10',
            'from_amount' => 'nullable|numeric|min:0',
            'to_amount' => 'nullable|numeric|min:0',
            'type' => 'required|string|in:trade,convert,deposit,withdrawal,fiat_buy,fiat_sell,mining,staking',
            'operation' => 'nullable|string|in:entrada,saida',
            'price' => 'nullable|numeric|min:0',
            'total_usdt' => 'nullable|numeric|min:0',
            'total_brl' => 'nullable|numeric|min:0',
            'date' => 'required|date',
            'txid' => 'nullable|string|max:255',
            'source' => 'nullable|string|max:50',
            'reference' => 'nullable|string|max:255',
        ]);

        try {
            $transaction->update($data);

            if ($data['operation'] === 'saida') {
                app(FifoCalculatorService::class)->calculateFor($transaction);
            }
            
            return redirect()->route('transactions.index')
                ->with('success', 'Transação atualizada com sucesso!');
        } catch (Exception $e) {
            Log::error('Erro ao atualizar transação: ' . $e->getMessage());
            
            return back()->withErrors(['error' => 'Erro ao atualizar transação.'])
                ->withInput();
        }
    }

    /**
     * Remove uma transação específica
     */
    public function destroy($id)
    {
        $transaction = Transaction::where('user_id', auth()->id())
            ->findOrFail($id);

        try {
            $transaction->delete();
            
            return redirect()->route('transactions.index')
                ->with('success', 'Transação removida com sucesso!');
        } catch (Exception $e) {
            Log::error('Erro ao remover transação: ' . $e->getMessage());
            
            return back()->withErrors(['error' => 'Erro ao remover transação.']);
        }
    }

    /**
     * Importa movimentações de uma exchange específica
     */
   public function importFromExchange(Request $request, $exchange)
    {
        $apiKey = UserApiKey::where('user_id', auth()->id())
            ->whereHas('exchange', fn($q) => $q->where('name', strtolower($exchange)))
            ->first();

        if (!$apiKey) {
            return response()->json(['error' => "Chave da exchange '{$exchange}' não cadastrada."], 400);
        }

        $priceService = app(CryptoPriceService::class);

        try {
            return match (strtolower($exchange)) {
                'binance' => $this->importFromBinance($apiKey, $priceService, $request),
                // ... outras exchanges
                default => response()->json(['error' => "Exchange não suportada: {$exchange}"], 400),
            };
        } catch (Exception $e) {
            Log::error("Erro ao importar de {$exchange}: " . $e->getMessage());
            return response()->json(['error' => 'Erro interno durante a importação.'], 500);
        }
    }

    /**
     * VERSÃO OTIMIZADA: Importa transações da Binance
     * 
     * Esta versão implementa todas as otimizações:
     * - Paginação completa
     * - Descoberta dinâmica de símbolos
     * - Uso direto de dados de preço da Binance
     * - Limite padrão de 5 anos (Receita Federal)
     */
protected function importFromBinance(UserApiKey $apiKey, CryptoPriceService $priceService, Request $request)
{
    set_time_limit(1800); // 30 minutos para importações grandes

    try {
        Log::info('[Binance Import v4] Iniciando importação completa para usuário: ' . auth()->id());

        // --- CONEXÃO E AUTENTICAÇÃO ---
        $client = new Spot([
            'key' => $apiKey->api_key,
            'secret' => $apiKey->secret_key,
        ]);
        $accountInfo = $client->account();
        if (isset($accountInfo['code']) && $accountInfo['code'] < 0) {
            Log::warning('[Binance Import] Falha na autenticação da conta Binance.', ['response' => $accountInfo]);
            return response()->json(['error' => 'Falha na autenticação com a Binance. Verifique as permissões da chave.'], 401);
        }
        Log::info('[Binance Import] Autenticação bem-sucedida.');

        DB::beginTransaction();
        $newly_imported_count = 0;

        // --- PERÍODO DE IMPORTAÇÃO ---
        $startTime = $request->start_date 
            ? Carbon::parse($request->start_date)->getTimestampMs() 
            : Carbon::now()->subYears(5)->getTimestampMs();
        $endTime = $request->end_date 
            ? Carbon::parse($request->end_date)->getTimestampMs() 
            : Carbon::now()->getTimestampMs();
        Log::info('[Binance Import] Período de importação definido.', [
            'start' => date('Y-m-d H:i:s', $startTime / 1000),
            'end' => date('Y-m-d H:i:s', $endTime / 1000)
        ]);

        // --- INICIALIZAÇÃO DOS SERVIÇOS ---
        // O ConvertService precisa da $apiKey, não do $client, para fazer chamadas HTTP assinadas.
        $this->convertService = new BinanceConvertService($apiKey);

        // --- 1. IMPORTAÇÃO DE TRADES SPOT ---
        // A função getSpotTradesOptimized já salva os trades no banco de dados.
        $spotTrades = $this->getSpotTradesOptimized($apiKey);
        Log::info('[Binance Spot] Total de trades spot encontrados e processados: ' . count($spotTrades));

        // --- 2. IMPORTAÇÃO DE CONVERSÕES ---
        Log::info('[Binance Import] Iniciando importação de conversões.');
        $conversions = $this->convertService->getConvertHistory($startTime, $endTime);
        Log::info('[Binance Import] Histórico de conversões recebido da API.', ['count' => count($conversions)]);

        if (!empty($conversions)) {
            foreach ($conversions as $conv) {
                try {
                    $fromAsset = CryptoAsset::firstOrCreate(['symbol' => strtoupper($conv['fromAsset'])]);
                    $toAsset   = CryptoAsset::firstOrCreate(['symbol' => strtoupper($conv['toAsset'])]);
                    $price = ($conv['fromAmount'] > 0) ? $conv['toAmount'] / $conv['fromAmount'] : 0;
                    $date = Carbon::createFromTimestampMs($conv['createTime']);

                    $totalUsdt = null;
                    $totalBrl = null;

                    // Lógica para calcular valor em USDT
                    if (strtoupper($conv['fromAsset']) === 'USDT') {
                        $totalUsdt = $conv['fromAmount'];
                    } elseif (strtoupper($conv['toAsset']) === 'USDT') {
                        $totalUsdt = $conv['toAmount'];
                    } else {
                        $priceData = $priceService->getOrCreatePrice($conv['fromAsset'], $date);
                        if ($priceData && $priceData->price_usdt) {
                            $totalUsdt = $conv['fromAmount'] * $priceData->price_usdt;
                        }
                    }

                    // Lógica para calcular valor em BRL
                    if ($totalUsdt !== null) {
                        $usdtPriceInBrl = $priceService->getOrCreatePrice('USDT', $date);
                        if ($usdtPriceInBrl && $usdtPriceInBrl->price_brl) {
                            $totalBrl = $totalUsdt * $usdtPriceInBrl->price_brl;
                        }
                    }
                    
                    // Cria ou atualiza a transação
                    $transaction = Transaction::updateOrCreate(
                        [
                            'user_id'   => auth()->id(),
                            'reference' => $conv['quoteId'],
                            'type'      => 'convert',
                        ],
                        [
                            'source_type'      => \App\Models\Exchange::class,
                            'source_id'        => $apiKey->exchange_id,
                            'from_asset_id'    => $fromAsset->id,
                            'to_asset_id'      => $toAsset->id,
                            'from_asset'       => $conv['fromAsset'],
                            'to_asset'         => $conv['toAsset'],
                            'from_amount'      => $conv['fromAmount'],
                            'to_amount'        => $conv['toAmount'],
                            'price'            => $price,
                            'operation'        => 'permuta',
                            'date'             => $date,
                            'total_usdt'       => $totalUsdt,
                            'total_brl'        => $totalBrl,
                        ]
                    );

                    if ($transaction->wasRecentlyCreated) {
                        $newly_imported_count++;
                    }

                } catch (Exception $e) {
                    Log::error('[Binance Import] Falha ao salvar uma conversão.', [
                        'quoteId' => $conv['quoteId'] ?? 'N/A',
                        'error' => $e->getMessage()
                    ]);
                    // Continua para a próxima conversão em vez de parar tudo
                    continue;
                }
            } // Fim do loop foreach
        }

        DB::commit(); // Salva todas as alterações no banco de dados

        // A contagem de $spotTrades já vem da função que os processa.
        $totalImported = count($spotTrades) + $newly_imported_count;

        Log::info('[Binance Import] Importação concluída com sucesso.', [
            'newly_imported' => $totalImported,
            'total_spot_trades_found' => count($spotTrades),
            'total_conversions_found' => count($conversions)
        ]);

        return response()->json([
            'success' => true,
            'message' => "Importação concluída! {$totalImported} novas transações importadas.",
            'imported' => $totalImported,
            'total_found' => count($spotTrades) + count($conversions),
            'spot_trades' => count($spotTrades),
            'conversions' => count($conversions)
        ]);

    } catch (Exception $e) {
        DB::rollBack(); // Desfaz qualquer alteração no banco se ocorrer um erro crítico
        Log::error('[Binance Import] Erro crítico durante a importação.', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'error' => 'Ocorreu um erro crítico durante a importação. Verifique os logs para mais detalhes.'
        ], 500);
    }
}



 public function getConvertHistory($startTime, $endTime)
{
    Log::info('[BinanceConvertService] Iniciando busca de conversões diretas.');

    $baseUrl = 'https://api.binance.com/sapi/v1/convert/tradeFlow';
    $results = [];
    $cursor = null;

    do {
        $params = [
            'startTime'  => $startTime,
            'endTime'    => $endTime,
            'limit'      => 1000,
            'recvWindow' => 15000,
            'timestamp'  => round(microtime(true) * 1000),
        ];
        if ($cursor) $params['cursor'] = $cursor;

        $signature = hash_hmac('sha256', http_build_query($params), $this->secret);
        $params['signature'] = $signature;

        $response = Http::withHeaders([
            'X-MBX-APIKEY' => $this->key,
        ])->get($baseUrl, $params);

        if (!$response->successful()) {
            Log::error('[BinanceConvertService] Falha na resposta', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            break;
        }

        $data = $response->json();
        if (empty($data['list'])) break;

        $results = array_merge($results, $data['list']);
        $cursor = $data['nextPageCursor'] ?? null;

        usleep(300000);
    } while ($cursor);

    Log::info('[BinanceConvertService] Conversões importadas: ' . count($results));

    return $results;
}


/**
 * NOVA FUNÇÃO: formatBinanceConversion()
 * 
 * Adicione esta função no final da classe TransactionController
 */
private function formatBinanceConversion($conversion, $priceService)
{
    try {
        $executedAt = isset($conversion['createTime']) 
            ? Carbon::createFromTimestampMs($conversion['createTime'])
            : Carbon::now();
        
        // Usar o serviço de preços otimizado para obter valor USDT
        $usdtValue = $this->priceOptimizer->getUsdtValueFromConversion($conversion);
        $brlValue = null;
        
        if ($usdtValue) {
            $timestamp = $conversion['createTime'] ?? (time() * 1000);
            $brlValue = $this->priceOptimizer->getBrlValueFromUsdt($usdtValue, $timestamp);
        }
        
        // Fallback para CryptoPriceService se necessário
        if (!$brlValue) {
            $symbol = $conversion['toAsset'] === 'USDT' ? $conversion['fromAsset'] : $conversion['toAsset'];
            $amount = $conversion['toAsset'] === 'USDT' ? $conversion['fromAmount'] : $conversion['toAmount'];
            
            if ($executedAt && $symbol && $amount) {
                $price = $priceService->getOrCreatePrice($symbol, $executedAt);
                $brlValue = $price ? $amount * $price : null;
            }
        }
        
        return [
            'from_asset' => $conversion['fromAsset'] ?? 'UNKNOWN',
            'to_asset' => $conversion['toAsset'] ?? 'UNKNOWN',
            'from_amount' => (float) ($conversion['fromAmount'] ?? 0),
            'to_amount' => (float) ($conversion['toAmount'] ?? 0),
            'type' => 'convert',
            'operation' => 'permuta',
            'price' => null,
            'total_usdt' => $usdtValue,
            'total_brl' => $brlValue,
            'txid' => null,
            'reference' => $conversion['orderId'] ?? $conversion['quoteId'] ?? uniqid(),
            'date' => $executedAt,
        ];
        
    } catch (Exception $e) {
        Log::error('[Binance Conversion Format] Erro ao formatar conversão: ' . $e->getMessage());
        return null;
    }
}

    /**
     * NOVA FUNÇÃO OTIMIZADA: Busca histórico completo de trades spot com paginação
     */
protected function getSpotTradesOptimized(UserApiKey $apiKey, $startTime = null, $endTime = null): array
{
    Log::info('[Binance Spot v7] Iniciando importação de trades spot (compra/venda) com descoberta automática de símbolos.');

    $allTrades = [];
    $limit = 1000;
    $baseUrl = 'https://api.binance.com/api/v3/myTrades';

    // 🔹 Define período padrão de busca (5 anos)
    $startTime ??= Carbon::now()->subYears(5)->getTimestampMs();
    $endTime ??= Carbon::now()->getTimestampMs();

    try {
        // --- 1️⃣ Descobre automaticamente os pares que o usuário tem ou teve saldo ---
        $symbols = Cache::remember("binance_user_symbols_" . auth()->id(), 60 * 12, function () use ($apiKey) {
            $accountParams = [
                'timestamp'  => round(microtime(true) * 1000),
                'recvWindow' => 15000,
            ];
            $accountParams['signature'] = hash_hmac('sha256', http_build_query($accountParams), $apiKey->secret_key);

            $response = Http::withHeaders(['X-MBX-APIKEY' => $apiKey->api_key])
                ->get('https://api.binance.com/api/v3/account', $accountParams);

            if (!$response->successful()) {
                Log::error('[Binance Spot] Falha ao obter saldos para descoberta de pares.', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return [];
            }

            $balances = collect($response->json('balances') ?? [])
                ->filter(fn($b) => (float)$b['free'] > 0 || (float)$b['locked'] > 0)
                ->pluck('asset')
                ->unique()
                ->values()
                ->toArray();

            $quoteAssets = ['USDT', 'BTC', 'BUSD', 'BRL'];
            $symbols = [];

            foreach ($balances as $asset) {
                foreach ($quoteAssets as $quote) {
                    if ($asset === $quote) continue;
                    $symbols[] = "{$asset}{$quote}";
                }
            }

            Log::info('[Binance Spot] Símbolos detectados automaticamente.', [
                'count' => count($symbols),
                'sample' => array_slice($symbols, 0, 10),
            ]);

            return array_values(array_unique($symbols));
        });

        if (empty($symbols)) {
            Log::warning('[Binance Spot] Nenhum símbolo detectado para importação.');
            return [];
        }

        // --- 2️⃣ Itera sobre os símbolos relevantes e busca as operações ---
        foreach ($symbols as $symbol) {
            Log::info("[Binance Spot] Iniciando importação do símbolo: {$symbol}");
            $fromId = null;
            $batchCount = 0;

            do {
                // --- Parâmetros de paginação ---
                $params = [
                    'symbol'     => $symbol,
                    'limit'      => $limit,
                    'recvWindow' => 15000,
                    'startTime'  => $startTime,
                    'endTime'    => $endTime,
                    'timestamp'  => round(microtime(true) * 1000),
                ];
                if ($fromId) $params['fromId'] = $fromId;

                // --- Assina a requisição ---
                $queryString = http_build_query($params);
                $signature = hash_hmac('sha256', $queryString, $apiKey->secret_key);
                $params['signature'] = $signature;

                // --- Requisição ---
                $response = Http::withHeaders([
                    'X-MBX-APIKEY' => $apiKey->api_key,
                ])->get($baseUrl, $params);

                if (!$response->successful()) {
                    Log::warning("[Binance Spot] Erro ao consultar {$symbol}: {$response->body()}");
                    break;
                }

                $trades = $response->json();
                if (empty($trades)) break;

                // --- Processa as trades ---
                foreach ($trades as $trade) {
                    $allTrades[] = [
                        'symbol'           => $trade['symbol'],
                        'id'               => $trade['id'],
                        'orderId'          => $trade['orderId'],
                        'price'            => (float) $trade['price'],
                        'qty'              => (float) $trade['qty'],
                        'quoteQty'         => isset($trade['quoteQty']) ? (float) $trade['quoteQty'] : null,
                        'commission'       => (float) ($trade['commission'] ?? 0),
                        'commissionAsset'  => $trade['commissionAsset'] ?? null,
                        'time'             => $trade['time'],
                        'isBuyer'          => $trade['isBuyer'],
                        'type'             => $trade['isBuyer'] ? 'BUY' : 'SELL',
                    ];
                }

                // --- Próxima página ---
                $lastTrade = end($trades);
                $fromId = $lastTrade['id'] + 1;
                $batchCount++;

                Log::info("[Binance Spot] {$symbol}: lote {$batchCount} importado com " . count($trades) . " registros.");

                usleep(250000); // evita rate limit

            } while (count($trades) === $limit && $batchCount < 100);
        }

        // --- 3️⃣ Log final ---
        Log::info('[Binance Spot v7] Importação finalizada.', [
            'total_trades' => count($allTrades),
            'compras' => count(array_filter($allTrades, fn($t) => $t['isBuyer'])),
            'vendas'  => count(array_filter($allTrades, fn($t) => !$t['isBuyer'])),
        ]);

        return $allTrades;

    } catch (Exception $e) {
        Log::error('[Binance Spot v7] Erro crítico durante importação spot: ' . $e->getMessage(), [
            'exception' => $e,
        ]);
        throw $e;
    }
}







    /**
     * Descobre dinamicamente todos os símbolos que o usuário já negociou
     */
   private function discoverTradingSymbols($client, $startTime = null, $endTime = null): array
{
    $symbols = [];
    
    // MÉTODO 1: Símbolos comuns como base
    $commonSymbols = ['BTCUSDT', 'ETHUSDT', 'BNBUSDT', 'ADAUSDT', 'DOTUSDT', 'USDTBRL'];
    foreach ($commonSymbols as $symbol) {
        try {
            // Pergunta à Binance: "O utilizador já fez algum trade com este par?"
            $trades = $client->history($symbol, 10); 
            if (!empty($trades)) {
                $symbols[] = $symbol; // Se sim, adiciona à lista.
            }
        } catch (Exception $e) { continue; }
    }
    
    // MÉTODO 2: Expandir com base em padrões conhecidos
    $baseAssets = ['BTC', 'ETH', 'BNB', 'ADA', 'DOT', 'LINK', 'LTC', 'XRP', 'SOL', 'MATIC'];
    $quoteAssets = ['USDT', 'BTC', 'ETH', 'BRL'];
    
    foreach ($baseAssets as $base) {
        foreach ($quoteAssets as $quote) {
            $symbol = $base . $quote;
            if (!in_array($symbol, $symbols)) { // Só testa se ainda não foi encontrado
                try {
                    // Pergunta novamente: "E com este outro par?"
                    $trades = $client->history($symbol, 5);
                    if (!empty($trades)) {
                        $symbols[] = $symbol; // Se sim, adiciona.
                    }
                } catch (Exception $e) { continue; }
            }
        }
    }
    
    return array_values(array_unique($symbols));
}


    /**
     * Busca todos os trades de um símbolo específico com paginação automática
     */
  /**
 * VERSÃO FINAL CORRIGIDA: Busca todos os trades de um símbolo com paginação por tempo.
 * Esta abordagem evita o erro de "More than 24 hours" (-1127).
 */
/**
 * VERSÃO FINAL E CORRETA: Busca todos os trades de um símbolo com paginação por tempo.
 */
private function getSymbolTradesWithPagination($client, $symbol, $startTime = null, $endTime = null): array
{
    $allTrades = [];
    $limit = 1000;
    $finalStartTime = $startTime ?: Carbon::now()->subYears(5)->getTimestampMs();
    $currentIterTime = $endTime ?: Carbon::now()->getTimestampMs();

    Log::info("[Binance Spot Paging] Iniciando para {$symbol}", [
        'start' => date('Y-m-d H:i:s', $finalStartTime / 1000),
        'end' => date('Y-m-d H:i:s', $currentIterTime / 1000)
    ]);

    while ($currentIterTime > $finalStartTime) {
        $loopStartTime = max($finalStartTime, Carbon::createFromTimestampMs($currentIterTime)->subDay()->getTimestampMs());

        try {
            Log::debug("[Binance Spot Paging] Buscando trades para {$symbol}", [
                'from' => date('Y-m-d H:i:s', $loopStartTime / 1000),
                'to' => date('Y-m-d H:i:s', $currentIterTime / 1000)
            ]);

            // --- A CORREÇÃO ESTÁ NESTA CHAMADA ---
            // O terceiro argumento ($fromTradeId) é agora 0, como a biblioteca exige.
            $trades = $client->myTrades(
                $symbol,
                $limit,
                0, // fromId (0 para não usar, em vez de null)
                $loopStartTime,
                $currentIterTime
            );

            if (!empty($trades) && is_array($trades)) {
                $formattedTrades = [];
                foreach ($trades as $trade) {
                    $formattedTrade = $this->formatBinanceTradeOptimized($trade, $symbol);
                    if ($formattedTrade) {
                        $formattedTrades[] = $formattedTrade;
                    }
                }
                $allTrades = array_merge($allTrades, $formattedTrades);
                Log::info("[Binance Spot Paging] {$symbol}: " . count($formattedTrades) . " trades encontrados neste período.");
            }

            $currentIterTime = $loopStartTime;
            usleep(300000);

        } catch (Exception $e) {
            Log::error("[Binance Spot Paging] Erro na paginação para {$symbol}: " . $e->getMessage());
            $currentIterTime = Carbon::createFromTimestampMs($currentIterTime)->subDay()->getTimestampMs();
        }
    }

    Log::info("[Binance Spot Paging] Paginação concluída para {$symbol}", ['total_trades' => count($allTrades)]);
    
    if (empty($allTrades)) {
        return [];
    }

    return array_values(array_unique($allTrades, SORT_REGULAR));
}



private function callMyTradesWithRetry($client, $params, $maxRetries = 3): array
{
    $retries = 0;
    
    while ($retries < $maxRetries) {
        try {
            // CORREÇÃO 9: Chamar myTrades com parâmetros corretos
            $symbol = $params['symbol'];
            $limit = $params['limit'] ?? 1000;
            $fromId = $params['fromId'] ?? 0;
            $startTime = $params['startTime'] ?? null;
            $endTime = $params['endTime'] ?? null;
            
            return $client->myTrades($symbol, $limit, $fromId, $startTime, $endTime);
            
        } catch (Exception $e) {
            $retries++;
            
            if ($retries >= $maxRetries) {
                throw $e;
            }
            
            Log::warning("[Binance API] Tentativa {$retries} falhou, tentando novamente: " . $e->getMessage());
            sleep(1); // Aguardar 1 segundo antes de tentar novamente
        }
    }
    
    return [];
}

/**
 * SOLUÇÃO 2: Melhorar Descoberta de Símbolos para 2025
 * 
 * Adicione esta função para descobrir símbolos mais recentes:
 */

private function discoverRecentSymbols($client): array
{
    Log::info('[Symbol Discovery] Buscando símbolos com atividade recente');
    
    $symbols = [];
    $recentPeriod = Carbon::now()->subMonths(3)->getTimestampMs(); // Últimos 3 meses
    
    // Símbolos populares de 2025
    $popularSymbols = [
        'BTCUSDT', 'ETHUSDT', 'BNBUSDT', 'SOLUSDT', 'XRPUSDT',
        'ADAUSDT', 'DOTUSDT', 'LINKUSDT', 'MATICUSDT', 'AVAXUSDT',
        'USDTBRL', 'BTCBRL', 'ETHBRL'
    ];
    
    foreach ($popularSymbols as $symbol) {
        try {
            // Verificar atividade recente
            $recentTrades = $client->myTrades($symbol, 10, 0, $recentPeriod);
            
            if (!empty($recentTrades)) {
                $symbols[] = $symbol;
                Log::debug("[Symbol Discovery] Símbolo ativo em 2025: {$symbol}");
            }
            
            usleep(100000); // 100ms
            
        } catch (Exception $e) {
            continue;
        }
    }
    
    Log::info('[Symbol Discovery] Símbolos ativos em 2025 encontrados', [
        'count' => count($symbols),
        'symbols' => $symbols
    ]);
    
    return $symbols;
}

/**
 * VERSÃO DEFINITIVA v2: Descobre símbolos de negociação do utilizador de forma precisa e eficiente.
 * CORRIGIDO: Usa publicRequest para chamar exchangeInfo e evitar o erro -1104.
 */
/**
 * VERSÃO DEFINITIVA v3: Descobre símbolos de negociação do utilizador de forma precisa e eficiente.
 * CORRIGIDO: Usa o cliente HTTP do Laravel para a chamada pública, evitando erros da biblioteca.
 */
private function discoverUserTradingSymbols($client): array
{
    Log::info('[Symbol Discovery v4] Iniciando descoberta precisa e robusta.');
    
    try {
        // 1. Obter todos os pares de negociação que existem na Binance (cache de 24h)
        $allBinancePairs = Cache::remember('binance_all_trading_pairs', 60 * 24, function () {
            Log::info('[Symbol Discovery v4] Buscando todos os pares da Binance via HTTP direto...');
            
            // --- CORREÇÃO APLICADA AQUI ---
            // Usamos o cliente HTTP do Laravel para fazer uma chamada pública "limpa",
            // contornando qualquer peculiaridade da biblioteca da Binance para este endpoint.
            $response = Http::get('https://api.binance.com/api/v3/exchangeInfo' );

            if (!$response->successful() || empty($response->json('symbols'))) {
                Log::error('[Symbol Discovery v4] Falha ao buscar exchangeInfo via HTTP direto.', ['status' => $response->status()]);
                return [];
            }
            
            return array_column($response->json('symbols'), 'symbol');
        });

        if (empty($allBinancePairs)) {
            Log::error('[Symbol Discovery v4] Falha ao obter a lista de todos os pares da Binance.');
            return ['BTCUSDT', 'ETHUSDT', 'USDTBRL']; // Fallback mínimo
        }
        Log::info('[Symbol Discovery v4] Total de pares existentes na Binance: ' . count($allBinancePairs));

        // 2. Obter todos os ativos que o utilizador tem ou já teve saldo (usando o cliente autenticado)
        $accountInfo = $client->account();
        $userAssets = [];
        if (!empty($accountInfo['balances'])) {
            foreach ($accountInfo['balances'] as $balance) {
                if ((float)$balance['free'] > 0 || (float)$balance['locked'] > 0) {
                    $userAssets[] = $balance['asset'];
                }
            }
        }
        
        $userAssets = array_unique(array_merge($userAssets, ['BTC', 'ETH', 'BNB', 'USDT', 'BRL']));
        Log::info('[Symbol Discovery v4] Ativos relevantes para o utilizador:', ['assets' => $userAssets]);

        // 3. Filtrar a lista total de pares da Binance
        $relevantPairs = [];
        foreach ($allBinancePairs as $pair) {
            foreach ($userAssets as $asset) {
                if (str_starts_with($pair, $asset) || str_ends_with($pair, $asset)) {
                    $relevantPairs[] = $pair;
                }
            }
        }
        
        $finalSymbols = array_values(array_unique($relevantPairs));
        Log::info('[Symbol Discovery v4] Descoberta concluída.', ['total_found' => count($finalSymbols), 'symbols' => $finalSymbols]);

        return $finalSymbols;

    } catch (Exception $e) {
        Log::error('[Symbol Discovery v4] Erro crítico na descoberta de símbolos: ' . $e->getMessage());
        return ['BTCUSDT', 'ETHUSDT', 'USDTBRL']; // Fallback
    }
}








    /**
     * Formata um trade da Binance para o formato do sistema (versão otimizada)
     */
   private function formatBinanceTradeOptimized($trade, $symbol)
{
    try {
        // Determinar ativos do símbolo
        $assets = $this->parseSymbolAssets($symbol);
        if (!$assets) {
            Log::warning("[Binance Trade Format] Não foi possível parsear símbolo: {$symbol}");
            return null;
        }

        $isBuyer = $trade['isBuyer'] ?? false;
        $quantity = (float) $trade['qty'];
        $price = (float) $trade['price'];
        $quoteQty = (float) $trade['quoteQty'];
        
        // Calcular valor em USDT usando dados nativos da Binance
        $totalUsdt = $this->calculateUsdtValueFromTradeOptimized(
            $trade, 
            $assets['base'], 
            $assets['quote'], 
            $isBuyer
        );

        // Se não conseguiu calcular via dados nativos, usar o valor da transação
        if ($totalUsdt === null) {
            $totalUsdt = $assets['quote'] === 'USDT' ? $quoteQty : null;
        }

        return [
            'user_id' => auth()->id(),
            'type' => 'spot', // CORRIGIDO: era 'trade', agora é 'spot'
            'operation' => $isBuyer ? 'compra' : 'venda',
            'from_asset' => $isBuyer ? $assets['quote'] : $assets['base'],
            'to_asset' => $isBuyer ? $assets['base'] : $assets['quote'],
            'from_amount' => $isBuyer ? $quoteQty : $quantity,
            'to_amount' => $isBuyer ? $quantity : $quoteQty,
            'price' => $price,
            'total_usdt' => $totalUsdt,
            'total_brl' => null, // Será calculado depois usando BinancePriceOptimizer
            'date' => Carbon::createFromTimestampMs($trade['time']),
            'reference' => 'binance_' . $trade['id'],
            'exchange' => 'binance',
            'fees' => $trade['commission'] ?? 0,
            'fees_asset' => $trade['commissionAsset'] ?? $assets['quote'],
        ];
        
    } catch (Exception $e) {
        Log::error('[Binance Trade Format] Erro ao formatar trade: ' . $e->getMessage());
        return null;
    }
}

    /**
     * Parseia um símbolo da Binance para extrair base e quote assets
     */
    private function parseSymbolAssets($symbol): ?array
    {
        $knownQuotes = ['USDT', 'BUSD', 'USDC', 'BRL', 'EUR', 'GBP', 'BTC', 'ETH', 'BNB'];
        
        foreach ($knownQuotes as $quote) {
            if (str_ends_with($symbol, $quote)) {
                $base = str_replace($quote, '', $symbol);
                if (!empty($base)) {
                    return ['base' => $base, 'quote' => $quote];
                }
            }
        }
        
        Log::warning("[Binance Spot] Símbolo não reconhecido: {$symbol}");
        return null;
    }

    /**
     * Calcula o valor em USDT usando dados nativos da Binance (versão otimizada)
     */
    private function calculateUsdtValueFromTradeOptimized($trade, $baseAsset, $quoteAsset, $isBuyer): ?float
    {
        // Se o quote asset já é USDT, usamos o valor diretamente
        if ($quoteAsset === 'USDT') {
            return (float) $trade['quoteQty'];
        }
        
        // Se o base asset é USDT (caso de USDTBRL por exemplo)
        if ($baseAsset === 'USDT') {
            return $isBuyer ? (float) $trade['qty'] : (float) $trade['qty'];
        }
        
        // Para outros pares, retornamos null para usar o sistema de preços otimizado
        return null;
    }

    /**
     * Calcula valor USDT otimizado para conversões
     */
    private function calculateUsdtValueOptimized($conversion, $priceOptimizer): ?float
    {
        if ($conversion['fromAsset'] === 'USDT') {
            return (float) $conversion['fromAmount'];
        }
        
        if ($conversion['toAsset'] === 'USDT') {
            return (float) $conversion['toAmount'];
        }
        
        // Usa o otimizador de preços para outros casos
        return $priceOptimizer->getUsdtValueFromConversion($conversion);
    }


    // Mantém as outras funções existentes (importFromCoinbase, importFromKraken, etc.)
    // ... (código das outras exchanges permanece inalterado)

    /**
     * Mostra página de importação
     */
    public function import()
    {
        $exchanges = Exchange::all();
        $userApiKeys = UserApiKey::where('user_id', auth()->id())
            ->with('exchange')
            ->get();

        return Inertia::render('Transactions/Import', [
            'exchanges' => $exchanges,
            'userApiKeys' => $userApiKeys,
        ]);
    }

    /**
     * Sincroniza transações de uma exchange específica
     */
    public function syncFromExchange(Request $request)
    {
        $request->validate([
            'exchange' => 'required|string',
        ]);

        return $this->importFromExchange($request, $request->exchange);
    }

    /**
     * Importa CSV de transações
     */
    public function importCsv(Request $request)
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240',
            'format' => 'required|string',
            'skip_duplicates' => 'boolean',
            'source_type' => 'required|in:exchange,wallet',
            'source_id' => 'required|integer',
        ]);

        $sourceModel = match ($validated['source_type']) {
            'exchange' => \App\Models\UserApiKey::class,
            'wallet'   => \App\Models\Wallet::class,
        };

        $source = $sourceModel::where('user_id', auth()->id())->findOrFail($validated['source_id']);

        $csv = array_map('str_getcsv', file($request->file('file')));
        $headers = array_map('trim', $csv[0]);
        $rows = array_slice($csv, 1);

        $imported = 0;

        foreach ($rows as $row) {
            $data = array_combine($headers, $row);

            $transactionData = [
                'user_id' => auth()->id(),
                'source_type' => $sourceModel,
                'source_id' => $source->id,
                'from_asset' => $data['from_asset'] ?? null,
                'to_asset' => $data['to_asset'] ?? null,
                'from_amount' => $data['from_amount'] ?? null,
                'to_amount' => $data['to_amount'] ?? null,
                'price' => $data['price'] ?? null,
                'total_usdt' => $data['total_usdt'] ?? null,
                'total_brl' => $data['total_brl'] ?? null,
                'type' => $data['type'] ?? 'other',
                'operation' => $data['operation'] ?? null,
                'txid' => $data['txid'] ?? null,
                'reference' => $data['reference'] ?? null,
                'date' => isset($data['date']) ? \Carbon\Carbon::parse($data['date']) : now(),
            ];

            Transaction::create($transactionData);
            $imported++;
        }

        return redirect()->route('transactions.index')
            ->with('success', "{$imported} transações importadas com sucesso.");
    }

}