<?php

namespace App\Console\Commands;

use App\Services\FifoCalculatorService;
use Illuminate\Console\Command;

class RecalculateFifoCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tax:recalculate-fifo
                            {user_id? : ID do usuário (omitir para processar todos)}
                            {--dry-run : Simula sem persistir alterações}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalcula o FIFO fiscal em lote para apuração de ganhos de capital (IN 1888 / GCAP)';

    public function __construct(private FifoCalculatorService $fifo)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $userId  = $this->argument('user_id') ? (int) $this->argument('user_id') : null;
        $dryRun  = $this->option('dry-run');

        $label = $userId ? "usuário #{$userId}" : 'todos os usuários';
        $this->info("Iniciando recálculo FIFO para {$label}..." . ($dryRun ? ' [DRY-RUN]' : ''));

        if ($dryRun) {
            $this->warn('Modo dry-run ativo: nenhuma alteração será persistida.');
            return self::SUCCESS;
        }

        $start = microtime(true);

        try {
            $stats = $this->fifo->recalculate($userId);
        } catch (\Throwable $e) {
            $this->error("Falha crítica: {$e->getMessage()}");
            return self::FAILURE;
        }

        $elapsed = round(microtime(true) - $start, 2);

        $this->newLine();
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Usuários processados',      $stats['users_processed']],
                ['Transações lidas',           $stats['transactions_read']],
                ['Saídas processadas (FIFO)',  $stats['saidas_processed']],
                ['Erros',                      count($stats['errors'])],
                ['Tempo decorrido (s)',         $elapsed],
            ]
        );

        if (!empty($stats['errors'])) {
            $this->newLine();
            $this->warn('Erros encontrados:');
            foreach ($stats['errors'] as $err) {
                $this->line("  - {$err}");
            }
        }

        $this->newLine();
        $this->info('Recálculo FIFO concluído com sucesso.');

        return self::SUCCESS;
    }
}
