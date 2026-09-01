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
use App\Models\ImportSession;
use App\Services\FifoCalculatorService;
use App\Services\BinanceImportService; // ✅ Importa o novo serviço
use App\Services\CryptoPriceService;
use App\Services\TransactionImportCoverageService;
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

    $query->when($filters['crypto_asset_id'] ?? null, function ($q, $cryptoAssetId) {
        $symbol = CryptoAsset::query()->whereKey($cryptoAssetId)->value('symbol');

        if ($symbol) {
            $normalizedSymbol = Str::upper(trim($symbol));

            $q->where(function ($assetQuery) use ($normalizedSymbol) {
                $assetQuery
                    ->whereRaw('UPPER(from_asset) = ?', [$normalizedSymbol])
                    ->orWhereRaw('UPPER(to_asset) = ?', [$normalizedSymbol]);
            });
        }
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

        $fromAmount = (float) ($transaction->from_amount ?? 0);
        $toAmount = (float) ($transaction->to_amount ?? 0);
        $hasConversionRate = $fromAmount > 0 && $toAmount > 0
            && !empty($transaction->from_asset) && !empty($transaction->to_asset);

        // `price` pode ser uma taxa entre criptoativos, especialmente em Convert.
        // O contrato de apresentação deixa a unidade explícita e não a trata como USD.
        $transaction->presentation = [
            'from' => [
                'label' => 'Enviado',
                'amount' => $fromAmount,
                'asset' => $transaction->from_asset,
            ],
            'to' => [
                'label' => 'Recebido',
                'amount' => $toAmount,
                'asset' => $transaction->to_asset,
            ],
            'effective_rate' => $transaction->effective_conversion_rate,
            'brl_value_available' => (float) ($transaction->total_brl ?? 0) > 0,
            'usdt_value_available' => (float) ($transaction->total_usdt ?? 0) > 0,
        ];

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

            // O recálculo moderno reconstitui todos os lotes em ordem cronológica
            // e registra lacunas de histórico quando uma saída não tiver custo suficiente.
            app(FifoCalculatorService::class)->recalculateForUser(
                $transaction->user_id,
                (int) Carbon::parse($transaction->date)->year,
            );

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

            // Reconstitui o FIFO pelo caminho moderno após qualquer alteração,
            // inclusive para resolver ou abrir pendências de histórico de aquisição.
            app(FifoCalculatorService::class)->recalculateForUser(
                $transaction->user_id,
                (int) Carbon::parse($transaction->date)->year,
            );
            
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



    /**
     * Retorna a prévia de uma exclusão por competência sem modificar dados.
     * A resposta é usada pela interface para apresentar a confirmação correta.
     */
    public function previewDestroyPeriod(Request $request): \Illuminate\Http\JsonResponse
    {
        $period = $this->validatedDeletionPeriod($request);
        $transactions = $this->periodTransactionsQuery(auth()->id(), $period['year'], $period['month']);
        $count = (clone $transactions)->count();

        $dateFrom = (clone $transactions)->min('date');
        $dateTo = (clone $transactions)->max('date');

        return response()->json([
            'year' => $period['year'],
            'month' => $period['month'],
            'period_label' => $this->deletionPeriodLabel($period['year'], $period['month']),
            'transactions_count' => $count,
            'total_brl' => round((float) ((clone $transactions)->sum('total_brl') ?? 0), 2),
            'date_from' => $dateFrom ? Carbon::parse($dateFrom)->toDateString() : null,
            'date_to' => $dateTo ? Carbon::parse($dateTo)->toDateString() : null,
            'types' => (clone $transactions)
                ->selectRaw('type, COUNT(*) as count')
                ->groupBy('type')
                ->orderBy('type')
                ->pluck('count', 'type'),
            'confirmation_phrase' => 'EXCLUIR ' . $this->deletionPeriodLabel($period['year'], $period['month']),
        ]);
    }

    /**
     * Exclui somente as transações do usuário autenticado no ano selecionado,
     * podendo restringir a operação a um mês. O recálculo FIFO reconstitui os
     * resultados fiscais a partir da competência afetada.
     */
    public function destroyPeriod(Request $request)
    {
        $period = $this->validatedDeletionPeriod($request);
        $expectedConfirmation = 'EXCLUIR ' . $this->deletionPeriodLabel($period['year'], $period['month']);
        $receivedConfirmation = strtoupper(trim((string) $request->input('confirmation')));

        if (!hash_equals($expectedConfirmation, $receivedConfirmation)) {
            return back()->withErrors([
                'confirmation' => "Confirmação inválida. Digite exatamente: {$expectedConfirmation}",
            ]);
        }

        $userId = auth()->id();
        $transactions = $this->periodTransactionsQuery($userId, $period['year'], $period['month']);
        $count = (clone $transactions)->count();

        if ($count === 0) {
            return back()->with('warning', 'Não há transações no período selecionado para excluir.');
        }

        DB::transaction(function () use ($transactions) {
            $transactions->delete();
        });

        try {
            $recalculation = app(FifoCalculatorService::class)->recalculateForUser($userId, $period['year']);
            $message = "{$count} transação(ões) de {$this->deletionPeriodLabel($period['year'], $period['month'])} foram excluídas e o FIFO foi recalculado.";

            return back()->with('success', $message)->with('fifo_recalculation', $recalculation);
        } catch (\Throwable $exception) {
            Log::error('[Exclusão por período] Transações removidas, mas o recálculo FIFO falhou.', [
                'user_id' => $userId,
                'year' => $period['year'],
                'month' => $period['month'],
                'error' => $exception->getMessage(),
            ]);

            return back()->with('warning', "{$count} transação(ões) foram excluídas, mas o recálculo FIFO falhou. Execute o recálculo fiscal antes de gerar relatórios.");
        }
    }

    private function validatedDeletionPeriod(Request $request): array
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2009', 'max:' . now()->year],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ]);

        return [
            'year' => (int) $data['year'],
            'month' => array_key_exists('month', $data) && $data['month'] !== null ? (int) $data['month'] : null,
        ];
    }

    private function periodTransactionsQuery(int $userId, int $year, ?int $month)
    {
        return Transaction::query()
            ->where('user_id', $userId)
            ->whereYear('date', $year)
            ->when($month !== null, fn ($query) => $query->whereMonth('date', $month));
    }

    private function deletionPeriodLabel(int $year, ?int $month): string
    {
        return $month === null
            ? (string) $year
            : str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '/' . $year;
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
        $validated = $request->validate([
            'api_key_id' => ['required', 'integer'],
            'year' => ['required', 'integer', 'min:2009', 'max:' . now('America/Sao_Paulo')->year],
        ]);

        if (strtolower($exchange) !== 'binance') {
            return back()->withErrors(['exchange' => 'A exchange selecionada ainda não é suportada.']);
        }

        $apiKey = UserApiKey::query()
            ->whereKey($validated['api_key_id'])
            ->where('user_id', $request->user()->id)
            ->whereHas('exchange', fn ($query) => $query->where('name', 'binance'))
            ->firstOrFail();

        $existingSession = $this->latestBinanceImportSession(
            $request->user()->id,
            $apiKey->id,
            (int) $validated['year'],
        );

        if ($existingSession?->isInProgress()) {
            return back()->with('warning', "A sincronização Binance de {$validated['year']} já está em andamento.");
        }

        $session = ImportSession::query()->create([
            'user_id' => $request->user()->id,
            'type' => 'exchange_sync',
            'source' => 'binance',
            'status' => 'pending',
            'settings' => [
                'api_key_id' => $apiKey->id,
                'exchange_id' => $apiKey->exchange_id,
                'year' => (int) $validated['year'],
            ],
            'progress_percentage' => 0,
        ]);

        ProcessBinanceImport::dispatch($request->user(), $apiKey->id, (int) $validated['year'], $session->id);

        return back()->with('success', "Sincronização Binance de {$validated['year']} iniciada. O andamento será atualizado automaticamente nesta tela.");
    }

    public function importCoverage(Request $request, TransactionImportCoverageService $coverageService)
    {
        $validated = $request->validate([
            'api_key_id' => ['required', 'integer'],
            'year' => ['required', 'integer', 'min:2009', 'max:' . now('America/Sao_Paulo')->year],
        ]);

        $apiKey = UserApiKey::query()
            ->whereKey($validated['api_key_id'])
            ->where('user_id', $request->user()->id)
            ->whereHas('exchange', fn ($query) => $query->where('name', 'binance'))
            ->firstOrFail();

        try {
            return response()->json(
                $coverageService->forYear($request->user(), $apiKey->exchange_id, (int) $validated['year'])
            );
        } catch (\Illuminate\Database\QueryException $exception) {
            if (str_contains($exception->getMessage(), 'transaction_import_coverages')) {
                return response()->json([
                    'message' => 'A estrutura de cobertura ainda não foi criada neste ambiente. Execute “php artisan migrate” e tente novamente.',
                ], 409);
            }

            throw $exception;
        }
    }

    /**
     * Retorna o último job de sincronização da chave e do ano selecionados.
     * A interface consulta este endpoint em intervalos curtos enquanto o job está ativo.
     */
    public function importStatus(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'api_key_id' => ['required', 'integer'],
            'year' => ['required', 'integer', 'min:2009', 'max:' . now('America/Sao_Paulo')->year],
        ]);

        $apiKey = UserApiKey::query()
            ->whereKey($validated['api_key_id'])
            ->where('user_id', $request->user()->id)
            ->whereHas('exchange', fn ($query) => $query->where('name', 'binance'))
            ->firstOrFail();

        try {
            $session = $this->latestBinanceImportSession($request->user()->id, $apiKey->id, (int) $validated['year']);
        } catch (\Illuminate\Database\QueryException $exception) {
            if (str_contains($exception->getMessage(), 'import_sessions')) {
                return response()->json([
                    'message' => 'O acompanhamento de sincronização ainda não foi criado neste ambiente. Execute “php artisan migrate” e tente novamente.',
                ], 409);
            }

            throw $exception;
        }

        return response()->json([
            'session' => $session ? $this->serializeImportSession($session) : null,
        ]);
    }

    private function latestBinanceImportSession(int $userId, int $apiKeyId, int $year): ?ImportSession
    {
        return ImportSession::query()
            ->where('user_id', $userId)
            ->where('type', 'exchange_sync')
            ->where('source', 'binance')
            ->latest('id')
            ->get()
            ->first(function (ImportSession $session) use ($apiKeyId, $year) {
                return (int) data_get($session->settings, 'api_key_id') === $apiKeyId
                    && (int) data_get($session->settings, 'year') === $year;
            });
    }

    private function serializeImportSession(ImportSession $session): array
    {
        return [
            'id' => $session->id,
            'status' => $session->status,
            'progress_percentage' => (float) $session->progress_percentage,
            'started_at' => $session->started_at?->toIso8601String(),
            'completed_at' => $session->completed_at?->toIso8601String(),
            'transactions_imported' => (int) $session->successful_rows,
            'result' => data_get($session->settings, 'result'),
            'pricing' => data_get($session->settings, 'pricing', []),
            'error' => data_get($session->errors, 'message'),
        ];
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
        'coverage_year' => 'nullable|integer|min:2009|max:' . now('America/Sao_Paulo')->year,
        'coverage_month' => 'nullable|integer|min:1|max:12',
        'report_type' => 'nullable|in:spot_trade,convert,deposit,withdrawal,asset_dividend,earn_staking,fiat,other',
    ]);

    $sourceModel = match ($validated['source_type']) {
        'exchange' => \App\Models\UserApiKey::class,
        'wallet'   => \App\Models\Wallet::class,
    };

    $source = $sourceModel::where('user_id', auth()->id())->findOrFail($validated['source_id']);
    $isBinanceExchangeImport = $source instanceof UserApiKey
        && strtolower((string) optional($source->exchange)->name) === 'binance';

    $isCurrentYearBinanceImport = $isBinanceExchangeImport
        && (int) ($validated['coverage_year'] ?? 0) === now('America/Sao_Paulo')->year;

    if ($isBinanceExchangeImport && (!isset($validated['coverage_year'], $validated['coverage_month']))) {
        return back()->withErrors([
            'coverage_year' => 'Para relatórios Binance, informe o ano e a competência inicial do arquivo.',
        ]);
    }

    if ($isCurrentYearBinanceImport && !isset($validated['report_type'])) {
        return back()->withErrors([
            'report_type' => 'No ano corrente, selecione o tipo solicitado pela análise de cobertura.',
        ]);
    }

    $uploadedFile = $request->file('file');
    $extension = strtolower($uploadedFile->getClientOriginalExtension());
    [$headers, $rows] = $this->extractRowsFromImportedFile($uploadedFile->getRealPath(), $extension);

    $imported = 0;
    $recognizedRows = 0;

    $skipDuplicates = (bool) ($validated['skip_duplicates'] ?? true);

    /** @var array<int, true> $coveredMonths */
    $coveredMonths = [];
    /** @var array<int, array<string, true>> $detectedReportTypesByMonth */
    $detectedReportTypesByMonth = [];

    foreach ($rows as $rowIndex => $row) {
        $data = $this->combineImportedRow($headers, $row, $rowIndex + 2);
        if ($data === null || !array_filter($data, fn($value) => !is_null($value) && trim((string)$value) !== '')) {
            continue;
        }

        $transactionData = $this->mapImportedRowToTransactionData($data, $validated['format'], $sourceModel, (int)$source->id);
        if ($transactionData === null) {
            continue;
        }

        $recognizedRows++;

        if ($isBinanceExchangeImport) {
            $transactionDate = Carbon::parse($transactionData['date'], 'America/Sao_Paulo');
            if ($transactionDate->year === (int) $validated['coverage_year']) {
                $coveredMonths[$transactionDate->month] = true;
                $transactionType = strtolower((string) ($transactionData['type'] ?? ''));
                $detectedReportType = match ($transactionType) {
                    'trade' => 'spot_trade',
                    'convert', 'swap' => 'convert',
                    'deposit', 'receive' => 'deposit',
                    'withdrawal', 'withdraw', 'send' => 'withdrawal',
                    'earn', 'staking', 'reward', 'airdrop', 'mining' => 'earn_staking',
                    'buy', 'sell', 'fiat_buy', 'fiat_sell' => 'fiat',
                    default => 'other',
                };
                $detectedReportTypesByMonth[$transactionDate->month][$detectedReportType] = true;
            }
        }

        if ($skipDuplicates && $this->transactionAlreadyExists($transactionData)) {
            continue;
        }

        Transaction::create($transactionData);
        $imported++;
    }

    if ($isBinanceExchangeImport && $recognizedRows > 0) {
        $coverageService = app(TransactionImportCoverageService::class);
        $monthsToConfirm = array_keys($coveredMonths);

        // Mantém compatibilidade com formatos cujo mapeamento não traz data,
        // sem confirmar um arquivo vazio ou não reconhecido.
        if (empty($monthsToConfirm)) {
            $monthsToConfirm = [(int) $validated['coverage_month']];
        }

        foreach ($monthsToConfirm as $month) {
            $reportTypesToConfirm = isset($validated['report_type'])
                ? [$validated['report_type']]
                : array_keys($detectedReportTypesByMonth[$month] ?? ['other' => true]);

            foreach ($reportTypesToConfirm as $reportType) {
                $coverageService->recordCsvCoverage(
                    $request->user(),
                    $source->exchange_id,
                    (int) $validated['coverage_year'],
                    (int) $month,
                    $reportType,
                    $recognizedRows,
                    $uploadedFile->getClientOriginalName(),
                );
            }
        }
    }

    $message = "{$imported} transações importadas com sucesso.";
    if ($isBinanceExchangeImport && count($coveredMonths) > 1) {
        $message .= ' A cobertura foi confirmada para ' . count($coveredMonths) . ' meses identificados no arquivo.';
    }

    return redirect()->route('transactions.index')
        ->with('success', $message);
}

