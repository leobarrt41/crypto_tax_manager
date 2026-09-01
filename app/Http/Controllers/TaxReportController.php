<?php

namespace App\Http\Controllers;

use App\Models\TaxMonthlySummary;
use App\Models\FifoOpeningBalance;
use App\Models\Transaction;
use App\Services\FifoCalculatorService;
use App\Services\FifoAcquisitionHistoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class TaxReportController extends Controller
{
    public function __construct(
        private FifoCalculatorService $fifo,
        private FifoAcquisitionHistoryService $acquisitionHistory,
    ) {
    }

    // ─── Inertia Page ────────────────────────────────────────────────────────────

    /**
     * Renderiza a página Relatórios IR.
     * GET /tax-reports
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        // Anos disponíveis (baseado nos resumos já calculados ou nas transações)
        $years = TaxMonthlySummary::where('user_id', $user->id)
            ->distinct()
            ->orderByDesc('year')
            ->pluck('year')
            ->toArray();

        // Complementar com anos existentes nas transações e nos saldos iniciais.
        // Isso permite cadastrar e consultar o estoque de abertura mesmo antes
        // de o recálculo ter gerado um resumo fiscal para o ano.
        $transactionYears = \App\Models\Transaction::where('user_id', $user->id)
            ->selectRaw('EXTRACT(YEAR FROM date) as year')
            ->distinct()
            ->pluck('year')
            ->toArray();

        $openingBalanceYears = FifoOpeningBalance::where('user_id', $user->id)
            ->distinct()
            ->pluck('fiscal_year')
            ->toArray();

        // Sempre disponibiliza a janela fiscal padrão de cinco anos, mesmo que
        // o usuário ainda não tenha transações ou resumos no banco.
        $defaultYears = range(now()->year, now()->year - 5);

        $years = collect([...$years, ...$transactionYears, ...$openingBalanceYears, ...$defaultYears])
            ->filter()
            ->unique()
            ->sortDesc()
            ->values()
            ->all();

        return Inertia::render('Reports/RelatorioIR', [
            'availableYears' => $years,
        ]);
    }

    // ─── API JSON ────────────────────────────────────────────────────────────────

    /**
     * Retorna o resumo mensal fiscal para um ano (e opcionalmente mês).
     * GET /api/tax-reports/monthly-summary?year=2024&month=3
     */
    public function monthlySummary(Request $request)
    {
        $request->validate([
            'year'  => 'required|integer|min:2009|max:2099',
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        $user  = Auth::user();
        $year  = (int) $request->year;
        $month = $request->month ? (int) $request->month : null;

        $query = TaxMonthlySummary::where('user_id', $user->id)
            ->where('year', $year)
            ->orderBy('month');

        if ($month !== null) {
            $query->where('month', $month);
        }

        $summaries = $query->get()->map(function ($s) {
            return [
                'mes'                    => $s->month,
                'nome_mes'               => $s->nome_mes,
                'ano'                    => $s->year,
                'total_alienacoes_brl'   => (float) $s->total_alienacoes_brl,
                'lucro_realizado_brl'    => (float) $s->lucro_realizado_brl,
                'prejuizo_realizado_brl' => (float) $s->prejuizo_realizado_brl,
                'resultado_liquido_brl'  => (float) $s->resultado_liquido_brl,
                'qtd_operacoes'          => (int) $s->qtd_operacoes,
                'calculated_at'          => $s->calculated_at?->toISOString(),
            ];
        });

        return response()->json([
            'year'      => $year,
            'month'     => $month,
            'summaries' => $summaries,
            'totals'    => [
                'total_alienacoes_brl'   => round($summaries->sum('total_alienacoes_brl'), 2),
                'lucro_realizado_brl'    => round($summaries->sum('lucro_realizado_brl'), 2),
                'prejuizo_realizado_brl' => round($summaries->sum('prejuizo_realizado_brl'), 2),
                'resultado_liquido_brl'  => round($summaries->sum('resultado_liquido_brl'), 2),
                'qtd_operacoes'          => $summaries->sum('qtd_operacoes'),
            ],
        ]);
    }

    /**
     * Dispara o recálculo FIFO para o usuário autenticado.
     * POST /api/tax-reports/recalculate-fifo
     */
    public function recalculateFifo(Request $request)
    {
        $request->validate([
            'fiscal_year' => 'nullable|integer|min:2009|max:2099',
        ]);

        $user = Auth::user();

        try {
            $stats = $this->fifo->recalculateForUser(
                $user->id,
                $request->filled('fiscal_year') ? (int) $request->fiscal_year : null
            );

            return response()->json([
                'success' => true,
                'message' => 'Recálculo FIFO concluído com sucesso.',
                'stats'   => $stats,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao recalcular FIFO: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retorna as lacunas abertas de histórico de aquisição e a cobertura já
     * registrada das importações. Este endpoint não consulta a Binance.
     * GET /reports/relatorio-ir/acquisition-history?year=2024
     */
    public function acquisitionHistory(Request $request)
    {
        $data = $request->validate([
            'year' => 'required|integer|min:2009|max:2099',
        ]);

        return response()->json($this->acquisitionHistory->forYear(
            Auth::user(),
            (int) $data['year'],
        ));
    }

    /**
     * Retorna os saldos de abertura usados como primeiro lote FIFO no ano.
     * GET /reports/relatorio-ir/opening-balances?fiscal_year=2024
     */
    public function openingBalances(Request $request)
    {
        $request->validate([
            'fiscal_year' => 'required|integer|min:2009|max:2099',
        ]);

        $balances = FifoOpeningBalance::where('user_id', Auth::id())
            ->where('fiscal_year', (int) $request->fiscal_year)
            ->orderBy('asset')
            ->get()
            ->map(fn (FifoOpeningBalance $balance) => $this->serializeOpeningBalance($balance));

        return response()->json([
            'fiscal_year' => (int) $request->fiscal_year,
            'reference_date' => sprintf('31/12/%d', (int) $request->fiscal_year - 1),
            'balances' => $balances,
        ]);
    }

    /**
     * Cria ou atualiza o saldo de abertura de um ativo para o ano fiscal.
     * POST /reports/relatorio-ir/opening-balances
     */
    public function storeOpeningBalance(Request $request)
    {
        $data = $request->validate([
            'fiscal_year'    => 'required|integer|min:2009|max:2099',
            'asset'          => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/'],
            'quantity'       => 'required|numeric|gt:0',
            'total_cost_brl' => 'required|numeric|min:0',
            'source'         => 'nullable|string|max:100',
            'notes'          => 'nullable|string|max:2000',
            'confirm_manual_correction' => 'nullable|boolean',
        ]);

        $data['asset']          = strtoupper(trim($data['asset']));
        $data['reference_date'] = sprintf('%d-12-31', (int) $data['fiscal_year'] - 1);

        if (!$request->boolean('confirm_manual_correction') && $this->hasReconstructedAcquisition(
            Auth::id(),
            $data['asset'],
            $data['reference_date'],
        )) {
            return response()->json([
                'success' => false,
                'requires_manual_confirmation' => true,
                'message' => 'Já existem aquisições importadas para este ativo antes da data de referência. A correção manual pode duplicar lotes FIFO; confirme-a somente se o histórico importado ainda não cobrir a aquisição.',
            ], 422);
        }

        $balance = FifoOpeningBalance::updateOrCreate(
            [
                'user_id'     => Auth::id(),
                'fiscal_year' => $data['fiscal_year'],
                'asset'       => $data['asset'],
            ],
            [
                'reference_date' => $data['reference_date'],
                'quantity'       => $data['quantity'],
                'total_cost_brl' => $data['total_cost_brl'],
                'source'         => $data['source'] ?? null,
                'notes'          => $data['notes'] ?? null,
            ]
        );

        return response()->json([
            'success'         => true,
            'message'         => "Saldo inicial de {$balance->asset} salvo. Execute o recálculo FIFO para aplicá-lo.",
            'opening_balance' => $this->serializeOpeningBalance($balance),
        ]);
    }

    /**
     * Remove um saldo de abertura do usuário autenticado.
     * DELETE /reports/relatorio-ir/opening-balances/{openingBalance}
     */
    public function destroyOpeningBalance(FifoOpeningBalance $openingBalance)
    {
        abort_unless($openingBalance->user_id === Auth::id(), 404);

        $openingBalance->delete();

        return response()->json([
            'success' => true,
            'message' => 'Saldo inicial removido. Execute o recálculo FIFO para atualizar a apuração.',
        ]);
    }

    private function hasReconstructedAcquisition(int $userId, string $asset, string $referenceDate): bool
    {
        return Transaction::query()
            ->where('user_id', $userId)
            ->where('to_asset', $asset)
            ->whereDate('date', '<=', $referenceDate)
            ->whereIn('type', ['buy', 'fiat_buy', 'trade', 'convert', 'deposit', 'receive', 'earn', 'reward', 'airdrop'])
            ->where(function ($query): void {
                $query->whereNull('reconciliation_status')
                    ->orWhere('reconciliation_status', '!=', 'pending_transfer_reconciliation');
            })
            ->exists();
    }

    private function serializeOpeningBalance(FifoOpeningBalance $balance): array
    {
        return [
            'id'               => $balance->id,
            'fiscal_year'      => $balance->fiscal_year,
            'reference_date'   => $balance->reference_date?->format('Y-m-d'),
            'asset'            => $balance->asset,
            'quantity'         => (float) $balance->quantity,
            'total_cost_brl'   => (float) $balance->total_cost_brl,
            'unit_cost_brl'    => $balance->unit_cost_brl,
            'source'           => $balance->source,
            'notes'            => $balance->notes,
            'updated_at'       => $balance->updated_at?->toISOString(),
        ];
    }

    /**
     * Exporta o resumo mensal fiscal em CSV.
     * GET /api/tax-reports/export-csv?year=2024
     */
    public function exportCsv(Request $request)
    {
        $request->validate([
            'year'  => 'required|integer|min:2009|max:2099',
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        $user  = Auth::user();
        $year  = (int) $request->year;
        $month = $request->month ? (int) $request->month : null;

        if ($this->acquisitionHistory->hasOpenGaps($user->id, $year, $month)) {
            return response()->json([
                'message' => 'A exportação oficial está indisponível porque há operações anteriores ausentes no histórico de aquisição. Importe os CSVs anteriores ou concilie as pendências antes de exportar.',
                'fifo_status' => 'incomplete',
                'open_gaps_count' => $this->acquisitionHistory->openGapsCount($user->id, $year, $month),
            ], 422);
        }

        $query = TaxMonthlySummary::where('user_id', $user->id)
            ->where('year', $year)
            ->orderBy('month');

        if ($month !== null) {
            $query->where('month', $month);
        }

        $summaries = $query->get();

        $meses = [
            1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março',
            4 => 'Abril',   5 => 'Maio',       6 => 'Junho',
            7 => 'Julho',   8 => 'Agosto',     9 => 'Setembro',
            10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
        ];

        $filename = "relatorio_ir_{$year}" . ($month ? "_{$month}" : '') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($summaries, $meses, $year) {
            $handle = fopen('php://output', 'w');

            // BOM para Excel reconhecer UTF-8
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // Cabeçalho
            fputcsv($handle, [
                'Ano',
                'Mês',
                'Alienações (R$)',
                'Lucro (R$)',
                'Prejuízo (R$)',
                'Resultado Líquido (R$)',
                'Qtd. Operações',
            ], ';');

            foreach ($summaries as $s) {
                fputcsv($handle, [
                    $year,
                    $meses[$s->month] ?? $s->month,
                    number_format((float) $s->total_alienacoes_brl, 2, ',', '.'),
                    number_format((float) $s->lucro_realizado_brl, 2, ',', '.'),
                    number_format((float) $s->prejuizo_realizado_brl, 2, ',', '.'),
                    number_format((float) $s->resultado_liquido_brl, 2, ',', '.'),
                    $s->qtd_operacoes,
                ], ';');
            }

            // Linha de totais
            fputcsv($handle, [
                $year,
                'TOTAL',
                number_format($summaries->sum('total_alienacoes_brl'), 2, ',', '.'),
                number_format($summaries->sum('lucro_realizado_brl'), 2, ',', '.'),
                number_format($summaries->sum('prejuizo_realizado_brl'), 2, ',', '.'),
                number_format($summaries->sum('resultado_liquido_brl'), 2, ',', '.'),
                $summaries->sum('qtd_operacoes'),
            ], ';');

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
}
