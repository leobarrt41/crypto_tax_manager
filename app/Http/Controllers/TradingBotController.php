<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Artisan;
use App\Models\TradingStrategy;
use App\Models\BotOrder;
use App\Models\TradingLog;
use App\Models\UserApiKey;
use App\Services\TradingBotEngine;
use App\Services\ExchangeConnector;
use Inertia\Inertia;

class TradingBotController extends Controller
{
    protected $exchangeConnector;

    public function __construct(ExchangeConnector $exchangeConnector)
    {
        $this->exchangeConnector = $exchangeConnector;
    }

    /**
     * Exibir dashboard do trading bot
     */
    public function index()
    {
        $user = Auth::user();
        
        // Estatísticas gerais
        $stats = [
            'active_strategies' => TradingStrategy::where('user_id', $user->id)
                ->where('is_active', true)->count(),
            'total_orders' => BotOrder::where('user_id', $user->id)->count(),
            'orders_today' => BotOrder::where('user_id', $user->id)
                ->whereDate('executed_at', today())->count(),
            'profit_today' => $this->calculateDailyProfit($user->id),
            'bot_status' => $this->getBotStatus()
        ];

        // Estratégias do usuário
        $strategies = TradingStrategy::where('user_id', $user->id)
            ->with(['botOrders' => function($query) {
                $query->latest()->limit(5);
            }])
            ->orderBy('created_at', 'desc')
            ->get();

        // Ordens recentes
        $recentOrders = BotOrder::where('user_id', $user->id)
            ->with('tradingStrategy')
            ->orderBy('executed_at', 'desc')
            ->limit(10)
            ->get();

        // Logs recentes
        $recentLogs = TradingLog::where('user_id', $user->id)
            ->with('tradingStrategy')
            ->orderBy('logged_at', 'desc')
            ->limit(20)
            ->get();

        return Inertia::render('TradingBot/Dashboard', [
            'stats' => $stats,
            'strategies' => $strategies,
            'recentOrders' => $recentOrders,
            'recentLogs' => $recentLogs
        ]);
    }

    /**
     * Exibir formulário de criação de estratégia
     */
    public function create()
    {
        $user = Auth::user();
        
        // Verificar se o usuário tem API keys configuradas
        $apiKeys = UserApiKey::where('user_id', $user->id)
            ->where('is_active', true)
            ->get();

        if ($apiKeys->isEmpty()) {
            return redirect()->route('api-keys.index')
                ->with('warning', 'Configure suas chaves de API antes de criar estratégias de trading.');
        }

        return Inertia::render('TradingBot/CreateStrategy', [
            'apiKeys' => $apiKeys,
            'strategyTypes' => $this->getStrategyTypes(),
            'tradingPairs' => $this->getTradingPairs()
        ]);
    }

