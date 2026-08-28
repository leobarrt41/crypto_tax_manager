<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\CryptoAsset;
use App\Models\WalletBalance;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

class PortfolioController extends Controller
{
    /**
     * Display portfolio overview.
     */
    public function index()
    {
        $portfolio = $this->getPortfolioOverview();
        $recentTransactions = $this->getRecentTransactions();
        $topAssets = $this->getTopAssets();

        return Inertia::render('Portfolio/Index', [
            'portfolio' => $portfolio,
            'recentTransactions' => $recentTransactions,
            'topAssets' => $topAssets
        ]);
    }

    /**
     * Get portfolio overview data.
     */
    public function overview()
    {
        $portfolio = $this->getPortfolioOverview();

        return response()->json($portfolio);
    }

    /**
     * Get portfolio performance.
     */
    public function performance(Request $request)
    {
        $validated = $request->validate([
            'period' => 'required|in:24h,7d,30d,90d,1y,all',
            'currency' => 'nullable|string|in:BRL,USD'
        ]);

        $period = $validated['period'];
        $currency = $validated['currency'] ?? 'BRL';

        $performance = $this->calculatePerformance($period, $currency);

        return response()->json($performance);
    }

    /**
     * Get asset allocation.
     */
    public function allocation()
    {
        $allocation = $this->getAssetAllocation();

        return response()->json($allocation);
    }

