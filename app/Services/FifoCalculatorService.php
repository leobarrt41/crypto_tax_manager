<?php

namespace App\Services;

use App\Models\FifoInventoryGap;
use App\Models\FifoOpeningBalance;
use App\Models\TaxMonthlySummary;
use App\Models\Transaction;
use App\Models\TransactionReconciliation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FifoCalculatorService
 *
 * Apura ganhos/perdas de capital pelo método FIFO para fins fiscais brasileiros.
 *
 * Regras implementadas:
 *  - buy / deposit / receive  => ENTRADA (acumula lote de custo), salvo conciliação pendente
 *  - sell / withdrawal / send => SAÍDA tributável (consome lotes FIFO), salvo conciliação pendente
 *  - trade / convert          => SAÍDA do from_asset + ENTRADA do to_asset
 *  - transferências unilaterais importadas com conciliação pendente não recebem efeito fiscal automático
 *
 * Idempotência: zera os campos fiscais antes de recalcular, garantindo
 * que múltiplas execuções produzam o mesmo resultado.
 *
 * Nota: o método calculateFor() legado é mantido para compatibilidade.
 */
class FifoCalculatorService
{
    // Tipos que representam ENTRADA de ativo
    private const ENTRADA_TYPES = ['buy', 'deposit', 'receive', 'earn', 'reward', 'airdrop', 'asset_dividend', 'distribution'];

    // Tipos que representam SAÍDA tributável
    private const SAIDA_TYPES = ['sell', 'withdrawal', 'withdraw', 'send', 'fee'];

    // Tipos que representam CONVERSÃO (saída + entrada)
    private const CONVERT_TYPES = ['trade', 'convert', 'swap'];

    public function __construct(private readonly TransactionImportEvidenceService $importEvidence) {}

    // ─── API pública ─────────────────────────────────────────────────────────────

    /**
     * Recalcula o FIFO para um usuário específico (ou todos os usuários).
     *
     * @param  int|null  $userId  null = todos os usuários
     * @return array Estatísticas do processamento
     */
    public function recalculate(?int $userId = null, ?int $fiscalYear = null): array
    {
        $stats = [
            'users_processed' => 0,
            'transactions_read' => 0,
            'saidas_processed' => 0,
            'opening_lots_loaded' => 0,
            'errors' => [],
        ];

        $query = User::query();
        if ($userId !== null) {
            $query->where('id', $userId);
        }

        $users = $query->get();

        foreach ($users as $user) {
            try {
                $result = $this->recalculateForUser($user->id, $fiscalYear);
                $stats['users_processed']++;
                $stats['transactions_read'] += $result['transactions_read'];
                $stats['saidas_processed'] += $result['saidas_processed'];
                $stats['opening_lots_loaded'] += $result['opening_lots_loaded'];
            } catch (\Throwable $e) {
                $msg = "Erro ao processar user_id={$user->id}: {$e->getMessage()}";
                Log::error($msg, ['trace' => $e->getTraceAsString()]);
                $stats['errors'][] = $msg;
            }
        }

        return $stats;
    }

