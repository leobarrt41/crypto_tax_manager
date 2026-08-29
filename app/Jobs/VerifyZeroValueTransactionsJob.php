<?php

namespace App\Jobs;

use App\Models\ImportSession;
use App\Models\User;
use App\Services\TransactionVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Verifica e enriquece transações sem valor fiscal após a sincronização.
 *
 * A sessão de importação só é concluída quando esta etapa também termina,
 * evitando que a interface informe uma conclusão prematura.
 */
class VerifyZeroValueTransactionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 3600;

    protected int $userId;

    /** ID da chave de API/origem usada para filtrar as transações. */
    protected ?int $sourceId = null;

    protected bool $notifyUser;

    /** Mantém compatibilidade com payloads serializados antes deste campo. */
    protected ?int $importSessionId = null;

    public function __construct(
        int $userId,
        ?int $sourceId = null,
        bool $notifyUser = false,
        ?int $importSessionId = null,
    ) {
        $this->userId = $userId;
        $this->sourceId = $sourceId;
        $this->notifyUser = $notifyUser;
        $this->importSessionId = $importSessionId;
    }

    public function handle(TransactionVerificationService $verificationService): void
    {
        set_time_limit($this->timeout);
        ini_set('max_execution_time', (string) $this->timeout);

        $session = $this->session();
        $this->markSessionPricing($session, 'processing');

        Log::info('======================================================================');
        Log::info('🔄 [Job] Iniciando verificação de transações com valores zero');
        Log::info('======================================================================');
        Log::info("👤 Usuário ID: {$this->userId}");
        if ($this->sourceId) {
            Log::info("🏦 ID da origem/API: {$this->sourceId}");
        }

        try {
            $user = User::findOrFail($this->userId);
            $stats = $verificationService->verifyAndUpdateZeroValueTransactions($user, $this->sourceId);

            $this->completeSessionPricing($session, $stats);

            Log::info('✅ [Job] Verificação concluída com sucesso', $stats);

            if ($this->notifyUser && $stats['total_updated'] > 0) {
                $this->sendNotification($user, $stats);
            }
        } catch (\Throwable $exception) {
            $this->failSessionPricing($session, $exception);

            Log::error('❌ [Job] Erro ao verificar transações', [
                'user_id' => $this->userId,
                'source_id' => $this->sourceId,
                'import_session_id' => $this->importSessionId,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->failSessionPricing($this->session(), $exception);

        Log::error('❌ [Job] Falha definitiva ao verificar transações', [
            'user_id' => $this->userId,
            'source_id' => $this->sourceId,
            'import_session_id' => $this->importSessionId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function session(): ?ImportSession
    {
        if ($this->importSessionId === null) {
            return null;
        }

        return ImportSession::query()
            ->whereKey($this->importSessionId)
            ->where('user_id', $this->userId)
            ->first();
    }

    private function markSessionPricing(?ImportSession $session, string $status): void
    {
        if (!$session) {
            return;
        }

        $session->update([
            'status' => 'pricing',
            'progress_percentage' => max((float) $session->progress_percentage, 90),
            'settings' => array_merge($session->settings ?? [], [
                'pricing' => array_merge(data_get($session->settings, 'pricing', []), [
                    'status' => $status,
                    'started_at' => now()->toIso8601String(),
                ]),
            ]),
        ]);
    }

    private function completeSessionPricing(?ImportSession $session, array $stats): void
    {
        if (!$session) {
            return;
        }

        $session->update([
            'settings' => array_merge($session->settings ?? [], [
                'pricing' => [
                    'status' => 'completed',
                    'checked' => (int) ($stats['total_checked'] ?? 0),
                    'updated' => (int) ($stats['total_updated'] ?? 0),
                    'unavailable' => (int) ($stats['total_failed'] ?? 0),
                    'completed_at' => now()->toIso8601String(),
                ],
            ]),
        ]);
        $session->complete();
    }

    private function failSessionPricing(?ImportSession $session, \Throwable $exception): void
    {
        if (!$session) {
            return;
        }

        $session->fail([
            'message' => 'A importação foi concluída, mas a verificação de cotações não terminou: ' . $exception->getMessage(),
            'pricing' => [
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ],
        ]);
    }

    private function sendNotification(User $user, array $stats): void
    {
        Log::info('📧 [Job] Enviando notificação', [
            'user_id' => $user->id,
            'total_updated' => $stats['total_updated'],
        ]);
    }
}
