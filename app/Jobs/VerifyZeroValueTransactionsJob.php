<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\TransactionVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job para verificar e atualizar transações com valores zero
 * 
 * Este job pode ser executado após uma importação ou de forma independente
 * para garantir que todas as transações tenham valores calculados corretamente.
 */
class VerifyZeroValueTransactionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número de tentativas do job
     */
    public $tries = 3;

    /**
     * Timeout do job em segundos
     */
    public $timeout = 600; // 10 minutos

    /**
     * ID do usuário
     */
    protected int $userId;

    /**
     * ID da exchange (opcional)
     */
    protected ?int $exchangeId;

    /**
     * Se deve enviar notificação ao usuário
     */
    protected bool $notifyUser;

    /**
     * Criar uma nova instância do job
     *
     * @param int $userId
     * @param int|null $exchangeId
     * @param bool $notifyUser
     */
    public function __construct(int $userId, ?int $exchangeId = null, bool $notifyUser = false)
    {
        $this->userId = $userId;
        $this->exchangeId = $exchangeId;
        $this->notifyUser = $notifyUser;
    }

    /**
     * Executar o job
     */
    public function handle(TransactionVerificationService $verificationService): void
    {

         set_time_limit(600);              // ou 0 para ilimitado
         ini_set('max_execution_time', '600');

        Log::info("======================================================================");
        Log::info("🔄 [Job] Iniciando verificação de transações com valores zero");
        Log::info("======================================================================");
        Log::info("👤 Usuário ID: {$this->userId}");
        if ($this->exchangeId) {
            Log::info("🏦 Exchange ID: {$this->exchangeId}");
        }

        try {
            $user = User::findOrFail($this->userId);
            
            // Executar verificação
            $stats = $verificationService->verifyAndUpdateZeroValueTransactions(
                $user,
                $this->exchangeId
            );

            Log::info("✅ [Job] Verificação concluída com sucesso", $stats);

            // Se configurado, enviar notificação ao usuário
            if ($this->notifyUser && $stats['total_updated'] > 0) {
                $this->sendNotification($user, $stats);
            }

        } catch (\Exception $e) {
            Log::error("❌ [Job] Erro ao verificar transações", [
                'user_id' => $this->userId,
                'exchange_id' => $this->exchangeId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Enviar notificação ao usuário sobre as atualizações
     */
    private function sendNotification(User $user, array $stats): void
    {
        // Aqui você pode implementar a lógica de notificação
        // Por exemplo, enviar um email ou notificação in-app
        
        Log::info("📧 [Job] Enviando notificação ao usuário", [
            'user_id' => $user->id,
            'total_updated' => $stats['total_updated']
        ]);

        // Exemplo de implementação:
        // $user->notify(new TransactionsUpdatedNotification($stats));
    }

    /**
     * Lidar com falha do job
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("❌ [Job] Falha definitiva ao verificar transações", [
            'user_id' => $this->userId,
            'exchange_id' => $this->exchangeId,
            'error' => $exception->getMessage()
        ]);

        // Aqui você pode implementar lógica adicional para falhas
        // Por exemplo, notificar administradores ou registrar em sistema de monitoramento
    }
}