    /**
     * Recalcula FIFO para um único usuário dentro de uma transação DB.
     */
    public function recalculateForUser(int $userId, ?int $fiscalYear = null): array
    {
        return DB::transaction(function () use ($userId, $fiscalYear) {

            // Quando o usuário informa saldos iniciais, o FIFO passa a ser
            // recalculado a partir do ano fiscal correspondente. Isso evita somar
            // novamente transações de anos anteriores ao estoque de 31/12 informado.
            $openingBalanceQuery = FifoOpeningBalance::where('user_id', $userId);

            if ($fiscalYear !== null) {
                // Para recalcular 2025, por exemplo, usa-se o último estoque
                // cadastrado até 2025. Assim, um estoque de abertura de 2024
                // continua sendo a base válida quando não houver novo saldo
                // cadastrado para 2025.
                $startYear = (clone $openingBalanceQuery)
                    ->where('fiscal_year', '<=', $fiscalYear)
                    ->max('fiscal_year');

                $openingBalances = $startYear !== null
                    ? $openingBalanceQuery->where('fiscal_year', $startYear)->orderBy('asset')->get()
                    : collect();
            } else {
                $startYear = (clone $openingBalanceQuery)->min('fiscal_year');
                $openingBalances = $startYear !== null
                    ? $openingBalanceQuery->where('fiscal_year', $startYear)->orderBy('asset')->get()
                    : collect();
            }

            // ── 1. Limitar o recálculo ao período correto ───────────────────────
            $transactionsQuery = Transaction::where('user_id', $userId);
            if ($startYear !== null) {
                $transactionsQuery->whereYear('date', '>=', $startYear);
            }

            // ── 2. Zerar campos fiscais somente no escopo recalculado ───────────
            (clone $transactionsQuery)->update([
                'cost_basis_brl' => null,
                'profit_loss_brl' => null,
                'fifo_lots' => null,
                'fifo_processed' => false,
                'fifo_status' => null,
                'quantity_status' => null,
                'cost_status' => null,
                'from_quantity_status' => null,
                'from_cost_status' => null,
                'from_cost_evidence_type' => null,
                'to_quantity_status' => null,
                'to_cost_status' => null,
                'to_cost_evidence_type' => null,
                'to_cost_basis_brl' => null,
            ]);

            // ── 3. Zerar resumos mensais no mesmo escopo ────────────────────────
            $summaryQuery = TaxMonthlySummary::where('user_id', $userId);
            if ($startYear !== null) {
                $summaryQuery->where('year', '>=', $startYear);
            }
            $summaryQuery->delete();

            // ── 4. Buscar transações cronologicamente ───────────────────────────
            $scopedTransactionIds = (clone $transactionsQuery)->pluck('id')->all();
            $transactions = $transactionsQuery
                ->whereNotIn('id', TransactionReconciliation::query()
                    ->select('matched_transaction_id')
                    ->where('user_id', $userId)
                    ->where('status', TransactionReconciliation::STATUS_CONFIRMED))
                ->with('documentaryEvidences')
                ->orderBy('date')
                ->orderBy('id')
                ->get();

            $stats = [
                'transactions_read' => $transactions->count(),
                'saidas_processed' => 0,
                'opening_lots_loaded' => $openingBalances->count(),
                'recalculated_from_year' => $startYear,
                'fifo_gaps_open' => 0,
                'fifo_gaps_resolved' => 0,
            ];

            // ── 5. Estrutura de lotes FIFO por ativo ────────────────────────────
            // $lots[$symbol] = [ ['qty', 'cost_brl', 'date', 'source'], ... ]
            $lots = [];
            $this->seedOpeningBalances($lots, $openingBalances);

            // ── 6. Acumulador mensal ────────────────────────────────────────────
            // $monthly[$year][$month] = ['alienacoes'=>0, 'lucro'=>0, 'prejuizo'=>0, 'qtd'=>0]
            $monthly = [];

            // ── 7. Processar cada transação ─────────────────────────────────────
            $detectedGapTransactionIds = [];
            foreach ($transactions as $tx) {
                // Movimentações de uma perna vindas do CSV anual podem ser
                // transferências entre carteiras próprias. Sem conciliação,
                // não criamos custo de aquisição nem alienação tributável.
                if ($tx->reconciliation_status === 'pending_transfer_reconciliation') {
                    continue;
                }

                $type = strtolower(trim($tx->type ?? ''));

                if (in_array($type, self::ENTRADA_TYPES)) {
                    $this->processEntrada($lots, $tx);

                } elseif (in_array($type, self::SAIDA_TYPES)) {
                    $result = $this->processSaida($lots, $tx);
                    if ($result['is_incomplete'] ?? false) {
                        $detectedGapTransactionIds[] = $tx->id;
                        $stats['fifo_gaps_open']++;
                    } else {
                        $this->updateMonthly($monthly, $tx, $result);
                        $stats['saidas_processed']++;
                    }

                } elseif (in_array($type, self::CONVERT_TYPES)) {
                    // A conversão possui duas pernas fiscais independentes. A
                    // evidência da aquisição é capturada antes de processar a
                    // alienação, pois esta pode ter custo FIFO pendente.
                    $acquisitionLeg = $this->resolveConvertAcquisitionLeg($tx);

                    // Saída do from_asset
                    if ($tx->from_asset && $tx->from_amount > 0) {
                        $result = $this->processSaidaAsset(
                            $lots,
                            $tx,
                            $tx->from_asset,
                            (float) $tx->from_amount,
                            (float) ($tx->total_brl ?? 0),
                        );
                        if ($result['is_incomplete'] ?? false) {
                            $detectedGapTransactionIds[] = $tx->id;
                            $stats['fifo_gaps_open']++;
                        } else {
                            $this->updateMonthly($monthly, $tx, $result);
                            $stats['saidas_processed']++;
                        }
                    }
                    // Entrada do to_asset. Nunca reutiliza o status de custo
                    // da alienação: o valor recebido deve ter sua própria
                    // evidência documental ou estimativa identificada.
                    if ($tx->to_asset && $tx->to_amount > 0) {
                        $tx->forceFill([
                            'to_quantity_status' => FifoInventoryGap::QUANTITY_COMPLETE,
                            'to_cost_status' => $acquisitionLeg['cost_status'],
                            'to_cost_evidence_type' => $acquisitionLeg['evidence_type'],
                            'to_cost_basis_brl' => $acquisitionLeg['cost_brl'],
                        ])->saveQuietly();

                        $this->processEntradaAsset(
                            $lots,
                            $tx->to_asset,
                            (float) $tx->to_amount,
                            $acquisitionLeg['cost_brl'],
                            $tx->date,
                            'transaction',
                            null,
                            $acquisitionLeg['cost_status'],
                        );
                    }
                }
                // Tipos desconhecidos são ignorados silenciosamente
            }

            // ── 8. Resolver somente lacunas deste escopo que não persistirem ───────
            $stats['fifo_gaps_resolved'] = $this->resolveRecoveredGaps(
                $userId,
                $scopedTransactionIds,
                $detectedGapTransactionIds,
            );

            // ── 9. Persistir resumos mensais ─────────────────────────────────────
            $this->persistMonthly($userId, $monthly);

            return $stats;
        });
    }

