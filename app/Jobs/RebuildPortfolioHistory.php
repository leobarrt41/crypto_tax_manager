<?php

namespace App\Jobs;

use App\Models\ImportSession;
use App\Models\User;
use App\Services\PortfolioHistoryReconstructionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RebuildPortfolioHistory implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;

    public function __construct(
        public readonly int $userId,
        public readonly int $sessionId,
    ) {
    }

    public function handle(PortfolioHistoryReconstructionService $reconstruction): void
    {
        $session = ImportSession::query()->find($this->sessionId);
        $user = User::query()->find($this->userId);
        if ($session === null || $user === null) {
            return;
        }

        $session->start();

        try {
            $result = $reconstruction->reconstruct($user);
            $session->update([
                'processed_rows' => $result['snapshots_written'],
                'successful_rows' => $result['snapshots_written'],
                'settings' => array_merge($session->settings ?? [], ['result' => $result]),
            ]);
            $session->complete();
        } catch (\Throwable $exception) {
            Log::error('[Portfólio] Falha na reconstrução histórica.', [
                'user_id' => $this->userId,
                'session_id' => $this->sessionId,
                'error' => $exception->getMessage(),
            ]);
            $session->fail([$exception->getMessage()]);

            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        ImportSession::query()->find($this->sessionId)?->fail([$exception->getMessage()]);
    }
}