private function mapImportedRowToTransactionData(array $data, string $format, string $sourceModel, int $sourceId): ?array
{
    if ($format === 'binance') {
        $mapped = $this->mapBinanceRowToTransactionData($data, $sourceModel, $sourceId);
        if ($mapped === null) {
            return null;
        }

        $preserveCsvBrl = (bool) ($mapped['_preserve_csv_brl'] ?? false);
        unset($mapped['_preserve_csv_brl']);

        // O relatório anual já contém a base fiscal em BRL. Nesse caso, não
        // substituímos o documento por cotação derivada ou consulta externa.
        return $preserveCsvBrl ? $mapped : $this->enrichTransactionFiatValues($mapped);
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

    // Layout do relatório anual CSV da Binance. As operações podem conter
    // duas pernas (trade/convert) ou uma única perna (entrada/saída).
    if ($this->isBinanceAnnualCsvRow($normalized)) {
        return $this->mapBinanceAnnualCsvRow($normalized, $sourceModel, $sourceId);
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

private function isBinanceAnnualCsvRow(array $row): bool
{
    return array_key_exists('datetime_tz_brt', $row)
        || array_key_exists('datetime_tz_gmt_03_00', $row)
        || (array_key_exists('sent_currency', $row) && array_key_exists('received_currency', $row));
}

private function mapBinanceAnnualCsvRow(array $row, string $sourceModel, int $sourceId): ?array
{
    $fromAmount = $this->parseNumeric($row['sent_amount'] ?? null);
    $toAmount = $this->parseNumeric($row['received_amount'] ?? null);
    $fromAsset = strtoupper(trim((string) ($row['sent_currency'] ?? '')));
    $toAsset = strtoupper(trim((string) ($row['received_currency'] ?? '')));
    $hasSent = $fromAsset !== '' && $fromAmount !== null && $fromAmount > 0;
    $hasReceived = $toAsset !== '' && $toAmount !== null && $toAmount > 0;
    $originalType = trim((string) ($row['type'] ?? ''));
    $normalizedType = $this->normalizeBinanceAnnualType($originalType, $row['market_model_type'] ?? null);

    if ($normalizedType === null) {
        return null;
    }

    $requiresBothSides = in_array($normalizedType, ['trade', 'buy', 'sell', 'convert', 'swap'], true);
    $isCredit = in_array($normalizedType, ['deposit', 'receive'], true);
    $isDebit = in_array($normalizedType, ['send', 'withdrawal', 'withdraw'], true);

    if (($requiresBothSides && (!$hasSent || !$hasReceived))
        || ($isCredit && !$hasReceived)
        || ($isDebit && !$hasSent)) {
        return null;
    }

    if ($isCredit) {
        $fromAsset = '';
        $fromAmount = null;
    }
    if ($isDebit) {
        $toAsset = '';
        $toAmount = null;
    }

    $sentValueBrl = $this->parseNumeric($row['sent_value_brl'] ?? null);
    $receivedValueBrl = $this->parseNumeric($row['received_value_brl'] ?? null);
    [$totalBrl, $brlValueSource] = $this->selectAnnualCsvBrlValue(
        $normalizedType,
        $sentValueBrl,
        $receivedValueBrl,
    );

    $stablecoins = ['USDT', 'USDC', 'BUSD', 'TUSD', 'FDUSD'];
    $totalUsdt = $hasSent && in_array($fromAsset, $stablecoins, true)
        ? $fromAmount
        : ($hasReceived && in_array($toAsset, $stablecoins, true) ? $toAmount : null);
    $isOneSided = $isCredit || $isDebit;
    $commission = $this->parseNumeric($row['fee_amount'] ?? null);
    $commissionAsset = strtoupper(trim((string) ($row['fee_currency'] ?? '')));
    $commissionValueBrl = $this->parseNumeric($row['fee_value_brl'] ?? null);
    $dateRaw = $row['datetime_tz_brt']
        ?? $row['datetime_tz_gmt_03_00']
        ?? $row['datetime']
        ?? $row['date']
        ?? null;

    return [
        'user_id' => auth()->id(),
        'source_type' => $sourceModel,
        'source_id' => $sourceId,
        'from_asset' => $fromAsset !== '' ? $fromAsset : null,
        'to_asset' => $toAsset !== '' ? $toAsset : null,
        'from_amount' => $fromAmount,
        'to_amount' => $toAmount,
        'price' => $requiresBothSides ? $this->deriveAnnualUnitPrice($fromAsset, $toAsset, (float) $fromAmount, (float) $toAmount) : null,
        'total_usdt' => $totalUsdt,
        'total_brl' => $totalBrl,
        'type' => $normalizedType === 'swap' ? 'convert' : $normalizedType,
        'operation' => strtolower(trim((string) ($row['market_model_type'] ?? $originalType))),
        'txid' => $row['id'] ?? null,
        'reference' => $row['id'] ?? null,
        'commission' => $commission,
        'commission_asset' => $commissionAsset !== '' ? $commissionAsset : null,
        'commission_value_brl' => $commissionValueBrl,
        'reconciliation_status' => $isOneSided ? 'pending_transfer_reconciliation' : null,
        'import_metadata' => [
            'format' => 'binance_annual_csv',
            'original_type' => $originalType,
            'market_model_type' => $row['market_model_type'] ?? null,
            'brl_values' => [
                'sent_value_brl' => $sentValueBrl,
                'received_value_brl' => $receivedValueBrl,
                'selected_source' => $brlValueSource,
            ],
            'one_sided' => $isOneSided,
            'fiscal_treatment' => $isOneSided ? 'pending_transfer_reconciliation' : 'standard_transaction',
        ],
        'date' => $this->parseBinanceDateValue($dateRaw, 'America/Sao_Paulo'),
        '_preserve_csv_brl' => $totalBrl !== null && $totalBrl > 0,
    ];
}

private function normalizeBinanceAnnualType(string $type, mixed $marketType): ?string
{
    $normalized = strtolower(trim(preg_replace('/\\s+/', ' ', $type) ?? ''));
    $market = strtolower(trim((string) $marketType));
    $aliases = [
        'trade' => 'trade',
        'buy' => 'buy',
        'sell' => 'sell',
        'convert' => 'convert',
        'swap' => 'swap',
        'deposit' => 'deposit',
        'receive' => 'receive',
        'received' => 'receive',
        'send' => 'send',
        'withdrawal' => 'withdrawal',
        'withdraw' => 'withdraw',
    ];

    $result = $aliases[$normalized] ?? null;

    return $result === 'trade' && $market === 'convert' ? 'convert' : $result;
}

/** @return array{0: ?float, 1: string|null} */
private function selectAnnualCsvBrlValue(string $type, ?float $sentValueBrl, ?float $receivedValueBrl): array
{
    $hasSentValue = $sentValueBrl !== null && $sentValueBrl > 0;
    $hasReceivedValue = $receivedValueBrl !== null && $receivedValueBrl > 0;

    if (in_array($type, ['deposit', 'receive'], true)) {
        return [$hasReceivedValue ? $receivedValueBrl : null, $hasReceivedValue ? 'received_value_brl' : null];
    }
    if (in_array($type, ['send', 'withdrawal', 'withdraw'], true)) {
        return [$hasSentValue ? $sentValueBrl : null, $hasSentValue ? 'sent_value_brl' : null];
    }

    // Em operações de duas pernas, a saída é a referência determinística.
    // A perna recebida é usada somente quando a saída não trouxe valor BRL.
    if ($hasSentValue) {
        return [$sentValueBrl, 'sent_value_brl'];
    }

    return [$hasReceivedValue ? $receivedValueBrl : null, $hasReceivedValue ? 'received_value_brl' : null];
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

private function parseBinanceDateValue($dateRaw, ?string $timezone = null): Carbon
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
            return Carbon::createFromFormat($format, $dateString, $timezone);
        } catch (\Throwable $e) {
            // tenta próximo formato
        }
    }

    return Carbon::parse($dateString, $timezone);
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
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Não foi possível abrir o arquivo CSV.');
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                throw new \RuntimeException('Arquivo CSV vazio.');
            }

            $delimiter = $this->detectCsvDelimiter($firstLine);
            rewind($handle);

            $csv = [];
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                // Ignora linhas vazias preservadas por alguns exportadores.
                if ($row === [null]) {
                    continue;
                }
                $csv[] = $row;
            }
        } finally {
            fclose($handle);
        }

        if (empty($csv)) {
            throw new \RuntimeException('Arquivo CSV vazio.');
        }

        $headers = array_map(
            static fn ($header) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $header)),
            $csv[0],
        );
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

/**
 * Garante que uma linha tenha exatamente a largura do cabeçalho antes de
 * associar colunas. CSVs Binance podem ter campos opcionais vazios no fim.
 */
private function combineImportedRow(array $headers, array $row, int $lineNumber): ?array
{
    $headerCount = count($headers);
    if ($headerCount === 0) {
        throw new \RuntimeException('O arquivo não possui cabeçalho válido.');
    }

    $row = array_values($row);
    if (count($row) < $headerCount) {
        $row = array_pad($row, $headerCount, null);
    } elseif (count($row) > $headerCount) {
        Log::warning('Linha CSV possui colunas adicionais; valores excedentes foram ignorados.', [
            'line' => $lineNumber,
            'headers' => $headerCount,
            'values' => count($row),
        ]);
        $row = array_slice($row, 0, $headerCount);
    }

    return array_combine($headers, $row) ?: null;
}

private function detectCsvDelimiter(string $firstLine): string
{
    $candidates = [',', ';', "\t"];
    $delimiter = ',';
    $highestCount = -1;

    foreach ($candidates as $candidate) {
        $count = substr_count($firstLine, $candidate);
        if ($count > $highestCount) {
            $highestCount = $count;
            $delimiter = $candidate;
        }
    }

    return $delimiter;
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