    // ─── Compatibilidade legada ──────────────────────────────────────────────────

    /**
     * @deprecated Use recalculateForUser() para processamento em lote.
     * Mantido para compatibilidade com código existente.
     */
    public function calculateFor(Transaction $sale)
    {
        if (! in_array($sale->operation, ['saida']) || ! $sale->to_asset || ! $sale->to_amount) {
            return null;
        }

        $userId = $sale->user_id;
        $asset = $sale->to_asset;
        $remaining = (float) $sale->to_amount;
        $totalCost = 0;

        DB::beginTransaction();

        try {
            $buys = Transaction::where('user_id', $userId)
                ->where('to_asset', $asset)
                ->where('operation', 'entrada')
                ->where('date', '<=', $sale->date)
                ->where('remaining_quantity', '>', 0)
                ->orderBy('date')
                ->lockForUpdate()
                ->get();

            foreach ($buys as $buy) {
                $available = $buy->remaining_quantity;
                if ($available <= 0) {
                    continue;
                }
                $used = min($remaining, $available);
                $unitCost = $buy->price ?? 0;
                $totalCost += $unitCost * $used;

                $buy->remaining_quantity -= $used;
                $buy->save();

                $remaining -= $used;
                if ($remaining <= 0) {
                    break;
                }
            }

            if ($remaining > 0) {
                Log::warning("Transação {$sale->id} possui quantidade superior ao disponível em FIFO.");
            }

            $saleValue = $sale->total_brl ?? 0;
            $profit = $saleValue - $totalCost;
            $sale->profit_loss = $profit;
            $sale->save();

            DB::commit();

            return $profit;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro no cálculo FIFO: '.$e->getMessage());

            return null;
        }
    }