    /**
     * Salvar nova estratégia
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:DCA,GRID,SCALPING,ARBITRAGE',
            'parameters' => 'required|array',
            'parameters.exchange' => 'required|string',
            'parameters.pair' => 'required|string'
        ]);

        $user = Auth::user();

        // Validar parâmetros específicos por tipo
        $this->validateStrategyParameters($request->type, $request->parameters);

        // Verificar se o usuário tem API key para a exchange
        $apiKey = UserApiKey::where('user_id', $user->id)
            ->where('exchange', $request->parameters['exchange'])
            ->where('is_active', true)
            ->first();

        if (!$apiKey) {
            return back()->withErrors([
                'parameters.exchange' => 'Você não possui chave de API ativa para esta exchange.'
            ]);
        }

        // Testar conexão com a exchange
        $connectionTest = $this->exchangeConnector->testConnection($apiKey);
        if (!$connectionTest['success']) {
            return back()->withErrors([
                'parameters.exchange' => 'Erro ao conectar com a exchange: ' . $connectionTest['error']
            ]);
        }

        // Criar estratégia
        $strategy = TradingStrategy::create([
            'user_id' => $user->id,
            'name' => $request->name,
            'type' => $request->type,
            'parameters' => $request->parameters,
            'is_active' => true
        ]);

        // Log da criação
        TradingLog::create([
            'user_id' => $user->id,
            'trading_strategy_id' => $strategy->id,
            'message' => "Estratégia '{$strategy->name}' criada e ativada",
            'logged_at' => now()
        ]);

        return redirect()->route('trading-bot.index')
            ->with('success', 'Estratégia criada com sucesso!');
    }

    /**
     * Exibir detalhes de uma estratégia
     */
    public function show(TradingStrategy $strategy)
    {
        $this->authorize('view', $strategy);

        $strategy->load(['botOrders', 'tradingLogs']);

        // Estatísticas da estratégia
        $stats = [
            'total_orders' => $strategy->botOrders->count(),
            'buy_orders' => $strategy->botOrders->where('side', 'buy')->count(),
            'sell_orders' => $strategy->botOrders->where('side', 'sell')->count(),
            'total_volume' => $strategy->botOrders->sum(function($order) {
                return $order->quantity * $order->price;
            }),
            'profit_loss' => $this->calculateStrategyProfitLoss($strategy),
            'success_rate' => $this->calculateSuccessRate($strategy)
        ];

        // Performance por dia (últimos 30 dias)
        $performance = $this->getStrategyPerformance($strategy, 30);

        return Inertia::render('TradingBot/StrategyDetails', [
            'strategy' => $strategy,
            'stats' => $stats,
            'performance' => $performance
        ]);
    }

    /**
     * Ativar/Desativar estratégia
     */
    public function toggleStatus(TradingStrategy $strategy)
    {
        $this->authorize('update', $strategy);

        $strategy->update([
            'is_active' => !$strategy->is_active
        ]);

        $status = $strategy->is_active ? 'ativada' : 'desativada';
        
        TradingLog::create([
            'user_id' => $strategy->user_id,
            'trading_strategy_id' => $strategy->id,
            'message' => "Estratégia '{$strategy->name}' {$status}",
            'logged_at' => now()
        ]);

        return back()->with('success', "Estratégia {$status} com sucesso!");
    }

    /**
     * Excluir estratégia
     */
    public function destroy(TradingStrategy $strategy)
    {
        $this->authorize('delete', $strategy);

        $strategyName = $strategy->name;
        $strategy->delete();

        return redirect()->route('trading-bot.index')
            ->with('success', "Estratégia '{$strategyName}' excluída com sucesso!");
    }

    /**
     * Iniciar/Parar o bot
     */
    public function toggleBot(Request $request)
    {
        $action = $request->input('action'); // 'start' ou 'stop'

        try {
            if ($action === 'start') {
                Artisan::call('trading-bot:run');
                $message = 'Trading Bot iniciado com sucesso!';
            } else {
                Artisan::call('trading-bot:run', ['--stop' => true]);
                $message = 'Trading Bot parado com sucesso!';
            }

            return back()->with('success', $message);

        } catch (\Exception $e) {
            return back()->with('error', 'Erro ao controlar o bot: ' . $e->getMessage());
        }
    }

    /**
     * Obter estatísticas em tempo real
     */
    public function getStats()
    {
        $user = Auth::user();

        return response()->json([
            'active_strategies' => TradingStrategy::where('user_id', $user->id)
                ->where('is_active', true)->count(),
            'orders_today' => BotOrder::where('user_id', $user->id)
                ->whereDate('executed_at', today())->count(),
            'profit_today' => $this->calculateDailyProfit($user->id),
            'bot_status' => $this->getBotStatus()
        ]);
    }

