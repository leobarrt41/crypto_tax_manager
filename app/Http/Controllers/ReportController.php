<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\IN1888Service;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

class ReportController extends Controller
{
    protected $in1888Service;

    public function __construct(IN1888Service $in1888Service)
    {
        $this->in1888Service = $in1888Service;
    }

    /**
     * Display reports dashboard.
     */
    public function index()
    {
        return Inertia::render('Reports/Index', [
            'availableReports' => $this->getAvailableReports()
        ]);
    }

    /**
     * Generate tax report.
     */
    public function taxReport(Request $request)
    {
        $validated = $request->validate([
            'year' => 'required|integer|min:2020|max:' . date('Y'),
            'format' => 'required|in:pdf,excel,json'
        ]);

        try {
            $year = $validated['year'];
            $startDate = Carbon::createFromDate($year, 1, 1)->startOfDay();
            $endDate = Carbon::createFromDate($year, 12, 31)->endOfDay();

            // Buscar transações do ano
            $transactions = auth()->user()->transactions()
                ->whereBetween('executed_at', [$startDate, $endDate])
                ->with(['cryptoAsset'])
                ->orderBy('executed_at')
                ->get();

            // Calcular impostos
            $taxCalculation = $this->calculateTaxes($transactions);

            // Gerar relatório
            $report = [
                'year' => $year,
                'period' => [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d')
                ],
                'summary' => $taxCalculation,
                'transactions' => $transactions,
                'generated_at' => now()->toISOString()
            ];

            return response()->json([
                'message' => 'Relatório fiscal gerado com sucesso!',
                'report' => $report
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao gerar relatório fiscal: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Gera o arquivo legado somente quando a competência ainda estiver sob a
     * IN 1888. Para DeCripto, devolve uma resposta explícita, sem produzir um
     * TXT no leiaute anterior.
     */
    public function in1888Report(Request $request)
    {
        $validated = $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2019|max:' . date('Y'),
            'validation_only' => 'nullable|boolean',
        ]);

        try {
            $report = $this->in1888Service->generateMonthlyFile(
                auth()->id(),
                (int) $validated['month'],
                (int) $validated['year'],
                (bool) ($validated['validation_only'] ?? false),
            );

            return response()->json([
                'message' => $report['message'] ?? 'Arquivo fiscal processado com sucesso.',
                'report' => $report,
            ], ($report['required'] && !$report['export_available']) ? 422 : 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao processar a declaração de criptoativos: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate portfolio report.
     */
    public function portfolioReport(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'include_charts' => 'boolean'
        ]);

        try {
            $startDate = Carbon::parse($validated['start_date']);
            $endDate = Carbon::parse($validated['end_date']);

            // Buscar transações do período
            $transactions = auth()->user()->transactions()
                ->whereBetween('executed_at', [$startDate, $endDate])
                ->with(['cryptoAsset'])
                ->get();

            // Calcular portfolio
            $portfolio = $this->calculatePortfolio($transactions);

            // Gerar gráficos se solicitado
            $charts = [];
            if ($validated['include_charts'] ?? false) {
                $charts = $this->generatePortfolioCharts($transactions, $startDate, $endDate);
            }

            $report = [
                'period' => [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d')
                ],
                'portfolio' => $portfolio,
                'charts' => $charts,
                'generated_at' => now()->toISOString()
            ];

            return response()->json([
                'message' => 'Relatório de portfolio gerado com sucesso!',
                'report' => $report
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao gerar relatório de portfolio: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate trading performance report.
     */
    public function tradingPerformanceReport(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'strategy_id' => 'nullable|exists:trading_strategies,id'
        ]);

        try {
            $startDate = Carbon::parse($validated['start_date']);
            $endDate = Carbon::parse($validated['end_date']);

            // Buscar ordens do bot
            $query = auth()->user()->botOrders()
                ->whereBetween('created_at', [$startDate, $endDate])
                ->with(['tradingStrategy', 'cryptoAsset']);

            if (isset($validated['strategy_id'])) {
                $query->where('trading_strategy_id', $validated['strategy_id']);
            }

            $orders = $query->get();

            // Calcular performance
            $performance = $this->calculateTradingPerformance($orders);

            $report = [
                'period' => [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d')
                ],
                'performance' => $performance,
                'orders' => $orders,
                'generated_at' => now()->toISOString()
            ];

            return response()->json([
                'message' => 'Relatório de performance gerado com sucesso!',
                'report' => $report
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao gerar relatório de performance: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export report to file.
     */
    public function export(Request $request)
    {
        $validated = $request->validate([
            'report_type' => 'required|in:tax,in1888,portfolio,trading_performance',
            'format' => 'required|in:pdf,excel,csv,json',
            'parameters' => 'required|array'
        ]);

        try {
            // Gerar relatório baseado no tipo
            $report = null;
            switch ($validated['report_type']) {
                case 'tax':
                    $report = $this->taxReport(new Request($validated['parameters']));
                    break;
                case 'in1888':
                    $report = $this->in1888Report(new Request($validated['parameters']));
                    break;
                case 'portfolio':
                    $report = $this->portfolioReport(new Request($validated['parameters']));
                    break;
                case 'trading_performance':
                    $report = $this->tradingPerformanceReport(new Request($validated['parameters']));
                    break;
            }

            // Implementar exportação para arquivo
            $filename = $validated['report_type'] . '_report_' . now()->format('Y-m-d_H-i-s') . '.' . $validated['format'];

            return response()->json([
                'message' => 'Relatório exportado com sucesso!',
                'filename' => $filename,
                'download_url' => '/reports/download/' . $filename
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao exportar relatório: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available reports.
     */
    private function getAvailableReports()
    {
        return [
            'tax' => [
                'name' => 'Relatório Fiscal',
                'description' => 'Relatório completo de impostos sobre criptomoedas',
                'parameters' => ['year']
            ],
            'in1888' => [
                'name' => 'IN 1888',
                'description' => 'Declaração mensal de operações com criptomoedas',
                'parameters' => ['month', 'year']
            ],
            'portfolio' => [
                'name' => 'Relatório de Portfolio',
                'description' => 'Análise detalhada do portfolio de criptomoedas',
                'parameters' => ['start_date', 'end_date']
            ],
            'trading_performance' => [
                'name' => 'Performance de Trading',
                'description' => 'Análise de performance das estratégias de trading',
                'parameters' => ['start_date', 'end_date', 'strategy_id']
            ]
        ];
    }

    /**
     * Calculate taxes for transactions.
     */
    private function calculateTaxes($transactions)
    {
        // Implementar cálculo de impostos
        return [
            'total_gains' => 0,
            'total_losses' => 0,
            'net_result' => 0,
            'tax_owed' => 0,
            'exemption_used' => 0,
            'transactions_count' => $transactions->count()
        ];
    }

    /**
     * Calculate portfolio metrics.
     */
    private function calculatePortfolio($transactions)
    {
        // Implementar cálculo de portfolio
        return [
            'total_invested' => 0,
            'current_value' => 0,
            'total_return' => 0,
            'return_percentage' => 0,
            'assets_count' => 0,
            'transactions_count' => $transactions->count()
        ];
    }

    /**
     * Generate portfolio charts.
     */
    private function generatePortfolioCharts($transactions, $startDate, $endDate)
    {
        // Implementar geração de gráficos
        return [
            'value_evolution' => [],
            'asset_allocation' => [],
            'profit_loss' => []
        ];
    }

    /**
     * Renderiza a página IN 1888 via Inertia.
     * Os dados de obrigatoriedade mensal são carregados via API pelo frontend.
     */
    public function in1888(Request $request)
    {
        $user = auth()->user();

        // Anos disponíveis: do ano atual até 2019
        $currentYear = (int) now()->year;
        $availableYears = range($currentYear, 2019);

        return Inertia::render('Reports/IN1888', [
            'availableYears' => $availableYears,
            'declarantInfo'  => [
                'name' => $user->name ?? '',
                'cpf'  => $user->cpf  ?? '',
            ],
        ]);
    }

    /**
     * Calculate trading performance.
     */
    private function calculateTradingPerformance($orders)
    {
        // Implementar cálculo de performance
        return [
            'total_orders' => $orders->count(),
            'completed_orders' => $orders->where('status', 'completed')->count(),
            'success_rate' => 0,
            'total_profit' => 0,
            'avg_profit_per_trade' => 0,
            'max_drawdown' => 0,
            'sharpe_ratio' => 0
        ];
    }
}

