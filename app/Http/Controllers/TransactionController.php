<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use App\Jobs\ProcessBinanceImport; 
use App\Models\Transaction;
use App\Models\UserApiKey;
use App\Models\CryptoAsset;
use App\Services\FifoCalculatorService;
use App\Services\BinanceImportService; // ✅ Importa o novo serviço
use App\Services\CryptoPriceService;
use Exception;
use Carbon\Carbon; // Necessário para a reconstrução de saldos
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

class TransactionController extends Controller
{
    /**
     * Lista as transações do usuário autenticado com filtros e paginação.
     */
    // Em app/Http/Controllers/TransactionController.php

public function index(Request $request)
{
    // 1. Valida e obtém os filtros da requisição.
    $filters = $request->validate([
        'search' => 'nullable|string|max:100',
        'type' => 'nullable|string|in:trade,convert,deposit,withdrawal,fiat_buy,fiat_sell,mining,staking',
        'crypto_asset_id' => 'nullable|integer|exists:crypto_assets,id',
        'date_range' => 'nullable|string',
        'start_date' => 'nullable|date',
        'end_date' => 'nullable|date',
    ]);

    if (($filters['type'] ?? null) === 'spot') {
        $filters['type'] = 'trade';
    }

    // 2. Constrói a consulta base para as transações do usuário.
    $query = Transaction::query()
        ->where('user_id', auth()->id())
        ->with(['fromCryptoAsset:id,symbol,name', 'toCryptoAsset:id,symbol,name']); // Carrega apenas colunas necessárias

    // 3. Aplica os filtros de forma condicional e limpa.
    $query->when($filters['search'] ?? null, function ($q, $search) {
        $q->where(function ($subQ) use ($search) {
            $subQ->where('from_asset', 'ilike', "%{$search}%")
                 ->orWhere('to_asset', 'ilike', "%{$search}%")
                 ->orWhere('txid', 'ilike', "%{$search}%")
                 ->orWhere('reference', 'ilike', "%{$search}%");
        });
    });

    $query->when($filters['type'] ?? null, function ($q, $type) {
        $q->where('type', $type);
    });

    if (($filters['date_range'] ?? null) === 'custom' && !empty($filters['start_date']) && !empty($filters['end_date'])) {
        $query->whereBetween('date', [$filters['start_date'], $filters['end_date']]);
    } elseif (!empty($filters['date_range'])) {
        match ($filters['date_range']) {
            'today' => $query->whereDate('date', today()),
            'week'  => $query->whereBetween('date', [now()->startOfWeek(), now()->endOfWeek()]),
            'month' => $query->whereYear('date', now()->year)->whereMonth('date', now()->month),
            'year'  => $query->whereYear('date', now()->year),
            default => null,
        };
    }

    // 4. ✅ CORREÇÃO DO BUG: Executa a paginação na consulta que foi construída com os filtros.
    $transactions = $query->orderByDesc('date')->paginate(15)->withQueryString();

    // Enriquecer dados com informações de taxa convertidas
    $priceService = app(CryptoPriceService::class);
    $transactions->getCollection()->transform(function (Transaction $transaction) use ($priceService) {
        $transaction->fee_brl = null;
        $transaction->fee_usdt = null;

        if (!empty($transaction->commission) && !empty($transaction->commission_asset) && $transaction->date) {
            try {
                $prices = $priceService->getOrCreatePrice(
                    $transaction->commission_asset,
                    $transaction->date instanceof Carbon ? $transaction->date : Carbon::parse($transaction->date)
                );

                $commissionAmount = (float)$transaction->commission;
                if ($prices->price_brl > 0) {
                    $transaction->fee_brl = round($commissionAmount * (float)$prices->price_brl, 8);
                }
                if ($prices->price_usd > 0) {
                    $transaction->fee_usdt = round($commissionAmount * (float)$prices->price_usd, 8);
                }
            } catch (Exception $e) {
                Log::warning('[Transactions] Falha ao calcular taxa convertida', [
                    'transaction_id' => $transaction->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $transaction;
    });

    // 5. Otimização: Calcula as estatísticas a partir da mesma query dos filtros, ANTES da paginação.
    $statsQuery = $query->clone()->getQuery(); // Clona a query base sem paginação/ordenação
    $stats = [
        'total_transactions' => (clone $statsQuery)->count(),
        'total_volume' => (clone $statsQuery)->sum('total_usdt') ?? 0,
        'total_volume_brl' => (clone $statsQuery)->sum('total_brl') ?? 0,
        'this_month' => (clone $statsQuery)->whereYear('date', now()->year)->whereMonth('date', now()->month)->count(),
        'profit_loss' => 0, // Placeholder
    ];

    // 6. Retorna os dados para a view do Inertia.
    return Inertia::render('Transactions/Index', [
        'transactions' => $transactions,
        'stats' => $stats,
        'filters' => $filters,
        // Otimização: Carrega apenas os ativos que o usuário realmente possui para o filtro.
        'cryptoAssets' => fn () => CryptoAsset::whereHas('transactions', function ($q) {
            $q->where('user_id', auth()->id());
        })->orderBy('symbol')->get(['id', 'symbol']),
    ]);
}


    // ===================================================================
    // MÉTODOS DE CRUD (NENHUMA MUDANÇA NECESSÁRIA)
    // ===================================================================
    /**
     * Mostra o formulário para criar uma nova transação.
     */
    public function create()
    {
        return Inertia::render('Transactions/Create', [
            'cryptoAssets' => CryptoAsset::all(),
        ]);
    }

    /**
     * Armazena uma nova transação criada manualmente no banco de dados.
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

            // Se for uma operação de saída, chama o serviço de cálculo FIFO.
            if ($data['operation'] === 'saida') {
                app(FifoCalculatorService::class)->calculateFor($transaction);
            }

            return redirect()->route('transactions.index')
                ->with('success', 'Transação cadastrada com sucesso!');
        } catch (Exception $e) {
            Log::error('Erro ao criar transação manual: ' . $e->getMessage());
            
            return back()->withErrors(['error' => 'Erro ao cadastrar transação.'])
                ->withInput();
        }
    }

    /**
     * Mostra os detalhes de uma transação específica.
     */
    public function show($id)
    {
        // Validação básica para garantir que o ID é numérico.
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
     * Mostra o formulário para editar uma transação existente.
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
     * Atualiza uma transação específica no banco de dados.
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

            // Se a operação foi alterada para saída, recalcula o FIFO.
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
     * Remove uma transação específica do banco de dados.
     * CORRIGIDO: Redireciona de volta para a página anterior, mantendo a paginação e os filtros.
     */
    public function destroy($id)
    {
        $transaction = Transaction::where('user_id', auth()->id())->findOrFail($id);

        try {
            $transaction->delete();
            
            // Redireciona para a página anterior de onde a requisição veio.
            return redirect()->back()->with('success', 'Transação removida com sucesso!');

        } catch (Exception $e) {
            Log::error('Erro ao remover transação: ' . $e->getMessage());
            
            return redirect()->back()->withErrors(['error' => 'Erro ao remover transação.']);
        }
    }



    // Em app/Http/Controllers/TransactionController.php

/**
 * Remove TODAS as transações do usuário autenticado.
 * Ação destrutiva, a ser usada com cuidado.
 */
// Em app/Http/Controllers/TransactionController.php

/**
 * Remove TODAS as transações do usuário autenticado.
 * Ação destrutiva, a ser usada com cuidado.
 */
public function destroyAll()
{
    // ✅ LOG 1: Confirma que a rota foi acessada e o método foi chamado.
    Log::info('[Exclusão em Massa] Método destroyAll foi acessado pelo usuário: ' . auth()->id());

    try {
        $userId = auth()->id();

        // ✅ LOG 2: Conta quantas transações existem ANTES de deletar.
        $initialCount = Transaction::where('user_id', $userId)->count();
        Log::info("[Exclusão em Massa] Encontradas {$initialCount} transações para o usuário {$userId} antes da exclusão.");

        if ($initialCount === 0) {
            Log::warning('[Exclusão em Massa] Nenhuma transação encontrada para deletar. Ação interrompida.');
            return redirect()->route('transactions.index')->with('warning', 'Nenhuma transação para remover.');
        }

        // Deleta todas as transações associadas ao usuário logado.
        $deletedCount = Transaction::where('user_id', $userId)->delete();

        // ✅ LOG 3: Confirma o resultado da operação de DELETE.
        Log::info("[Exclusão em Massa] Resultado da operação de exclusão: {$deletedCount} linhas afetadas.");

        // Limpa o cache de snapshots para forçar uma reconstrução completa na próxima importação.
        Cache::forget('binance_user_all_traded_symbols_' . $userId);
        \App\Models\MonthlyAssetSnapshot::where('user_id', $userId)->delete();
        Log::info('[Exclusão em Massa] Cache de símbolos e snapshots foram limpos.');

        // Redireciona de volta com uma mensagem de sucesso.
        return redirect()->route('transactions.index')->with('success', "{$deletedCount} transações foram removidas com sucesso!");

    } catch (Exception $e) {
        // ✅ LOG 4: Captura qualquer erro inesperado durante o processo.
        Log::error('[Exclusão em Massa] Ocorreu uma exceção: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ]);
        
        return redirect()->back()->withErrors(['error' => 'Ocorreu um erro ao tentar remover todas as transações.']);
    }
}



    // ===================================================================
    // PONTOS DE ENTRADA PARA IMPORTAÇÃO
    // ===================================================================

    /**
     * Mostra a página de importação.
     */
    public function import()
    {
        return Inertia::render('Transactions/Import', [
            'exchanges' => \App\Models\Exchange::all(),
            'userApiKeys' => UserApiKey::where('user_id', auth()->id())->with('exchange')->get(),
        ]);
    }

    /**
     * Ponto de entrada da API para sincronizar transações de uma exchange.
     * Delega a lógica para o serviço apropriado.
     */
    public function syncFromExchange(Request $request, string $exchange)
{
    // Log inicial para confirmar que a requisição chegou.
    Log::info("✅ [PONTO DE ENTRADA] Requisição para iniciar importação da exchange '{$exchange}'.");

    // Validação simples para garantir que o nome da exchange é válido.
    $validated = validator(['exchange' => $exchange], ['exchange' => 'required|string|in:binance'])->validate();

    try {
        // Usa o 'match' para decidir qual Job despachar.
        // Isso torna fácil adicionar outras exchanges no futuro.
        match (strtolower($validated['exchange'])) {
            'binance' => ProcessBinanceImport::dispatch(auth()->user()),
            // 'coinbase' => ProcessCoinbaseImport::dispatch(auth()->user()), // Exemplo futuro
            default => throw new Exception("A exchange '{$validated['exchange']}' não é suportada."),
        };

        Log::info("✅ [PONTO DE ENTRADA] Job de importação para '{$validated['exchange']}' despachado com sucesso para o usuário: " . auth()->id());

        // Retorna uma resposta IMEDIATA para o front-end.
        // O status 202 (Accepted) é o padrão para "Ok, recebi seu pedido e vou processá-lo".
        return redirect()->back();

    } catch (Exception $e) {
        Log::error("🚨 [PONTO DE ENTRADA] Falha ao despachar o Job de importação.", [
            'exchange' => $exchange,
            'error' => $e->getMessage()
        ]);
        return response()->json(['error' => 'Não foi possível iniciar o processo de importação.'], 500);
    }
}

    /**
     * Lida com a importação da Binance instanciando e chamando o BinanceImportService.
     */
// Em app/Http/Controllers/TransactionController.php

/**
 * Lida com a importação da Binance, com tratamento de erro para chave de API ausente.
 */
private function handleBinanceImport(Request $request): \Illuminate\Http\JsonResponse
{
    try {
        // ✅ LOG ADICIONADO AQUI para ver se o método é alcançado
        Log::info("[Controller] Tentando instanciar o BinanceImportService.");

        $importService = new BinanceImportService(auth()->user());
        $result = $importService->runSmartImport();
        
        return response()->json($result);

    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        // ✅ CAPTURA ESPECÍFICA para o erro 'firstOrFail'
        Log::error("[Controller] Falha ao iniciar a importação: Chave de API da Binance não encontrada para o usuário " . auth()->id());
        return response()->json(['error' => 'Chave de API da Binance não encontrada. Por favor, cadastre uma chave de API válida antes de importar.'], 404);

    } catch (Exception $e) {
        // Captura genérica para outros erros
        Log::error("[Controller] Falha crítica no handleBinanceImport: " . $e->getMessage());
        return response()->json(['error' => $e->getMessage()], 400);
    }
}

    
    public function importCsv(Request $request)
{
    $validated = $request->validate([
        'file' => 'required|file|mimes:csv,txt,xlsx|max:10240',
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

    $uploadedFile = $request->file('file');
    $extension = strtolower($uploadedFile->getClientOriginalExtension());
    [$headers, $rows] = $this->extractRowsFromImportedFile($uploadedFile->getRealPath(), $extension);

    $imported = 0;

    $skipDuplicates = (bool) ($validated['skip_duplicates'] ?? false);

    foreach ($rows as $row) {
        $data = array_combine($headers, $row);
        if (!$data || !array_filter($data, fn($value) => !is_null($value) && trim((string)$value) !== '')) {
            continue;
        }

        $transactionData = $this->mapImportedRowToTransactionData($data, $validated['format'], $sourceModel, (int)$source->id);
        if ($transactionData === null) {
            continue;
        }

        if ($skipDuplicates && $this->transactionAlreadyExists($transactionData)) {
            continue;
        }

        Transaction::create($transactionData);
        $imported++;
    }

    return redirect()->route('transactions.index')
        ->with('success', "{$imported} transações importadas com sucesso.");
}

private function mapImportedRowToTransactionData(array $data, string $format, string $sourceModel, int $sourceId): ?array
{
    if ($format === 'binance') {
        $mapped = $this->mapBinanceRowToTransactionData($data, $sourceModel, $sourceId);
        if ($mapped === null) {
            return null;
        }

        return $this->enrichTransactionFiatValues($mapped);
    }

    $mapped = [
        'user_id' => auth()->id(),
        'source_type' => $sourceModel,
        'source_id' => $sourceId,
        'from_asset' => $data['from_asset'] ?? null,
        'to_asset' => $data['to_asset'] ?? null,
        'from_amount' => $this->parseNumeric($data['from_amount'] ?? null),
        'to_amount' => $this->parseNumeric($data['to_amount'] ?? null),
        'price' => $this->parseNumeric($data['price'] ?? null),
        'total_usdt' => $this->parseNumeric($data['total_usdt'] ?? null),
        'total_brl' => $this->parseNumeric($data['total_brl'] ?? null),
        'type' => $data['type'] ?? 'other',
        'operation' => $data['operation'] ?? null,
        'txid' => $data['txid'] ?? null,
        'reference' => $data['reference'] ?? null,
        'date' => isset($data['date']) ? \Carbon\Carbon::parse($data['date']) : now(),
    ];

    return $this->enrichTransactionFiatValues($mapped);
}

private function mapBinanceRowToTransactionData(array $data, string $sourceModel, int $sourceId): ?array
{
    $normalized = [];
    foreach ($data as $key => $value) {
        $normKey = Str::of((string)$key)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
        $normalized[$normKey] = $value;
    }

    // Layout do relatório anual CSV da Binance
    if (isset($normalized['sent_amount']) && isset($normalized['received_amount'])) {
        $fromAmount = $this->parseNumeric($normalized['sent_amount'] ?? null);
        $toAmount = $this->parseNumeric($normalized['received_amount'] ?? null);
        $fromAsset = strtoupper(trim((string)($normalized['sent_currency'] ?? '')));
        $toAsset = strtoupper(trim((string)($normalized['received_currency'] ?? '')));
        $marketType = strtoupper(trim((string)($normalized['market_model_type'] ?? '')));
        $eventType = strtoupper(trim((string)($normalized['type'] ?? 'TRADE')));
        $dateRawAnnual = $normalized['datetime_tz_brt']
            ?? $normalized['datetime_tz_gmt_03_00']
            ?? $normalized['datetime']
            ?? $normalized['date']
            ?? null;
        $referenceId = $normalized['id'] ?? null;

        if (!$fromAsset || !$toAsset || !$fromAmount || !$toAmount) {
            return null;
        }

        $totalBrlAnnual = $this->parseNumeric($normalized['sent_value_brl'] ?? null)
            ?? $this->parseNumeric($normalized['received_value_brl'] ?? null);

        $stablecoins = ['USDT', 'USDC', 'BUSD', 'TUSD', 'FDUSD'];
        $totalUsdtAnnual = null;
        if (in_array($fromAsset, $stablecoins, true)) {
            $totalUsdtAnnual = $fromAmount;
        } elseif (in_array($toAsset, $stablecoins, true)) {
            $totalUsdtAnnual = $toAmount;
        }

        $priceAnnual = $this->deriveAnnualUnitPrice(
            $fromAsset,
            $toAsset,
            $fromAmount,
            $toAmount
        );

        return [
            'user_id' => auth()->id(),
            'source_type' => $sourceModel,
            'source_id' => $sourceId,
            'from_asset' => $fromAsset,
            'to_asset' => $toAsset,
            'from_amount' => $fromAmount,
            'to_amount' => $toAmount,
            'price' => $priceAnnual,
            'total_usdt' => $totalUsdtAnnual,
            'total_brl' => $totalBrlAnnual,
            'type' => $eventType === 'TRADE' ? ($marketType === 'CONVERT' ? 'convert' : 'trade') : strtolower($eventType),
            'operation' => strtolower($marketType ?: $eventType),
            'txid' => $referenceId,
            'reference' => $referenceId,
            'date' => $this->parseBinanceDateValue($dateRawAnnual),
        ];
    }

    $pair = strtoupper((string)($normalized['pair'] ?? $normalized['symbol'] ?? ''));
    $side = strtoupper((string)($normalized['side'] ?? $normalized['tipo'] ?? ''));
    $price = $this->parseNumeric($normalized['price'] ?? $normalized['preco'] ?? null);
    $executed = $this->parseNumeric($normalized['executed'] ?? $normalized['filled'] ?? $normalized['executado'] ?? null);
    $amount = $this->parseNumeric($normalized['amount'] ?? $normalized['quantity'] ?? $normalized['quantidade'] ?? null);
    $total = $this->parseNumeric($normalized['total'] ?? null);
    $sellRaw = $normalized['sell'] ?? null;
    $buyRaw = $normalized['buy'] ?? null;
    $dateRaw = $normalized['date_utc'] ?? $normalized['date'] ?? $normalized['data'] ?? null;

    // Suporte ao layout "Convert/Instant" da Binance: colunas Sell/Buy (ex: "25.2 BNX")
    if ($sellRaw && $buyRaw) {
        [$fromAmount, $fromAsset] = $this->parseAmountAssetCell((string)$sellRaw);
        [$toAmount, $toAsset] = $this->parseAmountAssetCell((string)$buyRaw);

        if (!$fromAsset || !$toAsset || !$fromAmount || !$toAmount) {
            return null;
        }

        $derivedPair = $pair ?: ($toAsset . $fromAsset);
        [, $quoteAsset] = $this->splitTradingPair($derivedPair);

        return [
            'user_id' => auth()->id(),
            'source_type' => $sourceModel,
            'source_id' => $sourceId,
            'from_asset' => $fromAsset,
            'to_asset' => $toAsset,
            'from_amount' => $fromAmount,
            'to_amount' => $toAmount,
            'price' => $price,
            'total_usdt' => in_array($fromAsset, ['USDT', 'FDUSD', 'USDC', 'BUSD', 'TUSD'], true) ? $fromAmount : (in_array($toAsset, ['USDT', 'FDUSD', 'USDC', 'BUSD', 'TUSD'], true) ? $toAmount : null),
            'total_brl' => $fromAsset === 'BRL' ? $fromAmount : ($toAsset === 'BRL' ? $toAmount : null),
            'type' => 'convert',
            'operation' => strtolower((string)($normalized['type'] ?? 'convert')),
            'txid' => $normalized['id'] ?? null,
            'reference' => $normalized['id'] ?? null,
            'date' => $this->parseBinanceDateValue($dateRaw),
        ];
    }

    if (!$pair || !$side) {
        return null;
    }

    [$baseAsset, $quoteAsset] = $this->splitTradingPair($pair);
    if (!$baseAsset || !$quoteAsset) {
        return null;
    }

    $qty = $executed ?? $amount ?? 0.0;
    if ($qty <= 0) {
        return null;
    }

    $quoteTotal = $total ?? (($price ?? 0) * $qty);
    $isBuy = $side === 'BUY' || $side === 'COMPRA';

    return [
        'user_id' => auth()->id(),
        'source_type' => $sourceModel,
        'source_id' => $sourceId,
        'from_asset' => $isBuy ? $quoteAsset : $baseAsset,
        'to_asset' => $isBuy ? $baseAsset : $quoteAsset,
        'from_amount' => $isBuy ? $quoteTotal : $qty,
        'to_amount' => $isBuy ? $qty : $quoteTotal,
        'price' => $price,
        'total_usdt' => in_array($quoteAsset, ['USDT', 'FDUSD', 'USDC', 'BUSD', 'TUSD'], true) ? $quoteTotal : null,
        'total_brl' => $quoteAsset === 'BRL' ? $quoteTotal : null,
        'type' => 'trade',
        'operation' => strtolower($side),
        'txid' => $normalized['order_no'] ?? $normalized['ordem'] ?? null,
        'reference' => $normalized['order_no'] ?? $normalized['id'] ?? null,
        'date' => $this->parseBinanceDateValue($dateRaw),
    ];
}

private function deriveAnnualUnitPrice(string $fromAsset, string $toAsset, float $fromAmount, float $toAmount): float
{
    if ($fromAmount <= 0 || $toAmount <= 0) {
        return 0.0;
    }

    $stableOrFiat = ['USDT', 'USDC', 'BUSD', 'TUSD', 'FDUSD', 'BRL', 'USD', 'EUR'];
    $fromIsStable = in_array($fromAsset, $stableOrFiat, true);
    $toIsStable = in_array($toAsset, $stableOrFiat, true);

    // Compra de cripto com stable/fiat: preço = quanto pagou / quantidade recebida
    if ($fromIsStable && !$toIsStable) {
        return $fromAmount / $toAmount;
    }

    // Venda de cripto para stable/fiat: preço = quanto recebeu / quantidade vendida
    if (!$fromIsStable && $toIsStable) {
        return $toAmount / $fromAmount;
    }

    // Cripto-cripto: fallback simétrico atual
    return $fromAmount / $toAmount;
}

private function parseAmountAssetCell(string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return [null, null];
    }

    if (preg_match('/^([0-9\.,]+)\s+([A-Za-z0-9]+)$/', $value, $m)) {
        return [$this->parseNumeric($m[1]), strtoupper($m[2])];
    }

    return [null, null];
}

private function parseBinanceDateValue($dateRaw): Carbon
{
    if (!$dateRaw) {
        return now();
    }

    $dateString = trim((string)$dateRaw);
    $formats = [
        'Y-m-d-H:i:s', // ex: 2022-06-13-09:02:07 (datetime_tz_BRT)
        'Y-m-d H:i:s', // ex: 2025-02-28 20:19:58
        'Y-m-d\TH:i:sP',
    ];

    foreach ($formats as $format) {
        try {
            return Carbon::createFromFormat($format, $dateString);
        } catch (\Throwable $e) {
            // tenta próximo formato
        }
    }

    return Carbon::parse($dateString);
}

private function enrichTransactionFiatValues(array $tx): array
{
    $date = $tx['date'] instanceof Carbon ? $tx['date'] : Carbon::parse($tx['date']);
    $fromAsset = strtoupper((string)($tx['from_asset'] ?? ''));
    $toAsset = strtoupper((string)($tx['to_asset'] ?? ''));
    $fromAmount = (float)($tx['from_amount'] ?? 0);
    $toAmount = (float)($tx['to_amount'] ?? 0);
    $price = $tx['price'] ?? null;
    $totalUsdt = $tx['total_usdt'] ?? null;
    $totalBrl = $tx['total_brl'] ?? null;

    // Se já veio totalmente preenchido, mantém.
    if (($price ?? 0) > 0 && ($totalUsdt ?? 0) > 0 && ($totalBrl ?? 0) > 0) {
        return $tx;
    }

    $stablecoins = ['USDT', 'USDC', 'BUSD', 'TUSD', 'FDUSD'];
    $priceService = app(CryptoPriceService::class);

    // 1) Determina total em USD/USDT preferindo a perna fiat/stable.
    if (($totalUsdt ?? 0) <= 0) {
        if (in_array($fromAsset, $stablecoins, true) && $fromAmount > 0) {
            $totalUsdt = $fromAmount;
        } elseif (in_array($toAsset, $stablecoins, true) && $toAmount > 0) {
            $totalUsdt = $toAmount;
        } else {
            // 2) Cripto-cripto puro: usa preço histórico da perna "to" e fallback na "from".
            if ($toAsset && $toAmount > 0) {
                $toPrices = $priceService->getOrCreatePrice($toAsset, $date);
                if (($toPrices->price_usd ?? 0) > 0) {
                    $totalUsdt = $toAmount * (float)$toPrices->price_usd;
                }
            }

            if (($totalUsdt ?? 0) <= 0 && $fromAsset && $fromAmount > 0) {
                $fromPrices = $priceService->getOrCreatePrice($fromAsset, $date);
                if (($fromPrices->price_usd ?? 0) > 0) {
                    $totalUsdt = $fromAmount * (float)$fromPrices->price_usd;
                }
            }
        }
    }

    // 3) Converte USD/USDT para BRL.
    if (($totalBrl ?? 0) <= 0 && ($totalUsdt ?? 0) > 0) {
        $usdBrl = $priceService->getOrCreatePrice('USDT', $date);
        if (($usdBrl->price_brl ?? 0) > 0) {
            $totalBrl = (float)$totalUsdt * (float)$usdBrl->price_brl;
        }
    }

    // 4) Preço unitário (por unidade do ativo de entrada/saída) se não veio no arquivo.
    if (($price ?? 0) <= 0) {
        if ($toAmount > 0 && ($totalUsdt ?? 0) > 0) {
            $price = (float)$totalUsdt / $toAmount;
        } elseif ($fromAmount > 0 && ($totalUsdt ?? 0) > 0) {
            $price = (float)$totalUsdt / $fromAmount;
        } else {
            $price = 0;
        }
    }

    $tx['price'] = $price;
    $tx['total_usdt'] = $totalUsdt;
    $tx['total_brl'] = $totalBrl;

    return $tx;
}

private function splitTradingPair(string $pair): array
{
    $quoteAssets = ['USDT', 'FDUSD', 'USDC', 'BUSD', 'TUSD', 'BRL', 'BTC', 'ETH', 'BNB', 'EUR', 'TRY'];
    foreach ($quoteAssets as $quote) {
        if (str_ends_with($pair, $quote) && strlen($pair) > strlen($quote)) {
            return [substr($pair, 0, -strlen($quote)), $quote];
        }
    }

    return [null, null];
}

private function parseNumeric($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    $normalized = str_replace([' ', ','], ['', '.'], (string)$value);
    return is_numeric($normalized) ? (float)$normalized : null;
}

private function transactionAlreadyExists(array $transactionData): bool
{
    // ── Estratégia 1: deduplicação cross-source por reference ──────────────────
    //
    // Quando a transação tem um identificador único da exchange, verificamos
    // APENAS por user_id + reference, IGNORANDO source_type e source_id.
    //
    // Isso evita duplicatas quando o usuário importa via API automática e
    // depois importa o mesmo período via CSV (ou vice-versa): ambas as fontes
    // produzem o mesmo reference para a mesma operação na exchange.
    if (!empty($transactionData['reference'])) {
        return Transaction::query()
            ->where('user_id', $transactionData['user_id'])
            ->where('reference', $transactionData['reference'])
            ->exists();
    }

    // ── Estratégia 2: deduplicação por conteúdo (sem reference) ────────────────
    //
    // Para transações sem identificador único (manuais ou formatos sem ID),
    // mantemos source_type + source_id para evitar falsos positivos entre
    // exchanges diferentes com mesmos valores no mesmo instante.
    $query = Transaction::query()
        ->where('user_id', $transactionData['user_id'])
        ->where('source_type', $transactionData['source_type'])
        ->where('source_id', $transactionData['source_id'])
        ->where('type', $transactionData['type'])
        ->where('from_asset', $transactionData['from_asset'])
        ->where('to_asset', $transactionData['to_asset'])
        ->where('date', $transactionData['date']);

    if (isset($transactionData['from_amount'])) {
        $query->where('from_amount', $transactionData['from_amount']);
    }

    if (isset($transactionData['to_amount'])) {
        $query->where('to_amount', $transactionData['to_amount']);
    }

    return $query->exists();
}

private function extractRowsFromImportedFile(string $filePath, string $extension): array
{
    if (in_array($extension, ['csv', 'txt'], true)) {
        $csv = array_map('str_getcsv', file($filePath));
        if (empty($csv)) {
            throw new \RuntimeException('Arquivo CSV vazio.');
        }

        $headers = array_map('trim', $csv[0]);
        $rows = array_slice($csv, 1);

        return [$headers, $rows];
    }

    if ($extension === 'xlsx') {
        $reader = new XlsxReader();
        $reader->open($filePath);

        try {
            $sheetRows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $sheetRows[] = $row->toArray();
                }
                break; // Usa apenas a primeira aba.
            }

            if (empty($sheetRows)) {
                throw new \RuntimeException('Arquivo XLSX vazio.');
            }

            $headers = array_map(static fn ($value) => trim((string) $value), $sheetRows[0] ?? []);
            $rows = array_slice($sheetRows, 1);

            return [$headers, $rows];
        } finally {
            $reader->close();
        }
    }

    throw new \RuntimeException('Formato de arquivo não suportado.');
}

    // ===================================================================
    // LÓGICA FISCAL (MANTER POR ENQUANTO)
    // ===================================================================
   public function rebuildBalancesBackward(Request $request)
{
    $apiKey = UserApiKey::where('user_id', auth()->id())
        ->whereHas('exchange', fn($q) => $q->where('name', 'binance'))
        ->first();

    if (!$apiKey) {
        return response()->json(['error' => 'Chave Binance não encontrada.'], 404);
    }

    // Inicializa o serviço de conversão se não estiver inicializado
    if (!isset($this->convertService)) {
        $this->convertService = new BinanceConvertService($apiKey);
    }

    Log::info('[Reconstrução Fiscal] Iniciando reconstrução reversa mês a mês.');

    $balances = $this->getCurrentBalances($apiKey);
    $currentDate = Carbon::now()->startOfMonth();
    $maxMonths = 60; // Limite de 5 anos
    $iteration = 0;

    while (!empty($balances) && $iteration < $maxMonths) {
        $monthStart = $currentDate->copy()->subMonth()->startOfMonth();
        $monthEnd   = $currentDate->copy()->subMonth()->endOfMonth();

        Log::info("[Reconstrução Fiscal] Mês alvo: {$monthStart->format('Y-m')} — Ativos: " . implode(', ', array_keys($balances)));

        foreach (array_keys($balances) as $asset) {
            $trades = $this->getAssetTradesByMonth($apiKey, $asset, $monthStart, $monthEnd);
            $converts = $this->convertService->getConvertHistory($monthStart->getTimestampMs(), $monthEnd->getTimestampMs());

            foreach (array_merge($trades, $converts) as $tx) {
                $qty = (float)($tx['qty'] ?? $tx['toAmount'] ?? 0);
                $isBuyer = $tx['isBuyer'] ?? ($tx['side'] ?? 'BUY') === 'BUY';

                if ($isBuyer) $balances[$asset] -= $qty;
                else $balances[$asset] += $qty;

                if ($balances[$asset] <= 0.0001) unset($balances[$asset]);
            }
        }

        Log::info("[Reconstrução Fiscal] Após {$monthStart->format('Y-m')}, saldos remanescentes:", $balances);

        $currentDate->subMonth();
        $iteration++;
    }

    Log::info('[Reconstrução Fiscal] Finalizado com sucesso.', [
        'total_meses' => $iteration,
        'ativos_restantes' => array_keys($balances),
    ]);

    return response()->json([
        'success' => true,
        'months_processed' => $iteration,
        'remaining_balances' => $balances
    ]);
}

private function getCurrentBalances(UserApiKey $apiKey): array
{
    $params = [
        'timestamp'  => round(microtime(true) * 1000),
        'recvWindow' => 15000,
    ];
    $params['signature'] = hash_hmac('sha256', http_build_query($params ), $apiKey->secret_key);

    $response = Http::withHeaders(['X-MBX-APIKEY' => $apiKey->api_key])
        ->get('https://api.binance.com/api/v3/account', $params );

    if (!$response->successful()) {
        Log::error('[Reconstrução] Erro ao obter saldos.', [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);
        return [];
    }

    return collect($response->json('balances') ?? [])
        ->filter(fn($b) => (float)$b['free'] > 0 || (float)$b['locked'] > 0)
        ->mapWithKeys(fn($b) => [$b['asset'] => (float)$b['free'] + (float)$b['locked']])
        ->toArray();
}

private function getAssetTradesByMonth(UserApiKey $apiKey, string $asset, Carbon $monthStart, Carbon $monthEnd): array
{
    $quoteAssets = ['USDT', 'BTC', 'BUSD', 'BRL'];
    $baseUrl = 'https://api.binance.com/api/v3/myTrades';
    $trades = [];

    foreach ($quoteAssets as $quote ) {
        if ($asset === $quote) continue;

        $symbol = "{$asset}{$quote}";
        $params = [
            'symbol'     => $symbol,
            'limit'      => 1000,
            'startTime'  => $monthStart->getTimestampMs(),
            'endTime'    => $monthEnd->getTimestampMs(),
            'timestamp'  => round(microtime(true) * 1000),
            'recvWindow' => 15000,
        ];
        $params['signature'] = hash_hmac('sha256', http_build_query($params ), $apiKey->secret_key);

        $response = Http::withHeaders(['X-MBX-APIKEY' => $apiKey->api_key])
            ->get($baseUrl, $params);

        if ($response->successful() && !empty($response->json())) {
            $trades = array_merge($trades, $response->json());
        }

        usleep(200000); // evita rate limit
    }

    return $trades;
}
}