    /**
     * Get portfolio history.
     */
    public function history(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date',
            'interval' => 'nullable|in:1h,4h,1d,1w'
        ]);

        $startDate = $validated['start_date'] ? Carbon::parse($validated['start_date']) : Carbon::now()->subDays(30);
        $endDate = $validated['end_date'] ? Carbon::parse($validated['end_date']) : Carbon::now();
        $interval = $validated['interval'] ?? '1d';

        $history = $this->getPortfolioHistory($startDate, $endDate, $interval);

        return response()->json($history);
    }

    /**
     * Get asset details.
     */
    public function assetDetails(Request $request, $symbol)
    {
        $asset = CryptoAsset::where('symbol', strtoupper($symbol))->firstOrFail();

        $transactions = auth()->user()->transactions()
            ->where(function ($query) use ($asset) {
                $query->where('from_asset', $asset->symbol)
                    ->orWhere('to_asset', $asset->symbol);
            })
            ->with(['fromCryptoAsset', 'toCryptoAsset', 'source'])
            ->orderByRaw('COALESCE(executed_at, date) DESC')
            ->paginate(20);

        $balance = $this->getAssetBalance($asset);
        $performance = $this->getAssetPerformance($asset);

        return Inertia::render('Portfolio/AssetDetails', [
            'asset' => $asset,
            'transactions' => $transactions,
            'balance' => $balance,
            'performance' => $performance
        ]);
    }

    /**
     * Get profit/loss analysis.
     */
    public function profitLoss(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date',
            'asset_id' => 'nullable|exists:crypto_assets,id'
        ]);

        $query = auth()->user()->transactions()
            ->with(['fromCryptoAsset', 'toCryptoAsset', 'source']);

        if (isset($validated['start_date'])) {
            $query->where('executed_at', '>=', $validated['start_date']);
        }

        if (isset($validated['end_date'])) {
            $query->where('executed_at', '<=', $validated['end_date']);
        }

        if (isset($validated['asset_id'])) {
            $symbol = CryptoAsset::findOrFail($validated['asset_id'])->symbol;
            $query->where(function ($query) use ($symbol) {
                $query->where('from_asset', $symbol)
                    ->orWhere('to_asset', $symbol);
            });
        }

        $transactions = $query->orderBy('executed_at')->get();
        $analysis = $this->calculateProfitLoss($transactions);

        return response()->json($analysis);
    }

    /**
     * Get diversification analysis.
     */
    public function diversification()
    {
        $analysis = $this->getDiversificationAnalysis();

        return response()->json($analysis);
    }

    /**
     * Get portfolio rebalancing suggestions.
     */
    public function rebalancingSuggestions(Request $request)
    {
        $validated = $request->validate([
            'target_allocation' => 'required|array',
            'target_allocation.*.symbol' => 'required|string',
            'target_allocation.*.percentage' => 'required|numeric|min:0|max:100'
        ]);

        $suggestions = $this->getRebalancingSuggestions($validated['target_allocation']);

        return response()->json($suggestions);
    }

    /**
     * Export portfolio data.
     */
    public function export(Request $request)
    {
        $validated = $request->validate([
            'format' => 'required|in:csv,excel,pdf',
            'include_transactions' => 'boolean',
            'include_performance' => 'boolean'
        ]);

        try {
            $data = [
                'portfolio' => $this->getPortfolioOverview(),
                'allocation' => $this->getAssetAllocation()
            ];

            if ($validated['include_transactions'] ?? false) {
                $data['transactions'] = auth()->user()->transactions()
                    ->with(['fromCryptoAsset', 'toCryptoAsset', 'source'])
                    ->orderByRaw('COALESCE(executed_at, date) DESC')
                    ->get();
            }

            if ($validated['include_performance'] ?? false) {
                $data['performance'] = $this->calculatePerformance('all', 'BRL');
            }

            $filename = 'portfolio_' . now()->format('Y-m-d_H-i-s') . '.' . $validated['format'];

            return response()->json([
                'message' => 'Portfolio exportado com sucesso!',
                'filename' => $filename,
                'download_url' => '/portfolio/download/' . $filename
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao exportar portfolio: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get portfolio overview.
     */
    private function getPortfolioOverview()
    {
        $balances = auth()->user()->walletBalances()
            ->with(['cryptoAsset', 'wallet'])
            ->whereRaw('(available + locked) > 0')
            ->get();

        $totalValue = 0;
        $totalInvested = 0;
        $assets = [];

        foreach ($balances as $balance) {
            $asset = $balance->cryptoAsset;
            $quantity = (float) $balance->total;
            $currentPrice = (float) ($asset?->current_price_brl ?? 0);
            $value = $quantity * $currentPrice;
            $totalValue += $value;

            // Calcular valor investido (implementar lógica FIFO)
            $invested = $asset ? $this->calculateInvestedAmount($asset, $quantity) : 0;
            $totalInvested += $invested;

            $assets[] = [
                'symbol' => $asset?->symbol ?? $balance->asset,
                'name' => $asset?->name ?? $balance->asset,
                'balance' => $quantity,
                'current_price' => $currentPrice,
                'value' => $value,
                'invested' => $invested,
                'profit_loss' => $value - $invested,
                'profit_loss_percentage' => $invested > 0 ? (($value - $invested) / $invested) * 100 : 0
            ];
        }

        return [
            'total_value' => $totalValue,
            'total_invested' => $totalInvested,
            'total_profit_loss' => $totalValue - $totalInvested,
            'total_profit_loss_percentage' => $totalInvested > 0 ? (($totalValue - $totalInvested) / $totalInvested) * 100 : 0,
            'assets_count' => count($assets),
            'assets' => $assets
        ];
    }

    /**
     * Get recent transactions.
     */
    private function getRecentTransactions($limit = 10)
    {
        return auth()->user()->transactions()
            ->with(['fromCryptoAsset', 'toCryptoAsset', 'source'])
            ->orderByRaw('COALESCE(executed_at, date) DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * Get top assets by value.
     */
    private function getTopAssets($limit = 5)
    {
        $balances = auth()->user()->walletBalances()
            ->with(['cryptoAsset'])
            ->whereRaw('(available + locked) > 0')
            ->get();

        $assets = $balances->map(function ($balance) {
            $asset = $balance->cryptoAsset;
            $quantity = (float) $balance->total;
            $currentPrice = (float) ($asset?->current_price_brl ?? 0);
            $value = $quantity * $currentPrice;

            return [
                'symbol' => $asset?->symbol ?? $balance->asset,
                'name' => $asset?->name ?? $balance->asset,
                'balance' => $quantity,
                'value' => $value
            ];
        })->sortByDesc('value')->take($limit)->values();

        return $assets;
    }

    /**
     * Calculate performance for period.
     */
    private function calculatePerformance($period, $currency)
    {
        // Implementar cálculo de performance
        return [
            'period' => $period,
            'currency' => $currency,
            'start_value' => 0,
            'end_value' => 0,
            'absolute_change' => 0,
            'percentage_change' => 0,
            'best_performer' => null,
            'worst_performer' => null
        ];
    }

    /**
     * Get asset allocation.
     */
    private function getAssetAllocation()
    {
        $portfolio = $this->getPortfolioOverview();
        $totalValue = $portfolio['total_value'];

        if ($totalValue == 0) {
            return [];
        }

        return collect($portfolio['assets'])->map(function ($asset) use ($totalValue) {
            return [
                'symbol' => $asset['symbol'],
                'name' => $asset['name'],
                'value' => $asset['value'],
                'percentage' => ($asset['value'] / $totalValue) * 100
            ];
        })->sortByDesc('percentage')->values();
    }

    /**
     * Get portfolio history.
     */
    private function getPortfolioHistory($startDate, $endDate, $interval)
    {
        // Implementar histórico do portfolio
        return [
            'start_date' => $startDate->toISOString(),
            'end_date' => $endDate->toISOString(),
            'interval' => $interval,
            'data' => []
        ];
    }

    /**
     * Get asset balance.
     */
    private function getAssetBalance($asset)
    {
        $balance = auth()->user()->walletBalances()
            ->where('asset', $asset->symbol)
            ->selectRaw('COALESCE(SUM(available + locked), 0) as total')
            ->value('total');

        return [
            'total_balance' => $balance,
            'current_price' => $asset->current_price_brl ?? 0,
            'total_value' => $balance * ($asset->current_price_brl ?? 0)
        ];
    }

    /**
     * Get asset performance.
     */
    private function getAssetPerformance($asset)
    {
        // Implementar performance do ativo
        return [
            'price_change_24h' => 0,
            'price_change_7d' => 0,
            'price_change_30d' => 0,
            'volume_24h' => 0,
            'market_cap' => 0
        ];
    }

    /**
     * Calculate profit/loss.
     */
    private function calculateProfitLoss($transactions)
    {
        // Implementar cálculo de lucro/prejuízo
        return [
            'realized_profit_loss' => 0,
            'unrealized_profit_loss' => 0,
            'total_profit_loss' => 0,
            'transactions_count' => $transactions->count(),
            'profitable_trades' => 0,
            'losing_trades' => 0,
            'win_rate' => 0
        ];
    }

    /**
     * Get diversification analysis.
     */
    private function getDiversificationAnalysis()
    {
        // Implementar análise de diversificação
        return [
            'diversification_score' => 0,
            'concentration_risk' => 0,
            'top_3_concentration' => 0,
            'recommendations' => []
        ];
    }

    /**
     * Get rebalancing suggestions.
     */
    private function getRebalancingSuggestions($targetAllocation)
    {
        // Implementar sugestões de rebalanceamento
        return [
            'current_allocation' => $this->getAssetAllocation(),
            'target_allocation' => $targetAllocation,
            'suggestions' => []
        ];
    }

    /**
     * Calculate invested amount for asset.
     */
    private function calculateInvestedAmount($asset, $balance)
    {
        // Implementar cálculo FIFO do valor investido
        return 0;
    }
}
