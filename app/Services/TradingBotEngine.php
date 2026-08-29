<?php

namespace App\Services;

use App\Models\TradingStrategy;
use App\Models\BotOrder;
use App\Models\TradingLog;
use App\Models\UserApiKey;
use App\Models\WalletBalance;
use App\Services\ExchangeConnector;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TradingBotEngine
{
    protected $exchangeConnector;
    protected $isRunning = false;
    protected $strategies = [];

    public function __construct(ExchangeConnector $exchangeConnector)
    {
        $this->exchangeConnector = $exchangeConnector;
    }

    /**
     * Iniciar o engine do trading bot
     */
    public function start()
    {
        Log::warning('Tentativa de iniciar o motor de trading bloqueada.', [
            'reason' => 'A Fase 0 não permite monitoramento automático nem envio de ordens.',
        ]);

        return false;
    }

    /**
     * Parar o engine do trading bot
     */
    public function stop()
    {
        $this->isRunning = false;
        Log::info('Motor de trading mantido parado pela política da Fase 0.');
    }

    /**
     * Loop principal de execução
     */
    protected function mainLoop()
    {
        while ($this->isRunning) {
            try {
                // Recarregar estratégias ativas a cada ciclo
                $this->loadActiveStrategies();

                // Executar cada estratégia
                foreach ($this->strategies as $strategy) {
                    $this->executeStrategy($strategy);
                }

                // Aguardar antes do próximo ciclo (30 segundos)
                sleep(30);

            } catch (\Exception $e) {
                $this->log('Erro no loop principal: ' . $e->getMessage(), 'error');
                sleep(60); // Aguardar mais tempo em caso de erro
            }
        }
    }

    /**
     * Carregar estratégias ativas
     */
    protected function loadActiveStrategies()
    {
        $this->strategies = TradingStrategy::where('is_active', true)
            ->with(['user', 'user.apiKeys'])
            ->get();

        $this->log('Carregadas ' . count($this->strategies) . ' estratégias ativas');
    }

    /**
     * Executar uma estratégia específica
     */
    protected function executeStrategy(TradingStrategy $strategy)
    {
        try {
            $this->log("Executando estratégia: {$strategy->name} (ID: {$strategy->id})");

            // Verificar se o usuário tem API key configurada
            $apiKey = $this->getUserApiKey($strategy->user_id, $strategy->parameters['exchange'] ?? 'binance');
            if (!$apiKey) {
                $this->log("API Key não encontrada para usuário {$strategy->user_id}", 'warning', $strategy->id);
                return;
            }

            // Executar baseado no tipo de estratégia
            switch ($strategy->type) {
                case 'DCA':
                    $this->executeDCAStrategy($strategy, $apiKey);
                    break;
                case 'GRID':
                    $this->executeGridStrategy($strategy, $apiKey);
                    break;
                case 'SCALPING':
                    $this->executeScalpingStrategy($strategy, $apiKey);
                    break;
                case 'ARBITRAGE':
                    $this->executeArbitrageStrategy($strategy, $apiKey);
                    break;
                default:
                    $this->log("Tipo de estratégia não suportado: {$strategy->type}", 'warning', $strategy->id);
            }

        } catch (\Exception $e) {
            $this->log("Erro ao executar estratégia {$strategy->id}: " . $e->getMessage(), 'error', $strategy->id);
        }
    }

    /**
     * Executar estratégia DCA (Dollar Cost Averaging)
     */
    protected function executeDCAStrategy(TradingStrategy $strategy, UserApiKey $apiKey)
    {
        $params = $strategy->parameters;
        $pair = $params['pair'] ?? 'BTCUSDT';
        $amount = $params['amount'] ?? 100;
        $interval = $params['interval'] ?? '1h';

        // Verificar se é hora de executar
        $lastOrder = BotOrder::where('trading_strategy_id', $strategy->id)
            ->orderBy('executed_at', 'desc')
            ->first();

        $shouldExecute = false;
        if (!$lastOrder) {
            $shouldExecute = true;
        } else {
            $nextExecution = $this->calculateNextExecution($lastOrder->executed_at, $interval);
            $shouldExecute = Carbon::now()->gte($nextExecution);
        }

        if (!$shouldExecute) {
            return;
        }

        // Verificar saldo disponível
        $baseAsset = $this->getBaseAsset($pair); // USDT para BTCUSDT
        $balance = $this->getBalance($strategy->user_id, $apiKey, $baseAsset);

        if ($balance < $amount) {
            $this->log("Saldo insuficiente para DCA. Necessário: {$amount}, Disponível: {$balance}", 'warning', $strategy->id);
            return;
        }

        // Obter preço atual
        $currentPrice = $this->exchangeConnector->getCurrentPrice($apiKey, $pair);
        if (!$currentPrice) {
            $this->log("Não foi possível obter preço para {$pair}", 'error', $strategy->id);
            return;
        }

        // Calcular quantidade
        $quantity = $amount / $currentPrice;

        // Executar ordem de compra
        $orderResult = $this->exchangeConnector->placeOrder($apiKey, [
            'symbol' => $pair,
            'side' => 'BUY',
            'type' => 'MARKET',
            'quoteOrderQty' => $amount
        ]);

        if ($orderResult['success']) {
            // Registrar ordem no banco
            $this->recordBotOrder($strategy, $orderResult['data'], 'buy', $quantity, $currentPrice);
            $this->log("DCA executado: Compra de {$quantity} {$pair} por {$amount} USDT", 'info', $strategy->id);
        } else {
            $this->log("Erro ao executar DCA: " . $orderResult['error'], 'error', $strategy->id);
        }
    }

    /**
     * Executar estratégia Grid Trading
     */
    protected function executeGridStrategy(TradingStrategy $strategy, UserApiKey $apiKey)
    {
        $params = $strategy->parameters;
        $pair = $params['pair'] ?? 'BTCUSDT';
        $gridSize = $params['grid_size'] ?? 0.5; // 0.5%
        $upperLimit = $params['upper_limit'] ?? 50000;
        $lowerLimit = $params['lower_limit'] ?? 40000;
        $quantityPerGrid = $params['quantity_per_grid'] ?? 0.001;

        // Obter preço atual
        $currentPrice = $this->exchangeConnector->getCurrentPrice($apiKey, $pair);
        if (!$currentPrice) {
            return;
        }

        // Verificar ordens abertas
        $openOrders = $this->exchangeConnector->getOpenOrders($apiKey, $pair);

        // Calcular níveis de grid
        $gridLevels = $this->calculateGridLevels($lowerLimit, $upperLimit, $gridSize);

        // Verificar se precisamos colocar ordens
        foreach ($gridLevels as $level) {
            $hasOrderAtLevel = $this->hasOrderAtLevel($openOrders, $level, 0.1); // 0.1% tolerância

            if (!$hasOrderAtLevel) {
                if ($level < $currentPrice) {
                    // Colocar ordem de compra
                    $this->placeGridOrder($strategy, $apiKey, $pair, 'BUY', $quantityPerGrid, $level);
                } else {
                    // Colocar ordem de venda
                    $this->placeGridOrder($strategy, $apiKey, $pair, 'SELL', $quantityPerGrid, $level);
                }
            }
        }
    }

    /**
     * Executar estratégia Scalping
     */
    protected function executeScalpingStrategy(TradingStrategy $strategy, UserApiKey $apiKey)
    {
        $params = $strategy->parameters;
        $pair = $params['pair'] ?? 'BTCUSDT';
        $profitTarget = $params['profit_target'] ?? 0.2; // 0.2%
        $stopLoss = $params['stop_loss'] ?? 0.1; // 0.1%
        $quantity = $params['quantity'] ?? 0.001;

        // Obter dados de mercado
        $marketData = $this->exchangeConnector->getMarketData($apiKey, $pair);
        if (!$marketData) {
            return;
        }

        // Analisar sinais de entrada
        $signal = $this->analyzeScalpingSignals($marketData);

        if ($signal['action'] === 'BUY') {
            $this->executeScalpingEntry($strategy, $apiKey, $pair, 'BUY', $quantity, $signal['price'], $profitTarget, $stopLoss);
        } elseif ($signal['action'] === 'SELL') {
            $this->executeScalpingEntry($strategy, $apiKey, $pair, 'SELL', $quantity, $signal['price'], $profitTarget, $stopLoss);
        }
    }

    /**
     * Executar estratégia de Arbitragem
     */
    protected function executeArbitrageStrategy(TradingStrategy $strategy, UserApiKey $apiKey)
    {
        $params = $strategy->parameters;
        $pair = $params['pair'] ?? 'BTCUSDT';
        $minProfitPercent = $params['min_profit'] ?? 0.5; // 0.5%

        // Obter preços de múltiplas exchanges
        $prices = $this->getMultiExchangePrices($pair);

        // Encontrar oportunidades de arbitragem
        $opportunity = $this->findArbitrageOpportunity($prices, $minProfitPercent);

        if ($opportunity) {
            $this->executeArbitrageOpportunity($strategy, $opportunity);
        }
    }

    /**
     * Obter saldo de um asset
     */
    protected function getBalance($userId, UserApiKey $apiKey, $asset)
    {
        // Primeiro tentar obter da exchange
        $exchangeBalance = $this->exchangeConnector->getBalance($apiKey, $asset);
        
        if ($exchangeBalance !== null) {
            // Atualizar saldo local
            $this->updateLocalBalance($userId, $asset, $exchangeBalance);
            return $exchangeBalance;
        }

        // Fallback para saldo local
        $walletBalance = WalletBalance::whereHas('wallet', function($query) use ($userId) {
            $query->where('user_id', $userId);
        })->where('asset', $asset)->first();

        return $walletBalance ? $walletBalance->available : 0;
    }

    /**
     * Atualizar saldo local
     */
    protected function updateLocalBalance($userId, $asset, $balance)
    {
        // Implementar lógica para atualizar saldo local
        // Por simplicidade, não implementado aqui
    }

    /**
     * Obter API key do usuário para uma exchange
     */
    protected function getUserApiKey($userId, $exchange)
    {
        return UserApiKey::where('user_id', $userId)
            ->where('exchange', $exchange)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Calcular próxima execução baseada no intervalo
     */
    protected function calculateNextExecution($lastExecution, $interval)
    {
        $lastTime = Carbon::parse($lastExecution);
        
        switch ($interval) {
            case '1m':
                return $lastTime->addMinute();
            case '5m':
                return $lastTime->addMinutes(5);
            case '15m':
                return $lastTime->addMinutes(15);
            case '30m':
                return $lastTime->addMinutes(30);
            case '1h':
                return $lastTime->addHour();
            case '4h':
                return $lastTime->addHours(4);
            case '1d':
                return $lastTime->addDay();
            default:
                return $lastTime->addHour();
        }
    }

    /**
     * Obter asset base de um par
     */
    protected function getBaseAsset($pair)
    {
        // BTCUSDT -> USDT, ETHBTC -> BTC
        if (str_ends_with($pair, 'USDT')) {
            return 'USDT';
        } elseif (str_ends_with($pair, 'BTC')) {
            return 'BTC';
        } elseif (str_ends_with($pair, 'ETH')) {
            return 'ETH';
        } elseif (str_ends_with($pair, 'BNB')) {
            return 'BNB';
        }
        
        return 'USDT'; // Default
    }

    /**
     * Registrar ordem do bot
     */
    protected function recordBotOrder(TradingStrategy $strategy, $orderData, $side, $quantity, $price)
    {
        BotOrder::create([
            'user_id' => $strategy->user_id,
            'trading_strategy_id' => $strategy->id,
            'exchange_order_id' => $orderData['orderId'] ?? null,
            'pair' => $orderData['symbol'] ?? '',
            'side' => $side,
            'quantity' => $quantity,
            'price' => $price,
            'executed_at' => now()
        ]);
    }

    /**
     * Calcular níveis de grid
     */
    protected function calculateGridLevels($lowerLimit, $upperLimit, $gridSize)
    {
        $levels = [];
        $currentLevel = $lowerLimit;
        
        while ($currentLevel <= $upperLimit) {
            $levels[] = $currentLevel;
            $currentLevel = $currentLevel * (1 + $gridSize / 100);
        }
        
        return $levels;
    }

    /**
     * Verificar se há ordem em um nível específico
     */
    protected function hasOrderAtLevel($openOrders, $level, $tolerance)
    {
        foreach ($openOrders as $order) {
            $orderPrice = floatval($order['price']);
            $diff = abs($orderPrice - $level) / $level;
            
            if ($diff <= $tolerance / 100) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Colocar ordem de grid
     */
    protected function placeGridOrder(TradingStrategy $strategy, UserApiKey $apiKey, $pair, $side, $quantity, $price)
    {
        $orderResult = $this->exchangeConnector->placeOrder($apiKey, [
            'symbol' => $pair,
            'side' => $side,
            'type' => 'LIMIT',
            'quantity' => $quantity,
            'price' => $price,
            'timeInForce' => 'GTC'
        ]);

        if ($orderResult['success']) {
            $this->recordBotOrder($strategy, $orderResult['data'], strtolower($side), $quantity, $price);
            $this->log("Grid order colocada: {$side} {$quantity} {$pair} a {$price}", 'info', $strategy->id);
        } else {
            $this->log("Erro ao colocar grid order: " . $orderResult['error'], 'error', $strategy->id);
        }
    }

    /**
     * Analisar sinais de scalping
     */
    protected function analyzeScalpingSignals($marketData)
    {
        // Implementar análise técnica simples
        // Por simplicidade, retornar sinal aleatório
        $actions = ['BUY', 'SELL', 'HOLD'];
        $action = $actions[array_rand($actions)];
        
        return [
            'action' => $action,
            'price' => $marketData['price'] ?? 0,
            'confidence' => rand(60, 95)
        ];
    }

    /**
     * Executar entrada de scalping
     */
    protected function executeScalpingEntry(TradingStrategy $strategy, UserApiKey $apiKey, $pair, $side, $quantity, $price, $profitTarget, $stopLoss)
    {
        // Colocar ordem de entrada
        $orderResult = $this->exchangeConnector->placeOrder($apiKey, [
            'symbol' => $pair,
            'side' => $side,
            'type' => 'MARKET',
            'quantity' => $quantity
        ]);

        if ($orderResult['success']) {
            $this->recordBotOrder($strategy, $orderResult['data'], strtolower($side), $quantity, $price);
            
            // Colocar ordens de take profit e stop loss
            $this->placeScalpingExitOrders($strategy, $apiKey, $pair, $side, $quantity, $price, $profitTarget, $stopLoss);
            
            $this->log("Scalping entry: {$side} {$quantity} {$pair} a {$price}", 'info', $strategy->id);
        }
    }

    /**
     * Colocar ordens de saída para scalping
     */
    protected function placeScalpingExitOrders(TradingStrategy $strategy, UserApiKey $apiKey, $pair, $side, $quantity, $entryPrice, $profitTarget, $stopLoss)
    {
        $exitSide = $side === 'BUY' ? 'SELL' : 'BUY';
        
        if ($side === 'BUY') {
            $takeProfitPrice = $entryPrice * (1 + $profitTarget / 100);
            $stopLossPrice = $entryPrice * (1 - $stopLoss / 100);
        } else {
            $takeProfitPrice = $entryPrice * (1 - $profitTarget / 100);
            $stopLossPrice = $entryPrice * (1 + $stopLoss / 100);
        }

        // Take Profit
        $this->exchangeConnector->placeOrder($apiKey, [
            'symbol' => $pair,
            'side' => $exitSide,
            'type' => 'LIMIT',
            'quantity' => $quantity,
            'price' => $takeProfitPrice,
            'timeInForce' => 'GTC'
        ]);

        // Stop Loss
        $this->exchangeConnector->placeOrder($apiKey, [
            'symbol' => $pair,
            'side' => $exitSide,
            'type' => 'STOP_MARKET',
            'quantity' => $quantity,
            'stopPrice' => $stopLossPrice
        ]);
    }

    /**
     * Obter preços de múltiplas exchanges
     */
    protected function getMultiExchangePrices($pair)
    {
        // Implementar busca de preços em múltiplas exchanges
        return [];
    }

    /**
     * Encontrar oportunidade de arbitragem
     */
    protected function findArbitrageOpportunity($prices, $minProfitPercent)
    {
        // Implementar lógica de arbitragem
        return null;
    }

    /**
     * Executar oportunidade de arbitragem
     */
    protected function executeArbitrageOpportunity(TradingStrategy $strategy, $opportunity)
    {
        // Implementar execução de arbitragem
    }

    /**
     * Log de atividades
     */
    protected function log($message, $level = 'info', $strategyId = null)
    {
        // Log no Laravel
        Log::channel('trading_bot')->{$level}($message);

        // Log no banco de dados se tiver strategy ID
        if ($strategyId) {
            TradingLog::create([
                'user_id' => TradingStrategy::find($strategyId)->user_id ?? null,
                'trading_strategy_id' => $strategyId,
                'message' => $message,
                'logged_at' => now()
            ]);
        }
    }

    /**
     * Verificar se o engine está rodando
     */
    public function isRunning()
    {
        return $this->isRunning;
    }

    /**
     * Obter estatísticas do engine
     */
    public function getStats()
    {
        return [
            'is_running' => $this->isRunning,
            'active_strategies' => count($this->strategies),
            'total_orders_today' => BotOrder::whereDate('executed_at', today())->count(),
            'total_profit_today' => $this->calculateDailyProfit(),
            'uptime' => $this->getUptime()
        ];
    }

    /**
     * Calcular lucro diário
     */
    protected function calculateDailyProfit()
    {
        // Implementar cálculo de lucro
        return 0;
    }

    /**
     * Obter tempo de atividade
     */
    protected function getUptime()
    {
        // Implementar cálculo de uptime
        return '00:00:00';
    }
}