    // ─── Métodos privados ────────────────────────────────────────────────────────

    private function processEntrada(array &$lots, Transaction $tx): void
    {
        $asset = $tx->to_asset ?? $tx->from_asset;
        $qty = (float) ($tx->to_amount ?? $tx->from_amount ?? 0);
        $costStatus = $this->costStatusForTransaction($tx);
        $costBrl = $this->costForTransaction($tx);

        if (! $asset || $qty <= 0) {
            return;
        }

        // Uma entrada pode ter a quantidade confirmada sem ter custo fiscal
        // comprovado. Ela só poderá participar de resultado fiscal quando o
        // custo estiver explicitamente marcado como conhecido.
        $tx->forceFill([
            'quantity_status' => FifoInventoryGap::QUANTITY_COMPLETE,
            'cost_status' => $costStatus,
        ])->saveQuietly();

        $this->processEntradaAsset($lots, $asset, $qty, $costBrl, $tx->date, 'transaction', null, $costStatus);
    }

    private function processEntradaAsset(
        array &$lots,
        string $asset,
        float $qty,
        ?float $costBrl,
        $date,
        string $source = 'transaction',
        ?int $openingBalanceId = null,
        string $costStatus = FifoInventoryGap::COST_KNOWN,
    ): void {
        $asset = strtoupper(trim($asset));

        if (! isset($lots[$asset])) {
            $lots[$asset] = [];
        }

        $lots[$asset][] = [
            'qty' => $qty,
            'cost_brl' => $costBrl,
            'cost_status' => $costStatus,
            'date' => $date instanceof \Carbon\Carbon ? $date->toDateTimeString() : (string) $date,
            'source' => $source,
            'opening_balance_id' => $openingBalanceId,
        ];
    }

    /**
     * Injeta o estoque de 31/12 como os primeiros lotes FIFO do ano fiscal.
     * Cada registro é rastreável posteriormente em transactions.fifo_lots.
     */
    private function seedOpeningBalances(array &$lots, $openingBalances): void
    {
        foreach ($openingBalances as $balance) {
            $quantity = (float) $balance->quantity;
            $costBrl = (float) $balance->total_cost_brl;

            if ($quantity <= 0 || $costBrl < 0 || empty($balance->asset)) {
                continue;
            }

            $this->processEntradaAsset(
                $lots,
                $balance->asset,
                $quantity,
                $costBrl,
                $balance->reference_date->copy()->endOfDay(),
                'opening_balance',
                $balance->id
            );
        }
    }

    private function processSaida(array &$lots, Transaction $tx): array
    {
        $asset = $tx->from_asset ?? $tx->to_asset;
        $qty = (float) ($tx->from_amount ?? $tx->to_amount ?? 0);
        $totalBrl = (float) ($tx->total_brl ?? 0);

        if (! $asset || $qty <= 0) {
            return ['cost_basis_brl' => 0, 'profit_loss_brl' => 0, 'fifo_lots' => []];
        }

        return $this->processSaidaAsset($lots, $tx, $asset, $qty, $totalBrl);
    }

