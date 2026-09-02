<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\BinanceApiCsvReconciliationService;
use Illuminate\Console\Command;

class ReconcileBinanceApiCsvCommand extends Command
{
    protected $signature = 'binance:reconcile-api-csv
        {user_id : Usuário proprietário das transações}
        {year : Ano das operações}
        {--apply : Persiste as conciliações encontradas; sem esta opção é somente simulação}';

    protected $description = 'Concilia Converts duplicados entre API e CSV anual sem alterar as transações brutas';

    public function handle(BinanceApiCsvReconciliationService $service): int
    {
        $userId = (int) $this->argument('user_id');
        $year = (int) $this->argument('year');
        if (! User::query()->whereKey($userId)->exists() || $year < 2009 || $year > 2099) {
            $this->error('Usuário ou ano inválido.');

            return self::INVALID;
        }

        $apply = (bool) $this->option('apply');
        $stats = $service->reconcileUserYear($userId, $year, $apply);

        $this->info($apply ? 'Conciliação aplicada.' : 'Simulação concluída; nenhuma alteração foi gravada.');
        $this->table(['Métrica', 'Quantidade'], collect($stats)->map(
            fn (int $value, string $label): array => [$label, $value],
        )->values()->all());

        if ($apply && ($stats['confirmed'] ?? 0) > 0) {
            $this->warn('Somente matches determinísticos foram confirmados. Execute o recálculo FIFO explicitamente.');
        }
        if ($apply && ($stats['pending_review'] ?? 0) > 0) {
            $this->warn('Matches heurísticos ficaram pendentes e não alteram o FIFO até confirmação explícita.');
        }

        return self::SUCCESS;
    }
}
