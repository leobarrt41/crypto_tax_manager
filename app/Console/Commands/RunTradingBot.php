<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TradingBotEngine;
use App\Services\ExchangeConnector;

class RunTradingBot extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'trading-bot:run {--stop : Stop the trading bot}';

    /**
     * The console command description.
     */
    protected $description = 'Run or stop the trading bot engine';

    protected $tradingBot;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('stop')) {
            $this->stopTradingBot();
        } else {
            $this->startTradingBot();
        }
    }

    protected function startTradingBot()
    {
        $this->info('🤖 Iniciando Trading Bot Engine...');

        // Verificar se já está rodando
        if ($this->isBotRunning()) {
            $this->warn('⚠️  Trading Bot já está rodando!');
            return;
        }

        try {
            // Criar instância do engine
            $exchangeConnector = new ExchangeConnector();
            $this->tradingBot = new TradingBotEngine($exchangeConnector);

            // Salvar PID do processo
            $this->saveBotPid();

            $this->info('✅ Trading Bot Engine iniciado com sucesso!');
            $this->info('📊 Monitorando estratégias ativas...');

            // Iniciar o bot
            $this->tradingBot->start();

        } catch (\Exception $e) {
            $this->error('❌ Erro ao iniciar Trading Bot: ' . $e->getMessage());
        }
    }

    protected function stopTradingBot()
    {
        $this->info('🛑 Parando Trading Bot Engine...');

        if (!$this->isBotRunning()) {
            $this->warn('⚠️  Trading Bot não está rodando!');
            return;
        }

        try {
            // Obter PID e matar processo
            $pid = $this->getBotPid();
            if ($pid) {
                exec("kill {$pid}");
                $this->removeBotPid();
                $this->info('✅ Trading Bot Engine parado com sucesso!');
            } else {
                $this->error('❌ Não foi possível encontrar o processo do Trading Bot');
            }

        } catch (\Exception $e) {
            $this->error('❌ Erro ao parar Trading Bot: ' . $e->getMessage());
        }
    }

    protected function isBotRunning()
    {
        $pid = $this->getBotPid();
        if (!$pid) {
            return false;
        }

        // Verificar se o processo ainda existe
        $result = exec("ps -p {$pid}");
        return !empty($result);
    }

    protected function getBotPid()
    {
        $pidFile = storage_path('app/trading_bot.pid');
        if (file_exists($pidFile)) {
            return trim(file_get_contents($pidFile));
        }
        return null;
    }

    protected function saveBotPid()
    {
        $pidFile = storage_path('app/trading_bot.pid');
        file_put_contents($pidFile, getmypid());
    }

    protected function removeBotPid()
    {
        $pidFile = storage_path('app/trading_bot.pid');
        if (file_exists($pidFile)) {
            unlink($pidFile);
        }
    }
}