    private function processSaidaAsset(
        array &$lots,
        Transaction $tx,
        string $asset,
        float $qty,
        float $totalBrl
    ): array {
        $asset = strtoupper(trim($asset));
        $consumedLots = [];
        $costBasisBrl = 0.0;
        $pendingCostQuantity = 0.0;
        $remaining = $qty;
        $availableQuantity = collect($lots[$asset] ?? [])->sum(fn (array $lot) => (float) $lot['qty']);

        if (isset($lots[$asset])) {
            foreach ($lots[$asset] as $i => &$lot) {
                if ($remaining <= 0) {
                    break;
                }

                $consume = min($lot['qty'], $remaining);
                $hasKnownCost = ($lot['cost_status'] ?? FifoInventoryGap::COST_KNOWN) === FifoInventoryGap::COST_KNOWN
                    && $lot['cost_brl'] !== null;
                $lotCostUnit = $hasKnownCost && $lot['qty'] > 0 ? ($lot['cost_brl'] / $lot['qty']) : null;
                $consumedCost = $lotCostUnit !== null ? $consume * $lotCostUnit : null;

                $consumedLots[] = [
                    'lot_date' => $lot['date'],
                    'lot_qty' => round($consume, 10),
                    'lot_cost_brl' => $consumedCost !== null ? round($consumedCost, 10) : null,
                    'cost_status' => $hasKnownCost ? FifoInventoryGap::COST_KNOWN : FifoInventoryGap::COST_PENDING,
                    'lot_source' => $lot['source'] ?? 'transaction',
                    'opening_balance_id' => $lot['opening_balance_id'] ?? null,
                ];

                if ($consumedCost !== null) {
                    $costBasisBrl += $consumedCost;
                    $lot['cost_brl'] -= $consumedCost;
                } else {
                    $pendingCostQuantity += $consume;
                }
                $lot['qty'] -= $consume;
                $remaining -= $consume;

                if ($lot['qty'] <= 1e-10) {
                    unset($lots[$asset][$i]);
                }
            }
            unset($lot);
            $lots[$asset] = array_values($lots[$asset]);
        }

        $quantityIncomplete = $remaining > 1e-10;
        $costIncomplete = $pendingCostQuantity > 1e-10;

        if ($quantityIncomplete || $costIncomplete) {
            $this->recordInventoryGap(
                $tx,
                $asset,
                $qty,
                $availableQuantity,
                $remaining,
                $pendingCostQuantity,
                $consumedLots,
                $costBasisBrl,
            );

            // A quantidade pode estar parcialmente ou totalmente identificada,
            // mas nunca compõe ganho fiscal quando o custo não é comprovado.
            $tx->cost_basis_brl = null;
            $tx->profit_loss_brl = null;
            $tx->fifo_lots = json_encode($consumedLots);
            $tx->fifo_processed = false;
            $tx->fifo_status = 'incomplete';
            $this->applyDisposalCompleteness($tx, $quantityIncomplete, true);
            $tx->saveQuietly();

            return [
                'cost_basis_brl' => null,
                'profit_loss_brl' => null,
                'fifo_lots' => $consumedLots,
                'is_incomplete' => true,
            ];
        }

        $profitLossBrl = $totalBrl - $costBasisBrl;

        $tx->cost_basis_brl = round($costBasisBrl, 10);
        $tx->profit_loss_brl = round($profitLossBrl, 10);
        $tx->fifo_lots = json_encode($consumedLots);
        $tx->fifo_processed = true;
        $tx->fifo_status = 'complete';
        $this->applyDisposalCompleteness($tx, false, false);
        $tx->saveQuietly();

        return [
            'cost_basis_brl' => $costBasisBrl,
            'profit_loss_brl' => $profitLossBrl,
            'fifo_lots' => $consumedLots,
            'is_incomplete' => false,
        ];
    }

