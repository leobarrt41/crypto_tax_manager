<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Os comandos Artisan disponíveis para o aplicativo.
     *
     * @var array
     */
    protected $commands = [
        \App\Console\Commands\VerifyZeroValueTransactionsCommand::class,
        \App\Console\Commands\SyncBinanceAnnouncements::class,
    ];

    /**
     * Define a agenda de execução dos comandos do aplicativo.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Sincroniza anúncios listagem/deslistagem para manter vigência de pares/ativos atualizada.
        $schedule->command('binance:sync-announcements')->dailyAt('03:30');
    }

    /**
     * Registra os comandos do aplicativo.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
