<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TaxMonthlySummary;
use App\Models\FifoOpeningBalance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FifoCalculatorService
 *
 * Apura ganhos/perdas de capital pelo método FIFO para fins fiscais brasileiros.
 *
 * Regras implementadas:
 *  - buy / deposit / receive  => ENTRADA (acumula lote de custo)
 *  - sell / withdrawal / send => SAÍDA tributável (consome lotes FIFO)
 *  - trade / convert          => SAÍDA do from_asset + ENTRADA do to_asset
 *
 * Idempotência: zera os campos fiscais antes de recalcular, garantindo
 * que múltiplas execuções produzam o mesmo resultado.
 *
 * Nota: o método calculateFor() legado é mantido para compatibilidade.
 */
class FifoCalculatorService
{
    // Tipos que representam ENTRADA de ativo
    private const ENTRADA_TYPES = ['buy', 'deposit', 'receive', 'earn', 'reward', 'airdrop'];

    // Tipos que representam SAÍDA tributável
    private const SAIDA_TYPES = ['sell', 'withdrawal', 'withdraw', 'send', 'fee'];

    // Tipos que representam CONVERSÃO (saída + entrada)
    private const CONVERT_TYPES = ['trade', 'convert', 'swap'];

    // ─── API pública ─────────────────────────────────────────────────────────────

    /**
     * Recalcula o FIFO para um usuário específico (ou todos os usuários).
     *
     * @param  int|null  $userId  null = todos os usuários
     * @return array  Estatísticas do processamento
     */
    public function recalculate(?int $userId = null, ?int $fiscalYear = null): array
    {
        $stats = [
            'users_processed'      => 0,
            'transactions_read'    => 0,
            'saidas_processed'     => 0,
            'opening_lots_loaded'  => 0,
            'errors'               => [],
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
                $stats['transactions_read']   += $result['transactions_read'];
                $stats['saidas_processed']    += $result['saidas_processed'];
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
                'cost_basis_brl'  => null,
                'profit_loss_brl' => null,
                'fifo_lots'       => null,
                'fifo_processed'  => false,
            ]);

            // ── 3. Zerar resumos mensais no mesmo escopo ────────────────────────
            $summaryQuery = TaxMonthlySummary::where('user_id', $userId);
            if ($startYear !== null) {
                $summaryQuery->where('year', '>=', $startYear);
            }
            $summaryQuery->delete();

            // ── 4. Buscar transações cronologicamente ───────────────────────────
            $transactions = $transactionsQuery
                ->orderBy('date')
                ->orderBy('id')
                ->get();

            $stats = [
                'transactions_read'   => $transactions->count(),
                'saidas_processed'    => 0,
                'opening_lots_loaded' => $openingBalances->count(),
                'recalculated_from_year' => $startYear,
            ];

            // ── 5. Estrutura de lotes FIFO por ativo ────────────────────────────
            // $lots[$symbol] = [ ['qty', 'cost_brl', 'date', 'source'], ... ]
            $lots = [];
            $this->seedOpeningBalances($lots, $openingBalances);

            // ── 6. Acumulador mensal ────────────────────────────────────────────
            // $monthly[$year][$month] = ['alienacoes'=>0, 'lucro'=>0, 'prejuizo'=>0, 'qtd'=>0]
            $monthly = [];

            // ── 7. Processar cada transação ─────────────────────────────────────
            foreach ($transactions as $tx) {
                $type = strtolower(trim($tx->type ?? ''));

                if (in_array($type, self::ENTRADA_TYPES)) {
                    $this->processEntrada($lots, $tx);

                } elseif (in_array($type, self::SAIDA_TYPES)) {
                    $result = $this->processSaida($lots, $tx);
                    $this->updateMonthly($monthly, $tx, $result);
                    $stats['saidas_processed']++;

                } elseif (in_array($type, self::CONVERT_TYPES)) {
                    // Saída do from_asset
                    if ($tx->from_asset && $tx->from_amount > 0) {
                        $result = $this->processSaidaAsset(
                            $lots,
                            $tx,
                            $tx->from_asset,
                            (float) $tx->from_amount,
                            (float) ($tx->total_brl ?? 0)
                        );
                        $this->updateMonthly($monthly, $tx, $result);
                        $stats['saidas_processed']++;
                    }
                    // Entrada do to_asset
                    if ($tx->to_asset && $tx->to_amount > 0) {
                        $this->processEntradaAsset(
                            $lots,
                            $tx->to_asset,
                            (float) $tx->to_amount,
                            (float) ($tx->total_brl ?? 0),
                            $tx->date
                        );
                    }
                }
                // Tipos desconhecidos são ignorados silenciosamente
            }

            // ── 8. Persistir resumos mensais ─────────────────────────────────────
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
        if (!in_array($sale->operation, ['saida']) || !$sale->to_asset || !$sale->to_amount) {
            return null;
        }