    private function recordInventoryGap(
        Transaction $transaction,
        string $asset,
        float $requiredQuantity,
        float $availableQuantity,
        float $missingQuantity,
        float $pendingCostQuantity,
        array $consumedLots,
        float $knownCostBrl,
    ): void {
        FifoInventoryGap::query()->updateOrCreate(
            [
                'user_id' => $transaction->user_id,
                'transaction_id' => $transaction->id,
            ],
            [
                'asset' => $asset,
                'required_quantity' => round($requiredQuantity, 12),
                'available_quantity' => round($availableQuantity, 12),
                'missing_quantity' => round($missingQuantity, 12),
                'pending_cost_quantity' => round($pendingCostQuantity, 12),
                'occurred_at' => $transaction->date,
                'status' => FifoInventoryGap::STATUS_OPEN,
                'quantity_status' => $missingQuantity > 1e-10
                    ? FifoInventoryGap::QUANTITY_INCOMPLETE
                    : FifoInventoryGap::QUANTITY_COMPLETE,
                'cost_status' => ($missingQuantity > 1e-10 || $pendingCostQuantity > 1e-10)
                    ? FifoInventoryGap::COST_PENDING
                    : FifoInventoryGap::COST_KNOWN,
                'reason' => $missingQuantity > 1e-10
                    ? ($pendingCostQuantity > 1e-10 ? 'insufficient_quantity_and_pending_cost' : 'insufficient_acquisition_history')
                    : 'pending_acquisition_cost',
                'source' => $transaction->source_type,
                'consumed_lots' => $consumedLots,
                'context' => [
                    'known_cost_brl' => round($knownCostBrl, 10),
                    'pending_cost_quantity' => round($pendingCostQuantity, 12),
                    'transaction_type' => $transaction->type,
                    'fifo_status' => 'incomplete',
                ],
                'resolved_at' => null,
            ],
        );
    }

