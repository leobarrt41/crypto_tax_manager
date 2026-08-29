<?php

namespace App\Jobs;

use App\Models\ImportSession;
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
        protected ?int $importSessionId = null,
    ) {
    }

    public function handle(): void
    {
        $session = $this->session();
        $session?->start();

        Log::info('[Binance] Job de sincronização anual iniciado.', [
            'user_id' => $this->user->id,
            'api_key_id' => $this->apiKeyId,
            'year' => $this->year,
            'import_session_id' => $session?->id,
        ]);

        try {
            $result = (new BinanceImportService($this->user, $this->apiKeyId))
                ->runSmartImport($this->year);

            if (!$result['success']) {
                throw new \RuntimeException($result['message'] ?? 'Falha sem mensagem retornada.');
            }

            if ($session) {
                $this->completeSession($session, $result);
            }

            Log::info('[Binance] Job de sincronização anual concluído.', [
                'user_id' => $this->user->id,
                'api_key_id' => $this->apiKeyId,
                'year' => $this->year,
                'import_session_id' => $session?->id,
                'result' => $result,
            ]);
        } catch (\Throwable $exception) {
            $session?->fail([
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            Log::error('[Binance] Job de sincronização anual falhou.', [
                'user_id' => $this->user->id,
                'api_key_id' => $this->apiKeyId,
                'year' => $this->year,
                'import_session_id' => $session?->id,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function session(): ?ImportSession
    {
        if ($this->importSessionId === null) {
            return null;
        }

        return ImportSession::query()
            ->whereKey($this->importSessionId)
            ->where('user_id', $this->user->id)
            ->first();
    }

    private function completeSession(ImportSession $session, array $result): void
    {
        $imported = array_sum([
            (int) ($result['spot_trades_imported'] ?? 0),
            (int) ($result['conversions_imported'] ?? 0),
            (int) ($result['deposits_imported'] ?? 0),
            (int) ($result['withdrawals_imported'] ?? 0),
        ]);

        $session->update([
            'total_rows' => $imported,
            'processed_rows' => $imported,
            'successful_rows' => $imported,
            'settings' => array_merge($session->settings ?? [], [
                'result' => $result,
            ]),
        ]);
        $session->complete();
    }
}
