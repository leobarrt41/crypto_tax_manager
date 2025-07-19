<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Models\Transaction;
use App\Models\CryptoAsset;
use App\Models\Wallet;
use App\Models\WalletBalance;
use App\Models\TradingStrategy;
use Carbon\Carbon;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * Display the dashboard with comprehensive statistics
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $period = $request->get('period', '30d');
        
        // Cache key for user dashboard data
        $cacheKey = "dashboard_data_{$user->id}_{$period}";
        
        $data = Cache::remember($cacheKey, 300, function () use ($user, $period) {
            return [
                'stats' => $this->getPortfolioStats($user, $period),
                'recentTransactions' => $this->getRecentTransactions($user),
                'topAssets' => $this->getTopPerformingAssets($user),
                'portfolioChartData' => $this->getPortfolioChartData($user, $period),
                'assetAllocationData' => $this->getAssetAllocationData($user),
                'marketData' => $this->getMarketOverview(),
            ];
        });

        return Inertia::render('Dashboard', $data);
    }

    /**
     * Get portfolio statistics - ADAPTADO PARA DASHBOARD.VUE
     */
    private function getPortfolioStats($user, $period)
    {
        $startDate = $this->getPeriodStartDate($period);
        $endDate = now();

        // Current portfolio value
        $currentPortfolioValue = $this->calculatePortfolioValue($user);
        
        // Previous period portfolio value for comparison
        $previousPeriodValue = $this->calculatePortfolioValue($user, $startDate);
        
        // Calculate portfolio change percentage
        $portfolioChange = $previousPeriodValue > 0 
            ? (($currentPortfolioValue - $previousPeriodValue) / $previousPeriodValue) * 100 
            : 0;

        // Total P&L calculation
        $totalPnL = $this->calculateTotalPnL($user);
        $previousPnL = $this->calculateTotalPnL($user, $startDate);
        $pnlChange = $previousPnL != 0 ? (($totalPnL - $previousPnL) / abs($previousPnL)) * 100 : 0;

        // Monthly transactions
        $monthlyTransactions = Transaction::where('user_id', $user->id)
            ->whereBetween('date', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        $previousMonthTransactions = Transaction::where('user_id', $user->id)
            ->whereBetween('date', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()])
            ->count();

        $transactionsChange = $previousMonthTransactions > 0 
            ? (($monthlyTransactions - $previousMonthTransactions) / $previousMonthTransactions) * 100 
            : 0;

        // Estimated taxes (simplified calculation)
        $estimatedTaxes = $this->calculateEstimatedTaxes($user);
        $previousTaxes = $this->calculateEstimatedTaxes($user, $startDate);
        $taxesChange = $previousTaxes != 0 ? (($estimatedTaxes - $previousTaxes) / abs($previousTaxes)) * 100 : 0;

        // IN 1888 Status
        $in1888Status = $this->getIN1888Status($user);

        // RETORNO ADAPTADO PARA DASHBOARD.VUE
        return [
            // Campos esperados pelo Dashboard.vue
            'portfolio_total' => $currentPortfolioValue,
            'portfolio_change' => $portfolioChange,
            'portfolio_variation' => $currentPortfolioValue - $previousPeriodValue,
            'monthly_pnl' => $totalPnL,
            'monthly_pnl_change' => $pnlChange,
            'monthly_transactions' => $monthlyTransactions,
            'in1888_status' => $in1888Status,
            
            // Campos adicionais (mantendo funcionalidades avançadas)
            'totalPortfolioValue' => $currentPortfolioValue,
            'totalPnL' => $totalPnL,
            'transactionsChange' => $transactionsChange,
            'estimatedTaxes' => $estimatedTaxes,
            'taxesChange' => $taxesChange,
        ];
    }

    /**
     * Get IN 1888 Status
     */
    private function getIN1888Status($user)
    {
        $currentMonth = now()->month;
        $currentYear = now()->year;
        
        $monthlyVolume = Transaction::where('user_id', $user->id)
            ->whereMonth('date', $currentMonth)
            ->whereYear('date', $currentYear)
            ->sum('total_brl');

        if ($monthlyVolume <= 30000) {
            return [
                'status' => 'not_required',
                'message' => 'Não obrigatória',
                'description' => 'Volume mensal abaixo de R$ 30.000',
                'volume' => $monthlyVolume,
            ];
        }

        // Verificar se já foi gerado (simulado)
        $hasFile = false; // Implementar verificação real

        return [
            'status' => $hasFile ? 'compliant' : 'pending',
            'message' => $hasFile ? 'Em dia' : 'Pendente',
            'description' => $hasFile ? 'Arquivo gerado' : 'Aguardando geração',
            'volume' => $monthlyVolume,
        ];
    }

    /**
     * Get recent transactions - ADAPTADO PARA DASHBOARD.VUE
     */
    private function getRecentTransactions($user, $limit = 5)
    {
        $transactions = Transaction::where('user_id', $user->id)
            ->with(['fromCryptoAsset', 'toCryptoAsset'])
            ->orderByDesc('date')
            ->limit($limit)
            ->get();

        // Se não há transações, retornar dados de exemplo
        if ($transactions->isEmpty()) {
            return [
                [
                    'id' => 1,
                    'type' => 'buy',
                    'asset' => 'BTC',
                    'amount' => 150000.00,
                    'quantity' => 0.5,
                    'date' => now()->subDays(1)->toISOString(),
                    'exchange' => 'Binance',
                ],
                [
                    'id' => 2,
                    'type' => 'sell',
                    'asset' => 'ETH',
                    'amount' => 25000.00,
                    'quantity' => 1.2,
                    'date' => now()->subDays(3)->toISOString(),
                    'exchange' => 'Coinbase',
                ],
                [
                    'id' => 3,
                    'type' => 'buy',
                    'asset' => 'BNB',
                    'amount' => 5000.00,
                    'quantity' => 20.0,
                    'date' => now()->subDays(5)->toISOString(),
                    'exchange' => 'Binance',
                ],
            ];
        }

        return $transactions->map(function ($transaction) {
            return [
                'id' => $transaction->id,
                'type' => $transaction->type,
                'asset' => $transaction->to_asset ?? $transaction->from_asset,
                'amount' => $transaction->total_brl,
                'quantity' => $transaction->to_amount ?? $transaction->from_amount,
                'date' => $transaction->date->toISOString(),
                'exchange' => $transaction->exchange ?? 'N/A',
            ];
        });
    }

    /**
     * Get top performing assets - ADAPTADO PARA DASHBOARD.VUE
     */
    private function getTopPerformingAssets($user, $limit = 5)
    {
        // Get user's assets from transactions
        $userAssets = Transaction::where('user_id', $user->id)
            ->whereNotNull('to_asset')
            ->select('to_asset')
            ->distinct()
            ->pluck('to_asset');

        $assets = CryptoAsset::whereIn('symbol', $userAssets)
            ->orderByDesc('price_change_24h')
            ->limit($limit)
            ->get();

        // Se não há assets, retornar dados de exemplo
        if ($assets->isEmpty()) {
            return [
                [
                    'symbol' => 'BTC',
                    'name' => 'Bitcoin',
                    'value' => 150000.00,
                    'change' => 5.2,
                    'balance' => 0.5,
                ],
                [
                    'symbol' => 'ETH',
                    'name' => 'Ethereum',
                    'value' => 80000.00,
                    'change' => -2.1,
                    'balance' => 2.3,
                ],
                [
                    'symbol' => 'BNB',
                    'name' => 'Binance Coin',
                    'value' => 25000.00,
                    'change' => 1.8,
                    'balance' => 10.5,
                ],
            ];
        }

        return $assets->map(function ($asset) {
            return [
                'symbol' => $asset->symbol,
                'name' => $asset->name,
                'value' => $asset->current_price_brl ?? 0,
                'change' => $asset->price_change_24h ?? 0,
                'balance' => 0, // Calcular saldo real
                'logo' => $this->getAssetLogo($asset->symbol),
            ];
        });
    }

    /**
     * Get portfolio chart data
     */
    private function getPortfolioChartData($user, $period)
    {
        $days = $this->getPeriodDays($period);
        $data = [];

        for ($i = $days; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $value = $this->calculatePortfolioValue($user, $date);
            
            $data[] = [
                'date' => $date->format('Y-m-d'),
                'value' => $value,
            ];
        }

        return $data;
    }

    /**
     * Get asset allocation data
     */
    private function getAssetAllocationData($user)
    {
        $allocations = DB::table('transactions')
            ->where('user_id', $user->id)
            ->whereNotNull('to_asset')
            ->select('to_asset', DB::raw('SUM(to_amount * COALESCE(price, 0)) as total_value'))
            ->groupBy('to_asset')
            ->orderByDesc('total_value')
            ->limit(10)
            ->get();

        $totalValue = $allocations->sum('total_value');

        return $allocations->map(function ($allocation) use ($totalValue) {
            return [
                'asset' => $allocation->to_asset,
                'value' => $allocation->total_value,
                'percentage' => $totalValue > 0 ? ($allocation->total_value / $totalValue) * 100 : 0,
            ];
        });
    }

    /**
     * Get market overview data
     */
    private function getMarketOverview()
    {
        return Cache::remember('market_overview', 600, function () {
            // This would typically come from an external API like CoinGecko
            return [
                'totalMarketCap' => 2500000000000, // $2.5T
                'marketCapChange' => 2.5,
                'totalVolume' => 85000000000, // $85B
                'btcDominance' => 42.3,
            ];
        });
    }

    /**
     * API endpoint for dashboard stats
     */
    public function apiStats(Request $request)
    {
        $user = auth()->user();
        $period = $request->get('period', '30d');
        
        return response()->json([
            'stats' => $this->getPortfolioStats($user, $period),
        ]);
    }

    /**
     * API endpoint for chart data
     */
    public function apiChartData(Request $request)
    {
        $user = auth()->user();
        $period = $request->get('period', '30d');
        
        return response()->json([
            'portfolioChart' => $this->getPortfolioChartData($user, $period),
            'assetAllocation' => $this->getAssetAllocationData($user),
        ]);
    }

    /**
     * Helper methods
     */
    private function getPeriodStartDate($period)
    {
        return match($period) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            '1y' => now()->subYear(),
            default => now()->subDays(30),
        };
    }

    private function getPeriodDays($period)
    {
        return match($period) {
            '7d' => 7,
            '30d' => 30,
            '90d' => 90,
            '1y' => 365,
            default => 30,
        };
    }

    private function calculatePortfolioValue($user, $date = null)
    {
        $date = $date ?? now();
        
        // Simplified calculation - in reality, you'd need historical prices
        $totalValue = Transaction::where('user_id', $user->id)
            ->where('date', '<=', $date)
            ->whereNotNull('total_brl')
            ->sum('total_brl');

        return abs($totalValue);
    }

    private function calculateTotalPnL($user, $date = null)
    {
        $date = $date ?? now();
        
        // Simplified P&L calculation
        $buys = Transaction::where('user_id', $user->id)
            ->where('date', '<=', $date)
            ->where('type', 'buy')
            ->sum('total_brl');

        $sells = Transaction::where('user_id', $user->id)
            ->where('date', '<=', $date)
            ->where('type', 'sell')
            ->sum('total_brl');

        return $sells - $buys;
    }

    private function calculateEstimatedTaxes($user, $date = null)
    {
        $date = $date ?? now();
        
        // Simplified tax calculation (15% on gains)
        $gains = $this->calculateTotalPnL($user, $date);
        return $gains > 0 ? $gains * 0.15 : 0;
    }

    private function getAssetLogo($symbol)
    {
        // Return placeholder or actual logo URL
        return "https://cryptologos.cc/logos/{$symbol}-logo.png";
    }
}
