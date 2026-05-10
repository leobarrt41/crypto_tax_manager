<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\User;
use App\Services\BinanceImportService;
use Illuminate\Support\Facades\Log;

class ProcessBinanceImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * O número de segundos que o job pode correr antes de dar timeout.
     *
     * @var int
     */
    public int $timeout = 3600; // <--- ADICIONE ESTA LINHA! (3600 segundos = 1 hora)

    protected User $user;

    /**
     * Create a new job instance.
     */
    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("✅ [Job] Iniciando ProcessBinanceImport para o usuário: {$this->user->id}");
        try {
            $importService = new BinanceImportService($this->user);
            $importService->runSmartImport();
            Log::info("🎉 [Job] ProcessBinanceImport concluído com sucesso para o usuário: {$this->user->id}");
        } catch (\Throwable $e) {
            Log::error("🚨 [Job] Falha no ProcessBinanceImport para o usuário: {$this->user->id}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