        $userId    = $sale->user_id;
        $asset     = $sale->to_asset;
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
                $used       = min($remaining, $available);
                $unitCost   = $buy->price ?? 0;
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

            $saleValue        = $sale->total_brl ?? 0;
            $profit           = $saleValue - $totalCost;
            $sale->profit_loss = $profit;
            $sale->save();

            DB::commit();

            return $profit;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Erro no cálculo FIFO: " . $e->getMessage());
            return null;
        }
    }

    // ─── Métodos privados ────────────────────────────────────────────────────────

    private function processEntrada(array &$lots, Transaction $tx): void
    {
        $asset   = $tx->to_asset ?? $tx->from_asset;
        $qty     = (float) ($tx->to_amount ?? $tx->from_amount ?? 0);
        $costBrl = (float) ($tx->total_brl ?? 0);

        if (!$asset || $qty <= 0) {
            return;
        }

        $this->processEntradaAsset($lots, $asset, $qty, $costBrl, $tx->date);
    }

    private function processEntradaAsset(
        array &$lots,
        string $asset,
        float $qty,
        float $costBrl,
        $date,
        string $source = 'transaction',
        ?int $openingBalanceId = null
    ): void {
        $asset = strtoupper(trim($asset));

        if (!isset($lots[$asset])) {
            $lots[$asset] = [];
        }

        $lots[$asset][] = [
            'qty'                => $qty,
            'cost_brl'           => $costBrl,
            'date'               => $date instanceof \Carbon\Carbon ? $date->toDateTimeString() : (string) $date,
            'source'             => $source,
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
            $costBrl  = (float) $balance->total_cost_brl;

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
        $asset    = $tx->from_asset ?? $tx->to_asset;
        $qty      = (float) ($tx->from_amount ?? $tx->to_amount ?? 0);
        $totalBrl = (float) ($tx->total_brl ?? 0);

        if (!$asset || $qty <= 0) {
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
        $consumedLots = [];
        $costBasisBrl = 0.0;
        $remaining    = $qty;

        if (isset($lots[$asset])) {
            foreach ($lots[$asset] as $i => &$lot) {
                if ($remaining <= 0) {
                    break;
                }

                $consume      = min($lot['qty'], $remaining);
                $lotCostUnit  = $lot['qty'] > 0 ? ($lot['cost_brl'] / $lot['qty']) : 0;
                $consumedCost = $consume * $lotCostUnit;

                $consumedLots[] = [
                    'lot_date'           => $lot['date'],
                    'lot_qty'            => round($consume, 10),
                    'lot_cost_brl'       => round($consumedCost, 10),
                    'lot_source'         => $lot['source'] ?? 'transaction',
                    'opening_balance_id' => $lot['opening_balance_id'] ?? null,
                ];

                $costBasisBrl    += $consumedCost;
                $lot['qty']      -= $consume;
                $lot['cost_brl'] -= $consumedCost;
                $remaining       -= $consume;

                if ($lot['qty'] <= 1e-10) {
                    unset($lots[$asset][$i]);
                }
            }
            unset($lot);
            $lots[$asset] = array_values($lots[$asset]);
        }

        $profitLossBrl = $totalBrl - $costBasisBrl;

        $tx->cost_basis_brl  = round($costBasisBrl, 10);
        $tx->profit_loss_brl = round($profitLossBrl, 10);
        $tx->fifo_lots       = json_encode($consumedLots);
        $tx->fifo_processed  = true;
        $tx->saveQuietly();

        return [
            'cost_basis_brl'  => $costBasisBrl,
            'profit_loss_brl' => $profitLossBrl,
            'fifo_lots'       => $consumedLots,
        ];
    }

    private function updateMonthly(array &$monthly, Transaction $tx, array $result): void
    {
        $date  = $tx->date instanceof \Carbon\Carbon ? $tx->date : \Carbon\Carbon::parse($tx->date);
        $year  = (int) $date->format('Y');
        $month = (int) $date->format('n');

        if (!isset($monthly[$year][$month])) {
            $monthly[$year][$month] = [
                'alienacoes' => 0.0,
                'lucro'      => 0.0,
                'prejuizo'   => 0.0,
                'qtd'        => 0,
            ];
        }

        $totalBrl   = (float) ($tx->total_brl ?? 0);
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
                        'total_alienacoes_brl'   => round($data['alienacoes'], 2),
                        'lucro_realizado_brl'     => round($data['lucro'], 2),
                        'prejuizo_realizado_brl'  => round($data['prejuizo'], 2),
                        'resultado_liquido_brl'   => round($resultado, 2),
                        'qtd_operacoes'           => $data['qtd'],
                        'calculated_at'           => $now,
                    ]
                );
            }
        }
    }
}