    /**
     * Obter logs recentes
     */
    public function getLogs(Request $request)
    {
        $user = Auth::user();
        $limit = $request->input('limit', 50);

        $logs = TradingLog::where('user_id', $user->id)
            ->with('tradingStrategy')
            ->orderBy('logged_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json($logs);
    }

    // ==================== MÉTODOS AUXILIARES ====================

    protected function getStrategyTypes()
    {
        return [
            'DCA' => [
                'name' => 'Dollar Cost Averaging',
                'description' => 'Compras periódicas de valor fixo',
                'parameters' => [
                    'amount' => 'Valor por compra (USDT)',
                    'interval' => 'Intervalo entre compras',
                    'max_orders' => 'Máximo de ordens'
                ]
            ],
            'GRID' => [
                'name' => 'Grid Trading',
                'description' => 'Ordens de compra e venda em grade',
                'parameters' => [
                    'grid_size' => 'Tamanho da grade (%)',
                    'upper_limit' => 'Limite superior',
                    'lower_limit' => 'Limite inferior',
                    'quantity_per_grid' => 'Quantidade por nível'
                ]
            ],
            'SCALPING' => [
                'name' => 'Scalping',
                'description' => 'Operações rápidas com pequenos lucros',
                'parameters' => [
                    'profit_target' => 'Meta de lucro (%)',
                    'stop_loss' => 'Stop loss (%)',
                    'quantity' => 'Quantidade por operação'
                ]
            ],
            'ARBITRAGE' => [
                'name' => 'Arbitragem',
                'description' => 'Aproveitar diferenças de preço entre exchanges',
                'parameters' => [
                    'min_profit' => 'Lucro mínimo (%)',
                    'max_amount' => 'Valor máximo por operação'
                ]
            ]
        ];
    }

    protected function getTradingPairs()
    {
        return [
            'BTCUSDT', 'ETHUSDT', 'ADAUSDT', 'DOTUSDT', 'LINKUSDT',
            'BNBUSDT', 'XRPUSDT', 'LTCUSDT', 'BCHUSDT', 'EOSUSDT'
        ];
    }

    protected function validateStrategyParameters($type, $parameters)
    {
        switch ($type) {
            case 'DCA':
                if (!isset($parameters['amount']) || $parameters['amount'] <= 0) {
                    throw new \InvalidArgumentException('Valor por compra deve ser maior que zero');
                }
                break;
            case 'GRID':
                if (!isset($parameters['upper_limit']) || !isset($parameters['lower_limit'])) {
                    throw new \InvalidArgumentException('Limites superior e inferior são obrigatórios');
                }
                if ($parameters['upper_limit'] <= $parameters['lower_limit']) {
                    throw new \InvalidArgumentException('Limite superior deve ser maior que o inferior');
                }
                break;
            case 'SCALPING':
                if (!isset($parameters['profit_target']) || $parameters['profit_target'] <= 0) {
                    throw new \InvalidArgumentException('Meta de lucro deve ser maior que zero');
                }
                break;
            case 'ARBITRAGE':
                if (!isset($parameters['min_profit']) || $parameters['min_profit'] <= 0) {
                    throw new \InvalidArgumentException('Lucro mínimo deve ser maior que zero');
                }
                break;
        }
    }

    protected function calculateDailyProfit($userId)
    {
        // Implementar cálculo de lucro diário
        // Por simplicidade, retornar valor simulado
        return rand(-100, 500) / 10; // -10 a 50 USDT
    }

    protected function getBotStatus()
    {
        $pidFile = storage_path('app/trading_bot.pid');
        if (!file_exists($pidFile)) {
            return 'stopped';
        }

        $pid = trim(file_get_contents($pidFile));
        $result = exec("ps -p {$pid}");
        
        return !empty($result) ? 'running' : 'stopped';
    }

    protected function calculateStrategyProfitLoss(TradingStrategy $strategy)
    {
        // Implementar cálculo de P&L da estratégia
        return rand(-50, 200) / 10; // -5 a 20 USDT
    }

    protected function calculateSuccessRate(TradingStrategy $strategy)
    {
        // Implementar cálculo de taxa de sucesso
        return rand(60, 95); // 60% a 95%
    }

    protected function getStrategyPerformance(TradingStrategy $strategy, $days)
    {
        // Implementar dados de performance
        $performance = [];
        for ($i = $days; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $performance[] = [
                'date' => $date,
                'profit' => rand(-20, 50) / 10,
                'orders' => rand(0, 10)
            ];
        }
        return $performance;
    }
}

