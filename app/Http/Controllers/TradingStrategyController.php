<?php

namespace App\Http\Controllers;

use App\Models\TradingStrategy;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TradingStrategyController extends Controller
{
    /**
     * Display a listing of trading strategies.
     */
    public function index()
    {
        $strategies = auth()->user()->tradingStrategies()
            ->with(['botOrders' => function($query) {
                $query->latest()->limit(5);
            }])
            ->orderBy('name')
            ->get();

        return Inertia::render('TradingStrategies/Index', [
            'strategies' => $strategies
        ]);
    }

    /**
     * Show the form for creating a new trading strategy.
     */
    public function create()
    {
        return Inertia::render('TradingStrategies/Create', [
            'strategyTypes' => $this->getStrategyTypes(),
            'exchanges' => $this->getAvailableExchanges()
        ]);
    }

    /**
     * Store a newly created trading strategy.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'strategy_type' => 'required|in:dca,grid,scalping,arbitrage,momentum,mean_reversion',
            'base_asset' => 'required|string|max:10',
            'quote_asset' => 'required|string|max:10',
            'exchange' => 'required|string|max:50',
            'is_active' => 'boolean',
            'risk_level' => 'required|in:low,medium,high',
            'max_investment' => 'required|numeric|min:0',
            'stop_loss_percentage' => 'nullable|numeric|min:0|max:100',
            'take_profit_percentage' => 'nullable|numeric|min:0',
            'parameters' => 'required|json'
        ]);

        $strategy = auth()->user()->tradingStrategies()->create($validated);

        return redirect()->route('trading-strategies.index')
            ->with('success', 'Estratégia de trading criada com sucesso!');
    }

    /**
     * Display the specified trading strategy.
     */
    public function show(TradingStrategy $tradingStrategy)
    {
        $this->authorize('view', $tradingStrategy);

        $tradingStrategy->load([
            'botOrders' => function($query) {
                $query->latest()->limit(20);
            },
            'tradingLogs' => function($query) {
                $query->latest()->limit(50);
            }
        ]);

        return Inertia::render('TradingStrategies/Show', [
            'strategy' => $tradingStrategy,
            'performance' => $this->getStrategyPerformance($tradingStrategy)
        ]);
    }

    /**
     * Show the form for editing the specified trading strategy.
     */
    public function edit(TradingStrategy $tradingStrategy)
    {
        $this->authorize('update', $tradingStrategy);

        return Inertia::render('TradingStrategies/Edit', [
            'strategy' => $tradingStrategy,
            'strategyTypes' => $this->getStrategyTypes(),
            'exchanges' => $this->getAvailableExchanges()
        ]);
    }

    /**
     * Update the specified trading strategy.
     */
    public function update(Request $request, TradingStrategy $tradingStrategy)
    {
        $this->authorize('update', $tradingStrategy);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'strategy_type' => 'required|in:dca,grid,scalping,arbitrage,momentum,mean_reversion',
            'base_asset' => 'required|string|max:10',
            'quote_asset' => 'required|string|max:10',
            'exchange' => 'required|string|max:50',
            'is_active' => 'boolean',
            'risk_level' => 'required|in:low,medium,high',
            'max_investment' => 'required|numeric|min:0',
            'stop_loss_percentage' => 'nullable|numeric|min:0|max:100',
            'take_profit_percentage' => 'nullable|numeric|min:0',
            'parameters' => 'required|json'
        ]);

        $tradingStrategy->update($validated);

        return redirect()->route('trading-strategies.index')
            ->with('success', 'Estratégia de trading atualizada com sucesso!');
    }

    /**
     * Remove the specified trading strategy.
     */
    public function destroy(TradingStrategy $tradingStrategy)
    {
        $this->authorize('delete', $tradingStrategy);

        // Verificar se há ordens ativas
        if ($tradingStrategy->botOrders()->where('status', 'active')->exists()) {
            return redirect()->back()
                ->with('error', 'Não é possível excluir estratégia com ordens ativas.');
        }

        $tradingStrategy->delete();

        return redirect()->route('trading-strategies.index')
            ->with('success', 'Estratégia de trading removida com sucesso!');
    }

    /**
     * Start trading strategy.
     */
    public function start(TradingStrategy $tradingStrategy)
    {
        $this->authorize('update', $tradingStrategy);

        try {
            $tradingStrategy->update(['is_active' => true]);
            
            // Implementar início da estratégia
            // Usar TradingBotEngine service
            
            return response()->json([
                'message' => 'Estratégia iniciada com sucesso!',
                'strategy' => $tradingStrategy
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao iniciar estratégia: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Stop trading strategy.
     */
    public function stop(TradingStrategy $tradingStrategy)
    {
        $this->authorize('update', $tradingStrategy);

        try {
            $tradingStrategy->update(['is_active' => false]);
            
            // Implementar parada da estratégia
            // Cancelar ordens ativas
            
            return response()->json([
                'message' => 'Estratégia parada com sucesso!',
                'strategy' => $tradingStrategy
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao parar estratégia: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Backtest trading strategy.
     */
    public function backtest(Request $request, TradingStrategy $tradingStrategy)
    {
        $this->authorize('view', $tradingStrategy);

        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'initial_balance' => 'required|numeric|min:0'
        ]);

        try {
            // Implementar backtesting
            // Chamar serviço Python
            
            $backtestResult = [
                'total_return' => 0,
                'sharpe_ratio' => 0,
                'max_drawdown' => 0,
                'win_rate' => 0,
                'total_trades' => 0,
                'profit_factor' => 0
            ];

            return response()->json([
                'message' => 'Backtest realizado com sucesso!',
                'result' => $backtestResult
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao realizar backtest: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get strategy types.
     */
    private function getStrategyTypes()
    {
        return [
            'dca' => 'Dollar Cost Averaging',
            'grid' => 'Grid Trading',
            'scalping' => 'Scalping',
            'arbitrage' => 'Arbitragem',
            'momentum' => 'Momentum',
            'mean_reversion' => 'Reversão à Média'
        ];
    }

    /**
     * Get available exchanges.
     */
    private function getAvailableExchanges()
    {
        return [
            'binance' => 'Binance',
            'coinbase' => 'Coinbase',
            'kraken' => 'Kraken',
            'mercadobitcoin' => 'Mercado Bitcoin'
        ];
    }

    /**
     * Get strategy performance.
     */
    private function getStrategyPerformance(TradingStrategy $strategy)
    {
        // Implementar cálculo de performance
        return [
            'total_profit' => 0,
            'total_trades' => $strategy->botOrders()->count(),
            'win_rate' => 0,
            'avg_profit_per_trade' => 0,
            'max_drawdown' => 0,
            'sharpe_ratio' => 0
        ];
    }
}

