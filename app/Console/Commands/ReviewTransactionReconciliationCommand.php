<?php

namespace App\Console\Commands;

use App\Models\TransactionReconciliation;
use App\Models\User;
use App\Services\BinanceApiCsvReconciliationService;
use Illuminate\Console\Command;

class ReviewTransactionReconciliationCommand extends Command
{
    protected $signature = 'binance:review-reconciliation
        {reconciliation_id : Identificador da conciliação}
        {decision : confirm, reject ou revoke}
        {actor_user_id : Usuário responsável pela decisão}
        {--reason= : Motivo opcional da decisão}';

    protected $description = 'Registra uma decisão humana auditável sobre uma conciliação API × CSV';

    public function handle(BinanceApiCsvReconciliationService $service): int
    {
        $reconciliation = TransactionReconciliation::query()->find($this->argument('reconciliation_id'));
        if ($reconciliation === null) {
            $this->error('Conciliação não encontrada.');

            return self::FAILURE;
        }

        $actor = User::query()->find($this->argument('actor_user_id'));
        if ($actor === null) {
            $this->error('Usuário responsável não encontrado.');

            return self::FAILURE;
        }

        $status = match ($this->argument('decision')) {
            'confirm' => TransactionReconciliation::STATUS_CONFIRMED,
            'reject' => TransactionReconciliation::STATUS_REJECTED,
            'revoke' => TransactionReconciliation::STATUS_REVOKED,
            default => null,
        };
        if ($status === null) {
            $this->error('Decisão inválida. Use confirm, reject ou revoke.');

            return self::INVALID;
        }

        try {
            $service->transition($reconciliation, $status, $actor, $this->option('reason'));
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Conciliação #{$reconciliation->id} atualizada para {$status}.");
        $this->warn('O FIFO não foi recalculado automaticamente. Execute o recálculo explicitamente quando apropriado.');

        return self::SUCCESS;
    }
}
