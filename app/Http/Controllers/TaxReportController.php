<?php

namespace App\Http\Controllers;

use App\Models\TaxMonthlySummary;
use App\Services\FifoCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class TaxReportController extends Controller
{
    public function __construct(private FifoCalculatorService $fifo)
    {
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

        // Se não houver resumos, tenta inferir dos anos de transações
        if (empty($years)) {
            $years = \App\Models\Transaction::where('user_id', $user->id)
                ->selectRaw('YEAR(date) as year')
                ->distinct()
                ->orderByDesc('year')
                ->pluck('year')
                ->toArray();
        }

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
        $user = Auth::user();

        try {
            $stats = $this->fifo->recalculateForUser($user->id);

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
