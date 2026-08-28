<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\BinanceImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessBinanceImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public function __construct(
        protected User $user,
        protected int $apiKeyId,
        protected int $year,
    ) {
    }

    public function handle(): void
    {
        Log::info('[Binance] Job de sincronização anual iniciado.', [
            'user_id' => $this->user->id,
            'api_key_id' => $this->apiKeyId,
            'year' => $this->year,
        ]);

        try {
            $result = (new BinanceImportService($this->user, $this->apiKeyId))
                ->runSmartImport($this->year);

            if (!$result['success']) {
                throw new \RuntimeException($result['message'] ?? 'Falha sem mensagem retornada.');
            }

            Log::info('[Binance] Job de sincronização anual concluído.', [
                'user_id' => $this->user->id,
                'api_key_id' => $this->apiKeyId,
                'year' => $this->year,
                'result' => $result,
            ]);
        } catch (\Throwable $exception) {
            Log::error('[Binance] Job de sincronização anual falhou.', [
                'user_id' => $this->user->id,
                'api_key_id' => $this->apiKeyId,
                'year' => $this->year,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
