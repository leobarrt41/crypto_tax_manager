<?php

namespace App\Http\Controllers;

use App\Models\CryptoAsset;
use App\Services\PortfolioMetricsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PortfolioController extends Controller
{
    private const PERIODS = ['24h', '7d', '30d', '90d', '1y', 'all'];

    public function __construct(private readonly PortfolioMetricsService $metrics)
    {
    }

    /**
     * Página principal do Portfólio. O contrato entregue ao Vue é construído a
     * partir de wallets, wallet_balances, crypto_assets e transactions.
     */
    public function index(Request $request): Response
    {
        $period = $this->period($request);
        $user = $request->user();

        return Inertia::render('Portfolio/Index', [
            'portfolio' => $this->metrics->overview($user, $period),
            'recentActivity' => $this->metrics->recentActivity($user),
        ]);
    }

    public function analytics(Request $request): Response
    {
        $period = $this->period($request);

        return Inertia::render('Portfolio/Analytics', [
            'portfolio' => $this->metrics->overview($request->user(), $period),
            'performance' => $this->metrics->performance($request->user(), $period),
        ]);
    }

    public function performance(Request $request): Response
    {
        $period = $this->period($request);

        return Inertia::render('Portfolio/Performance', [
            'performance' => $this->metrics->performance($request->user(), $period),
        ]);
    }

    public function allocation(Request $request): Response
    {
        return Inertia::render('Portfolio/Allocation', [
            'allocation' => $this->metrics->allocation($request->user()),
        ]);
    }

    /**
     * Endpoint JSON de resumo, usado em atualização sem troca de página.
     */
    public function apiSummary(Request $request)
    {
        return response()->json($this->metrics->overview($request->user(), $this->period($request)));
    }

    /**
     * Endpoint JSON da série de snapshots reais. Sem snapshots, devolve uma
     * coleção vazia — nunca dados simulados.
     */
    public function apiChartData(Request $request)
    {
        return response()->json($this->metrics->history($request->user(), $this->period($request)));
    }

    public function apiAllocationData(Request $request)
    {
        return response()->json($this->metrics->allocation($request->user()));
    }

    /**
     * Compatibilidade com consumidores que já chamavam o resumo diretamente.
     */
    public function overview(Request $request)
    {
        return $this->apiSummary($request);
    }

    public function history(Request $request)
    {
        return $this->apiChartData($request);
    }

    public function assetDetails(Request $request, string $symbol): Response
    {
        $asset = CryptoAsset::query()->where('symbol', strtoupper($symbol))->firstOrFail();
        $portfolio = $this->metrics->overview($request->user(), 'all');
        $position = collect($portfolio['assets'])->firstWhere('symbol', $asset->symbol);

        $transactions = $request->user()->transactions()
            ->where(function ($query) use ($asset) {
                $query->where('from_asset', $asset->symbol)
                    ->orWhere('to_asset', $asset->symbol);
            })
            ->with(['fromCryptoAsset:symbol,name', 'toCryptoAsset:symbol,name', 'source'])
            ->orderBy('date', 'desc')
            ->paginate(20);

        return Inertia::render('Portfolio/AssetDetails', [
            'asset' => $asset,
            'position' => $position,
            'transactions' => $transactions,
        ]);
    }

    /**
     * Mantém a rota de análise de P&L com os valores efetivamente calculados
     * para a posição atual, sem depender dos campos legados de transactions.
     */
    public function profitLoss(Request $request)
    {
        $portfolio = $this->metrics->overview($request->user(), $this->period($request));

        return response()->json([
            'unrealized_profit_loss_brl' => $portfolio['total_pnl'],
            'total_invested_brl' => $portfolio['total_invested'],
            'total_profit_loss_percentage' => $portfolio['total_profit_loss_percentage'],
            'cost_basis_coverage_percentage' => $portfolio['cost_basis_coverage_percentage'],
            'assets' => $portfolio['assets'],
        ]);
    }

    public function diversification(Request $request)
    {
        $portfolio = $this->metrics->overview($request->user());

        return response()->json([
            'diversification_score' => $portfolio['diversification_score'],
            'assets_count' => $portfolio['assets_count'],
            'allocations' => $portfolio['allocations'],
        ]);
    }

    private function period(Request $request): string
    {
        $period = (string) $request->query('period', '30d');

        return in_array($period, self::PERIODS, true) ? $period : '30d';
    }
}
