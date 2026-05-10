<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TransactionVerificationService;
use Illuminate\Console\Command;

/**
 * Comando Artisan para verificar e atualizar transações com valores zero
 * 
 * Uso:
 * php artisan transactions:verify-zero-values {user_id} {--exchange=} {--report} {--references=}
 */
class VerifyZeroValueTransactionsCommand extends Command
{
    /**
     * Nome e assinatura do comando
     */
    protected $signature = 'transactions:verify-zero-values
                            {user_id : ID do usuário}
                            {--exchange= : ID da exchange (opcional)}
                            {--report : Apenas gerar relatório sem atualizar}
                            {--references= : Lista de referências separadas por vírgula para verificar transações específicas}';

    /**
     * Descrição do comando
     */
    protected $description = 'Verifica e atualiza transações com valores zero, recalculando com dados atualizados de preços';

    /**
     * Executar o comando
     */
    public function handle(TransactionVerificationService $verificationService): int
    {
        $userId = $this->argument('user_id');
        $exchangeId = $this->option('exchange');
        $reportOnly = $this->option('report');
        $references = $this->option('references');

        $this->info("======================================================================");
        $this->info("🔍 Verificação de Transações com Valores Zero");
        $this->info("======================================================================");

        try {
            $user = User::findOrFail($userId);
            $this->info("👤 Usuário: {$user->name} (ID: {$user->id})");
            
            if ($exchangeId) {
                $this->info("🏦 Exchange ID: {$exchangeId}");
            }

            // Se for apenas relatório
            if ($reportOnly) {
                $this->info("\n📊 Gerando relatório...\n");
                $report = $verificationService->generateZeroValueReport($user, $exchangeId);
                $this->displayReport($report);
                return Command::SUCCESS;
            }

            // Se for verificação de transações específicas
            if ($references) {
                $referencesArray = explode(',', $references);
                $referencesArray = array_map('trim', $referencesArray);
                
                $this->info("\n🔍 Verificando transações específicas...");
                $this->info("📋 Referências: " . implode(', ', $referencesArray));
                
                $stats = $verificationService->verifySpecificTransactions($user, $referencesArray);
                $this->displayStats($stats);
                return Command::SUCCESS;
            }

            // Verificação completa
            $this->info("\n🔄 Iniciando verificação e atualização...\n");
            
            if (!$this->confirm('Deseja continuar com a verificação e atualização das transações?', true)) {
                $this->warn('Operação cancelada pelo usuário.');
                return Command::SUCCESS;
            }

            $stats = $verificationService->verifyAndUpdateZeroValueTransactions($user, $exchangeId);
            
            $this->displayStats($stats);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Erro ao executar verificação: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }

    /**
     * Exibir estatísticas da verificação
     */
    private function displayStats(array $stats): void
    {
        $this->info("\n======================================================================");
        $this->info("📊 Estatísticas da Verificação");
        $this->info("======================================================================");
        
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Total Verificadas', $stats['total_checked']],
                ['Total Atualizadas', $stats['total_updated']],
                ['Total com Falha', $stats['total_failed']],
            ]
        );

        if (!empty($stats['updated_transactions'])) {
            $this->info("\n✅ Transações Atualizadas:");
            $this->table(
                ['ID', 'Referência', 'Data', 'USDT Antigo', 'USDT Novo', 'BRL Antigo', 'BRL Novo'],
                array_map(function($tx) {
                    return [
                        $tx['id'],
                        $tx['reference'],
                        $tx['date'],
                        number_format($tx['old_usdt'], 2),
                        number_format($tx['new_usdt'], 2),
                        number_format($tx['old_brl'], 2),
                        number_format($tx['new_brl'], 2),
                    ];
                }, array_slice($stats['updated_transactions'], 0, 10)) // Mostrar apenas as primeiras 10
            );
            
            if (count($stats['updated_transactions']) > 10) {
                $remaining = count($stats['updated_transactions']) - 10;
                $this->info("... e mais {$remaining} transações atualizadas.");
            }
        }

        if (!empty($stats['failed_transactions'])) {
            $this->warn("\n⚠️ Transações com Falha:");
            $this->table(
                ['ID', 'Referência', 'Data', 'Motivo'],
                array_map(function($tx) {
                    return [
                        $tx['id'],
                        $tx['reference'],
                        $tx['date'],
                        substr($tx['reason'], 0, 50) . (strlen($tx['reason']) > 50 ? '...' : ''),
                    ];
                }, array_slice($stats['failed_transactions'], 0, 10)) // Mostrar apenas as primeiras 10
            );
            
            if (count($stats['failed_transactions']) > 10) {
                $remaining = count($stats['failed_transactions']) - 10;
                $this->warn("... e mais {$remaining} transações com falha.");
            }
        }

        $this->info("\n======================================================================");
        
        if ($stats['total_updated'] > 0) {
            $this->info("✅ Verificação concluída com sucesso!");
        } else {
            $this->warn("⚠️ Nenhuma transação foi atualizada.");
        }
    }

    /**
     * Exibir relatório de transações com valores zero
     */
    private function displayReport(array $report): void
    {
        $this->info("\n======================================================================");
        $this->info("📊 Relatório de Transações com Valores Zero");
        $this->info("======================================================================");
        
        $this->info("\n📈 Total de transações com valores zero: " . $report['total_count']);

        if ($report['total_count'] === 0) {
            $this->info("\n✅ Nenhuma transação com valor zero encontrada!");
            return;
        }

        if (!empty($report['by_type'])) {
            $this->info("\n📊 Por Tipo:");
            $this->table(
                ['Tipo', 'Quantidade'],
                array_map(function($type, $count) {
                    return [$type, $count];
                }, array_keys($report['by_type']), array_values($report['by_type']))
            );
        }

        if (!empty($report['by_month'])) {
            $this->info("\n📅 Por Mês:");
            $monthData = $report['by_month'];
            krsort($monthData); // Ordenar por mês (mais recente primeiro)
            $this->table(
                ['Mês', 'Quantidade'],
                array_map(function($month, $count) {
                    return [$month, $count];
                }, array_keys($monthData), array_values($monthData))
            );
        }

        if (!empty($report['by_asset'])) {
            $this->info("\n💰 Por Ativo:");
            $assetData = $report['by_asset'];
            arsort($assetData); // Ordenar por quantidade (maior primeiro)
            $this->table(
                ['Ativo', 'Quantidade'],
                array_map(function($asset, $count) {
                    return [$asset, $count];
                }, array_keys($assetData), array_values($assetData))
            );
        }

        $this->info("\n💡 Dica: Execute sem a opção --report para atualizar essas transações.");
        $this->info("======================================================================");
    }
}

