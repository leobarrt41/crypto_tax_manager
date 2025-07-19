<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use App\Models\Transaction;
use App\Models\UserApiKey;
use App\Models\CryptoAsset;
use App\Models\Exchange;
use Binance\API as BinanceAPI;
use Carbon\Carbon;
use App\Services\FifoCalculatorService;
use Exception;

class TransactionController extends Controller
{
    /**
     * Lista as transações do usuário autenticado
     */
    public function index(Request $request)
    {
        $filters = $request->only([
            'search', 'type', 'crypto_asset_id', 'date_range', 'start_date', 'end_date'
        ]);

       $query = Transaction::with(['source', 'fromCryptoAsset', 'toCryptoAsset'])
            ->where('user_id', auth()->id())
            ->orderByDesc('date');

        // Aplicar filtros se existirem
        if ($filters['search'] ?? false) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('from_asset', 'ilike', "%{$search}%")
                  ->orWhere('to_asset', 'ilike', "%{$search}%")
                  ->orWhere('txid', 'ilike', "%{$search}%")
                  ->orWhere('reference', 'ilike', "%{$search}%");
            });
        }

        if ($filters['origin'] ?? false) {
                    switch ($filters['origin']) {
                        case 'wallet':
                            $query->where('source_type', Wallet::class);
                            break;
                        case 'exchange':
                            $query->where('source_type', UserApiKey::class);
                            break;
                    }
                }

        if ($filters['type'] ?? false) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['date_range']) && $filters['date_range'] === 'custom') {
            if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
                $query->whereBetween('date', [$filters['start_date'], $filters['end_date']]);
            }
        }

        // Adicionar outros filtros de data
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
            'from_crypto_asset' => $tr->fromCryptoAsset ? [
                'symbol' => $tr->fromCryptoAsset->symbol,
                'name'   => $tr->fromCryptoAsset->name,
            ] : null,
            'to_crypto_asset'   => $tr->toCryptoAsset ? [
                'symbol' => $tr->toCryptoAsset->symbol,
                'name'   => $tr->toCryptoAsset->name,
            ] : null,
            'from_amount'       => $tr->from_amount,
            'to_amount'         => $tr->to_amount,
            'unit_price'        => $tr->price,
            'total_amount'      => $tr->total_usdt, // ou total_brl, conforme currency selecionada
            'fees'              => 0,               // ajuste conforme seu modelo
            'executed_at'       => $tr->date?->toIso8601String(), // string ISO, válida para o Vue
        ];
    });

        // Dados para os cards (usando query clone para não afetar paginação)
        $statsQuery = Transaction::where('user_id', auth()->id());
        
        $stats = [
            'total_transactions' => $statsQuery->count(),
            'total_volume' => $statsQuery->sum('total_usdt') ?? 0,
            'this_month' => $statsQuery->whereMonth('date', now()->month)->count(),
            'profit_loss' => 0, // Será calculado após implementar FIFO
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
            Transaction::create($data);

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
    public function importFromExchange($exchange)
    {
        $apiKey = UserApiKey::where('user_id', auth()->id())
            ->whereHas('exchange', fn($q) => $q->where('name', strtolower($exchange)))
            ->first();

        if (!$apiKey) {
            return response()->json([
                'error' => "Chave da exchange '{$exchange}' não cadastrada."
            ], 400);
        }

        try {
            return match (strtolower($exchange)) {
                'binance' => $this->importFromBinance($apiKey),
                'coinbase' => $this->importFromCoinbase($apiKey),
                'kraken' => $this->importFromKraken($apiKey),
                'kucoin' => $this->importFromKucoin($apiKey),
                'bitfinex' => $this->importFromBitfinex($apiKey),
                default => response()->json([
                    'error' => "Exchange não suportada: {$exchange}"
                ], 400),
            };
        } catch (Exception $e) {
            Log::error("Erro ao importar de {$exchange}: " . $e->getMessage());
            
            return response()->json([
                'error' => 'Erro interno durante a importação.'
            ], 500);
        }
    }

    /**
     * Importa transações da Binance
     */
 protected function importFromBinance($apiKey)
{
    try {
        Log::info('[Binance Import] Iniciando importação para o usuário: ' . auth()->id());

        $client = new BinanceAPI($apiKey->api_key, $apiKey->secret_key);
        $client->keepAlive = false;
        $client->subscriptions = [];
        $client->caOverride = false;
        $client->proxyConf = null;
        $client->useServerTime();
        
        usleep(100000); // 100ms

        Log::info('[Binance] API Key: ' . substr($apiKey->api_key, 0, 8) . '...');
        Log::info('[Binance] Secret existe: ' . (!empty($apiKey->secret_key) ? 'Sim' : 'Não'));
        Log::info('[Binance Import] Instância Binance criada e sincronizada com server time.');

        $accountInfo = $client->account();
        if (!$accountInfo || isset($accountInfo['code'])) {
            Log::warning('[Binance Import] Falha na autenticação da conta Binance.', ['response' => $accountInfo]);
            return response()->json(['error' => 'Falha na autenticação com a Binance.'], 401);
        }

        Log::info('[Binance Import] Autenticação bem-sucedida.');

        DB::beginTransaction();
        $imported = 0;

        // --- IMPORT SPOT ---
        $spotTrades = $this->getSpotTrades($client);
        Log::info('[Binance Spot] Total de trades spot encontrados: ' . count($spotTrades));

        foreach ($spotTrades as $tradeData) {
            $created = Transaction::updateOrCreate(
                [
                    'user_id' => auth()->id(),
                    'source_type' => UserApiKey::class,
                    'source_id' => $apiKey->id,
                    'reference' => $tradeData['reference'],
                ],
                $tradeData
            );

            if ($created->wasRecentlyCreated) {
                $imported++;
                Log::info('[Binance Spot] Nova transação spot criada.', ['reference' => $created->reference]);
            } else {
                Log::info('[Binance Spot] Transação spot já existia.', ['reference' => $created->reference]);
            }
        }

        // --- IMPORT CONVERSIONS ---
        $conversions = $this->getConvertHistory($client);
        Log::info('[Binance Import] Histórico de conversões recebido.', ['count' => count($conversions)]);

        foreach ($conversions as $trade) {
            Log::debug('[Binance Import] Processando trade:', $trade);

            $created = Transaction::updateOrCreate(
                [
                    'user_id' => auth()->id(),
                    'source_type' => UserApiKey::class,
                    'source_id' => $apiKey->id,
                    'reference' => $trade['orderId'] ?? $trade['quoteId'] ?? null,
                ],
                [
                    'from_asset' => $trade['fromAsset'],
                    'to_asset' => $trade['toAsset'],
                    'from_amount' => $trade['fromAmount'],
                    'to_amount' => $trade['toAmount'],
                    'type' => 'convert',
                    'operation' => 'troca',
                    'price' => $trade['fromAmount'] > 0 
                        ? $trade['toAmount'] / $trade['fromAmount'] 
                        : null,
                    'date' => Carbon::createFromTimestampMs($trade['createTime']),
                    'total_usdt' => $this->calculateUsdtValue($trade),
                    'total_brl' => null,
                ]
            );

            if ($created->wasRecentlyCreated) {
                $imported++;
                Log::info('[Binance Import] Nova transação convert criada.', ['reference' => $created->reference]);
            } else {
                Log::info('[Binance Import] Transação convert já existia.', ['reference' => $created->reference]);
            }
        }

        DB::commit();

        Log::info("[Binance Import] Importação finalizada. Total importado: {$imported}");

        return response()->json([
            'success' => true,
            'message' => "Importadas {$imported} novas transações da Binance!",
            'imported' => $imported,
            'total_found' => count($spotTrades) + count($conversions),
        ]);

    } catch (Exception $e) {
        DB::rollBack();
        Log::error('[Binance Import] Erro na importação da Binance: ' . $e->getMessage());

        return response()->json([
            'error' => 'Erro ao importar transações da Binance: ' . $e->getMessage()
        ], 500);
    }
}





    /**
     * Importa transações da Coinbase
     */
    protected function importFromCoinbase($apiKey)
    {
        // TODO: Implementar integração com Coinbase Pro API
        return response()->json([
            'success' => true,
            'message' => 'Importação da Coinbase ainda não implementada.',
            'imported' => 0,
        ]);
    }

    /**
     * Importa transações da Kraken
     */
    protected function importFromKraken($apiKey)
    {
        // TODO: Implementar integração com Kraken API
        return response()->json([
            'success' => true,
            'message' => 'Importação da Kraken ainda não implementada.',
            'imported' => 0,
        ]);
    }

    /**
     * Importa transações da KuCoin
     */
    protected function importFromKucoin($apiKey)
    {
        // TODO: Implementar integração com KuCoin API
        return response()->json([
            'success' => true,
            'message' => 'Importação da KuCoin ainda não implementada.',
            'imported' => 0,
        ]);
    }

    /**
     * Importa transações da Bitfinex
     */
    protected function importFromBitfinex($apiKey)
    {
        // TODO: Implementar integração com Bitfinex API
        return response()->json([
            'success' => true,
            'message' => 'Importação da Bitfinex ainda não implementada.',
            'imported' => 0,
        ]);
    }

    /**
     * Busca histórico completo de conversões da Binance
     */
    private function getConvertHistory($client)
    {
        $trades = [];
        $startTime = Carbon::now()->subYears(2)->getTimestampMs();
        $limit = 1000;

        try {
            do {
                $response = $client->sapiRequest(
                    'GET',
                    '/sapi/v1/convert/tradeFlow',
                    [
                        'startTime' => $startTime,
                        'limit' => $limit,
                        'recvWindow' => 60000,
                    ],
                    true
                );

                if (!is_array($response) || empty($response)) {
                    break;
                }

                $trades = array_merge($trades, $response);
                
                // Atualizar startTime para próxima página
                $lastTrade = end($response);
                $startTime = $lastTrade['createTime'] + 1;

                // Rate limiting - 300ms entre chamadas
                usleep(300_000);

            } while (count($response) === $limit);

        } catch (Exception $e) {
            Log::error('Erro ao buscar histórico da Binance: ' . $e->getMessage());
            throw $e;
        }

        return $trades;
    }

    /**
     * Calcula valor em USDT de uma transação
     */
    private function calculateUsdtValue($trade)
    {
        // Se uma das moedas for USDT, usar o valor diretamente
        if ($trade['fromAsset'] === 'USDT') {
            return $trade['fromAmount'];
        }
        
        if ($trade['toAsset'] === 'USDT') {
            return $trade['toAmount'];
        }

        // TODO: Implementar conversão para USDT usando preços históricos
        return null;
    }

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

        return $this->importFromExchange($request->exchange);
    }


 protected function getSpotTrades($client): array
{
    $trades = [];

    // Lista de sufixos de quote conhecidos
    $quoteAssets = ['USDT', 'BUSD', 'BTC', 'ETH', 'BNB', 'TRY', 'EUR', 'BRL', 'USD', 'TUSD', 'FDUSD', 'DAI'];

    // Lista de ativos cadastrados
    $symbols = CryptoAsset::pluck('symbol')
        ->filter()
        ->map(fn($s) => strtoupper($s))
        ->unique()
        ->values();

    \Log::debug('[Binance Spot] Símbolos base cadastrados: ', $symbols->toArray());

    // CORREÇÃO: Sincroniza horário com o servidor da Binance ANTES de qualquer operação
    try {
        $client->useServerTime();
        usleep(500000); // 500ms para garantir sincronização
        \Log::info("[Binance Spot] Tempo sincronizado com sucesso.");
    } catch (\Exception $e) {
        \Log::warning("[Binance Spot] Falha ao sincronizar horário: {$e->getMessage()}");
        return []; // Retorna vazio se não conseguir sincronizar
    }

    // Obtém todos os pares válidos da Binance
    try {
        $exchangeInfo = $client->exchangeInfo();
        $validSymbols = collect($exchangeInfo['symbols'])
            ->filter(fn($s) => $s['status'] === 'TRADING' && $s['isSpotTradingAllowed'])
            ->pluck('symbol')
            ->values();

        \Log::debug('[Binance Spot] Pares válidos da Binance filtrados: ', $validSymbols->toArray());
    } catch (\Exception $e) {
        \Log::error("[Binance Spot] Erro ao obter informações da exchange: {$e->getMessage()}");
        return [];
    }

    // Filtra os pares que envolvem ativos cadastrados
    $pairSymbols = $validSymbols->filter(function ($symbol) use ($symbols, $quoteAssets) {
        foreach ($quoteAssets as $quote) {
            if (str_ends_with($symbol, $quote)) {
                $base = substr($symbol, 0, -strlen($quote));
                if ($symbols->contains($base) && $symbols->contains($quote)) {
                    return true;
                }
            }
        }
        return false;
    })->values();

    // CORREÇÃO: Processar apenas alguns pares por vez para evitar rate limit
    $processedCount = 0;
    $maxPairs = 10; // Limitar para teste

    foreach ($pairSymbols->take($maxPairs) as $symbol) {
        $fromId = null;
        $hasMore = true;
        $attempts = 0;
        $maxAttempts = 3;

        while ($hasMore && $attempts < $maxAttempts) {
            try {
                // CORREÇÃO: Sincronizar antes de cada chamada individual
                $client->useServerTime();
                usleep(1000000); // 1 segundo entre chamadas para evitar rate limit
                
                $result = $client->myTrades($symbol);

                if (empty($result)) {
                    \Log::info("[Binance Spot] Nenhum trade encontrado para {$symbol}");
                    break;
                }

                foreach ($result as $trade) {
                    $baseAsset = null;
                    $quoteAsset = null;

                    foreach ($quoteAssets as $quote) {
                        if (str_ends_with($symbol, $quote)) {
                            $baseAsset = substr($symbol, 0, -strlen($quote));
                            $quoteAsset = $quote;
                            break;
                        }
                    }

                    if (!$baseAsset || !$quoteAsset) {
                        \Log::warning("[Binance Spot] Falha ao extrair base/quote de {$symbol}");
                        continue;
                    }

                    $isBuyer = $trade['isBuyer'];

                    $fromAsset  = $isBuyer ? $quoteAsset : $baseAsset;
                    $toAsset    = $isBuyer ? $baseAsset : $quoteAsset;
                    $fromAmount = $isBuyer ? $trade['quoteQty'] : $trade['qty'];
                    $toAmount   = $isBuyer ? $trade['qty'] : $trade['quoteQty'];

                    $trades[] = [
                        'user_id'      => auth()->id(),
                        'from_asset'   => $fromAsset,
                        'to_asset'     => $toAsset,
                        'from_amount'  => $fromAmount,
                        'to_amount'    => $toAmount,
                        'type'         => 'spot',
                        'operation'    => $isBuyer ? 'compra' : 'venda',
                        'price'        => $trade['price'],
                        'total_usdt'   => $quoteAsset === 'USDT' ? $trade['quoteQty'] : null,
                        'total_brl'    => null,
                        'txid'         => null,
                        'source'       => 'binance',
                        'reference'    => $trade['orderId'],
                        'date'         => \Carbon\Carbon::createFromTimestampMs($trade['time']),
                    ];

                    $fromId = $trade['id'] + 1;
                }

                $hasMore = count($result) === 500;
                $attempts = 0; // Reset attempts on success

                \Log::info("[Binance Spot] Processados " . count($result) . " trades para {$symbol}");

            } catch (\Exception $e) {
                $attempts++;
                \Log::warning("[Binance Spot] Erro ao buscar trades para {$symbol} (tentativa {$attempts}): {$e->getMessage()}");
                
                if ($attempts >= $maxAttempts) {
                    \Log::error("[Binance Spot] Máximo de tentativas excedido para {$symbol}");
                    break;
                }
                
                // Aguardar mais tempo antes de tentar novamente
                usleep(2000000); // 2 segundos
            }
        }

        $processedCount++;
        \Log::info("[Binance Spot] Progresso: {$processedCount}/{$maxPairs} pares processados");
    }

    \Log::info("[Binance Spot] Total de trades coletados: " . count($trades));
    return $trades;
}


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

    // 🔍 Lê o CSV
    $csv = array_map('str_getcsv', file($request->file('file')));
    $headers = array_map('trim', $csv[0]);
    $rows = array_slice($csv, 1);

    $imported = 0;

    foreach ($rows as $row) {
        $data = array_combine($headers, $row);

        // Ajuste este mapeamento conforme seu formato real de CSV
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

        // Aqui você pode usar updateOrCreate se quiser evitar duplicatas com base em txid ou reference
        Transaction::create($transactionData);
        $imported++;
    }

    return redirect()->route('transactions.index')->with('success', "{$imported} transações importadas com sucesso.");
}





}