    /** @param array<int, int> $scopedTransactionIds @param array<int, int> $detectedGapTransactionIds */
    private function resolveRecoveredGaps(int $userId, array $scopedTransactionIds, array $detectedGapTransactionIds): int
    {
        if ($scopedTransactionIds === []) {
            return 0;
        }

        $query = FifoInventoryGap::query()
            ->where('user_id', $userId)
            ->where('status', FifoInventoryGap::STATUS_OPEN)
            ->whereIn('transaction_id', $scopedTransactionIds);

        if ($detectedGapTransactionIds !== []) {
            $query->whereNotIn('transaction_id', array_values(array_unique($detectedGapTransactionIds)));
        }

        return $query->update([
            'status' => FifoInventoryGap::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);
    }

    /**
     * Atualiza exclusivamente a perna de alienação da conversão. Campos
     * legados continuam sendo usados apenas por operações de uma perna.
     */
    private function applyDisposalCompleteness(Transaction $transaction, bool $quantityIncomplete, bool $costIncomplete): void
    {
        $quantityStatus = $quantityIncomplete
            ? FifoInventoryGap::QUANTITY_INCOMPLETE
            : FifoInventoryGap::QUANTITY_COMPLETE;
        $costStatus = $costIncomplete
            ? FifoInventoryGap::COST_PENDING
            : FifoInventoryGap::COST_KNOWN;

        if (in_array(strtolower((string) $transaction->type), self::CONVERT_TYPES, true)) {
            $transaction->from_quantity_status = $quantityStatus;
            $transaction->from_cost_status = $costStatus;
            $transaction->from_cost_evidence_type = $costIncomplete ? null : 'fifo_consumed_lots';
            // Um único cost_status não descreve corretamente as duas pernas.
            $transaction->quantity_status = null;
            $transaction->cost_status = null;
            $transaction->cost_evidence_type = null;

            return;
        }

        $transaction->quantity_status = $quantityStatus;
        $transaction->cost_status = $costStatus;
        $transaction->cost_evidence_type = $costIncomplete ? null : 'fifo_consumed_lots';
    }

    /** @return array{cost_brl: ?float, cost_status: string, evidence_type: ?string} */
    private function resolveConvertAcquisitionLeg(Transaction $transaction): array
    {
        $metadata = $this->importEvidence->annualCsvMetadata($transaction)
            ?? (is_array($transaction->import_metadata) ? $transaction->import_metadata : []);
        $brlValues = is_array($metadata['brl_values'] ?? null) ? $metadata['brl_values'] : [];
        $receivedValue = $brlValues['received_value_brl'] ?? null;

        if (is_numeric($receivedValue) && (float) $receivedValue > 0) {
            return [
                'cost_brl' => (float) $receivedValue,
                'cost_status' => FifoInventoryGap::COST_KNOWN,
                'evidence_type' => 'binance_annual_csv_received_value_brl',
            ];
        }

        $fromAsset = strtoupper((string) $transaction->from_asset);
        $fromAmount = (float) ($transaction->from_amount ?? 0);
        if ($fromAsset === 'BRL' && $fromAmount > 0) {
            return [
                'cost_brl' => $fromAmount,
                'cost_status' => FifoInventoryGap::COST_KNOWN,
                'evidence_type' => 'binance_convert_brl_paid',
            ];
        }

        $totalBrl = (float) ($transaction->total_brl ?? 0);
        if ($totalBrl > 0 && $transaction->pricing_status === 'completed') {
            return [
                'cost_brl' => $totalBrl,
                'cost_status' => FifoInventoryGap::COST_ESTIMATED,
                'evidence_type' => 'historical_market_quote',
            ];
        }

        return [
            'cost_brl' => null,
            'cost_status' => FifoInventoryGap::COST_PENDING,
            'evidence_type' => null,
        ];
    }

    private function costStatusForTransaction(Transaction $transaction): string
    {
        if (in_array($transaction->cost_status, [
            FifoInventoryGap::COST_KNOWN,
            FifoInventoryGap::COST_ESTIMATED,
            FifoInventoryGap::COST_PENDING,
            FifoInventoryGap::COST_UNAVAILABLE,
        ], true)) {
            return $transaction->cost_status;
        }

        return $transaction->total_brl !== null && (float) $transaction->total_brl > 0
            ? FifoInventoryGap::COST_KNOWN
            : FifoInventoryGap::COST_PENDING;
    }

    private function costForTransaction(Transaction $transaction): ?float
    {
        return $this->costStatusForTransaction($transaction) === FifoInventoryGap::COST_KNOWN
            ? (float) $transaction->total_brl
            : null;
    }

    private function updateMonthly(array &$monthly, Transaction $tx, array $result): void
    {
        $date = $tx->date instanceof \Carbon\Carbon ? $tx->date : \Carbon\Carbon::parse($tx->date);
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');

        if (! isset($monthly[$year][$month])) {
            $monthly[$year][$month] = [
                'alienacoes' => 0.0,
                'lucro' => 0.0,
                'prejuizo' => 0.0,
                'qtd' => 0,
            ];
        }

        $totalBrl = (float) ($tx->total_brl ?? 0);
        $profitLoss = (float) $result['profit_loss_brl'];

        $monthly[$year][$month]['alienacoes'] += $totalBrl;
        $monthly[$year][$month]['qtd']++;

        if ($profitLoss >= 0) {
            $monthly[$year][$month]['lucro'] += $profitLoss;
        } else {
            $monthly[$year][$month]['prejuizo'] += abs($profitLoss);
        }
    }

    private function persistMonthly(int $userId, array $monthly): void
    {
        $now = now();

        foreach ($monthly as $year => $months) {
            foreach ($months as $month => $data) {
                $resultado = $data['lucro'] - $data['prejuizo'];

                TaxMonthlySummary::updateOrCreate(
                    ['user_id' => $userId, 'year' => $year, 'month' => $month],
                    [
                        'total_alienacoes_brl' => round($data['alienacoes'], 2),
                        'lucro_realizado_brl' => round($data['lucro'], 2),
                        'prejuizo_realizado_brl' => round($data['prejuizo'], 2),
                        'resultado_liquido_brl' => round($resultado, 2),
                        'qtd_operacoes' => $data['qtd'],
                        'calculated_at' => $now,
                    ]
                );
            }
        }
    }
}
