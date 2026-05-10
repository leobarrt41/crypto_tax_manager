<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserApiKey;
use App\Models\MonthlyAssetSnapshot;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Comando para diagnosticar problemas na importação da Binance
 * 
 * Uso: php artisan binance:diagnose {user_id}
 */
class DiagnoseBinanceImportCommand extends Command
{
    protected $signature = 'binance:diagnose {user_id}';
    protected $description = 'Diagnostica problemas na importação de transações da Binance';

    public function handle(): int
    {
        $userId = $this->argument('user_id');

        try {
            $user = User::findOrFail($userId);
            $this->info("======================================================================");
            $this->info("🔍 Diagnóstico de Importação Binance");
            $this->info("======================================================================");
            $this->info("👤 Usuário: {$user->name} (ID: {$user->id})");
            $this->newLine();

            // 1. Verificar API Key
            $this->info("1️⃣ Verificando API Key...");
            $apiKey = UserApiKey::where('user_id', $user->id)
                ->whereHas('exchange', function ($q) {
                    $q->where('name', 'binance');
                })
                ->first();

            if (!$apiKey) {
                $this->error("❌ Nenhuma API Key da Binance encontrada para este usuário.");
                return Command::FAILURE;
            }
            $this->info("✅ API Key encontrada (ID: {$apiKey->id})");
            $this->newLine();

            // 2. Verificar Snapshots Mensais
            $this->info("2️⃣ Verificando Snapshots Mensais...");
            $snapshots = MonthlyAssetSnapshot::where('user_id', $user->id)
                ->where('exchange_id', $apiKey->exchange_id)
                ->orderBy('year')
                ->orderBy('month')
                ->get();

            if ($snapshots->isEmpty()) {
                $this->warn("⚠️ Nenhum snapshot mensal encontrado.");
            } else {
                $this->info("✅ Total de snapshots: {$snapshots->count()}");
                $firstSnapshot = $snapshots->first();
                $lastSnapshot = $snapshots->last();
                $this->info("   📅 Primeiro mês: {$firstSnapshot->year}-{$firstSnapshot->month}");
                $this->info("   📅 Último mês: {$lastSnapshot->year}-{$lastSnapshot->month}");
                
                // Verificar se há lacunas
                $this->checkSnapshotGaps($snapshots);
            }
            $this->newLine();

            // 3. Verificar Transações Importadas
            $this->info("3️⃣ Verificando Transações Importadas...");
            $transactions = Transaction::where('user_id', $user->id)
                ->where('source_id', $apiKey->id)
                ->orderBy('date')
                ->get();

            if ($transactions->isEmpty()) {
                $this->warn("⚠️ Nenhuma transação importada encontrada.");
            } else {
                $this->info("✅ Total de transações: {$transactions->count()}");
                $firstTx = $transactions->first();
                $lastTx = $transactions->last();
                $this->info("   📅 Primeira transação: {$firstTx->date->format('d/m/Y')}");
                $this->info("   📅 Última transação: {$lastTx->date->format('d/m/Y')}");
                
                // Verificar transações com valores zero
                $zeroValueCount = Transaction::where('user_id', $user->id)
                    ->where('source_id', $apiKey->id)
                    ->where(function($q) {
                        $q->where('total_usdt', 0)
                          ->orWhere('total_brl', 0);
                    })
                    ->count();
                
                if ($zeroValueCount > 0) {
                    $this->warn("   ⚠️ Transações com valores zero: {$zeroValueCount}");
                }
                
                // Distribuição por mês
                $this->showMonthlyDistribution($transactions);
            }
            $this->newLine();

            // 4. Testar Conectividade com API Binance
            $this->info("4️⃣ Testando Conectividade com API Binance...");
            $this->testBinanceApi($apiKey);
            $this->newLine();

            // 5. Verificar Limite de Histórico da API
            $this->info("5️⃣ Verificando Limite de Histórico da API...");
            $this->checkHistoricalLimit($apiKey);
            $this->newLine();

            // 6. Recomendações
            $this->info("======================================================================");
            $this->info("💡 Recomendações");
            $this->info("======================================================================");
            $this->provideRecommendations($snapshots, $transactions);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Erro ao executar diagnóstico: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function checkSnapshotGaps($snapshots): void
    {
        $gaps = [];
        for ($i = 0; $i < $snapshots->count() - 1; $i++) {
            $current = $snapshots[$i];
            $next = $snapshots[$i + 1];
            
            $currentDate = Carbon::create($current->year, $current->month, 1);
            $nextDate = Carbon::create($next->year, $next->month, 1);
            
            $monthsDiff = $currentDate->diffInMonths($nextDate);
            
            if ($monthsDiff > 1) {
                $gaps[] = "Entre {$current->year}-{$current->month} e {$next->year}-{$next->month} ({$monthsDiff} meses)";
            }
        }
        
        if (!empty($gaps)) {
            $this->warn("   ⚠️ Lacunas encontradas nos snapshots:");
            foreach ($gaps as $gap) {
                $this->warn("      - {$gap}");
            }
        }
    }

    private function showMonthlyDistribution($transactions): void
    {
        $distribution = [];
        foreach ($transactions as $tx) {
            $monthKey = $tx->date->format('Y-m');
            if (!isset($distribution[$monthKey])) {
                $distribution[$monthKey] = 0;
            }
            $distribution[$monthKey]++;
        }
        
        ksort($distribution);
        $this->newLine();
        $this->info("   📊 Distribuição por Mês:");
        
        // Mostrar apenas os últimos 12 meses
        $recentMonths = array_slice($distribution, -12, 12, true);
        
        $tableData = [];
        foreach ($recentMonths as $month => $count) {
            $tableData[] = [$month, $count];
        }
        
        $this->table(['Mês', 'Transações'], $tableData);
        
        if (count($distribution) > 12) {
            $this->info("   (Mostrando apenas os últimos 12 meses de " . count($distribution) . " meses totais)");
        }
    }

    private function testBinanceApi($apiKey): void
    {
        try {
            $timestamp = now()->timestamp * 1000;
            $queryString = "timestamp={$timestamp}";
            $signature = hash_hmac('sha256', $queryString, $apiKey->secret);
            
            $response = Http::withHeaders([
                'X-MBX-APIKEY' => $apiKey->key
            ])->get('https://api.binance.com/api/v3/account', [
                'timestamp' => $timestamp,
                'signature' => $signature
            ]);
            
            if ($response->successful()) {
                $this->info("✅ Conexão com API Binance OK");
                $data = $response->json();
                $this->info("   📊 Tipo de conta: " . ($data['accountType'] ?? 'N/A'));
                $this->info("   🔐 Permissões: " . implode(', ', $data['permissions'] ?? []));
            } else {
                $this->error("❌ Falha ao conectar com API Binance");
                $this->error("   Status: " . $response->status());
                $this->error("   Resposta: " . $response->body());
            }
        } catch (\Exception $e) {
            $this->error("❌ Erro ao testar API: " . $e->getMessage());
        }
    }

    private function checkHistoricalLimit($apiKey): void
    {
        try {
            // Tentar buscar um trade muito antigo
            $fiveYearsAgo = Carbon::now()->subYears(5)->getTimestampMs();
            $timestamp = now()->timestamp * 1000;
            
            $queryString = "symbol=BTCUSDT&limit=1&timestamp={$timestamp}";
            $signature = hash_hmac('sha256', $queryString, $apiKey->secret);
            
            $response = Http::withHeaders([
                'X-MBX-APIKEY' => $apiKey->key
            ])->get('https://api.binance.com/api/v3/myTrades', [
                'symbol' => 'BTCUSDT',
                'limit' => 1,
                'timestamp' => $timestamp,
                'signature' => $signature
            ]);
            
            if ($response->successful()) {
                $trades = $response->json();
                if (!empty($trades)) {
                    $oldestTrade = $trades[0];
                    $tradeDate = Carbon::createFromTimestampMs($oldestTrade['time']);
                    $this->info("✅ Trade mais antigo encontrado: {$tradeDate->format('d/m/Y H:i:s')}");
                    
                    $yearsAgo = Carbon::now()->diffInYears($tradeDate);
                    if ($yearsAgo >= 2) {
                        $this->info("   ℹ️ A API permite acesso a histórico de pelo menos {$yearsAgo} anos");
                    } else {
                        $this->warn("   ⚠️ Histórico disponível: apenas {$yearsAgo} anos");
                    }
                } else {
                    $this->warn("⚠️ Nenhum trade encontrado para BTCUSDT");
                }
            } else {
                $this->warn("⚠️ Não foi possível verificar limite histórico");
            }
        } catch (\Exception $e) {
            $this->warn("⚠️ Erro ao verificar limite: " . $e->getMessage());
        }
    }

    private function provideRecommendations($snapshots, $transactions): void
    {
        if ($snapshots->isEmpty()) {
            $this->warn("1. Execute uma nova importação para gerar os snapshots mensais");
            $this->info("   Comando: php artisan binance:import {$this->argument('user_id')}");
        }
        
        if ($transactions->isNotEmpty()) {
            $lastTx = $transactions->last();
            $daysSinceLastTx = Carbon::now()->diffInDays($lastTx->date);
            
            if ($daysSinceLastTx > 30) {
                $this->warn("2. A última transação importada é de {$daysSinceLastTx} dias atrás");
                $this->info("   Considere executar uma nova importação para atualizar os dados");
            }
            
            $zeroValueCount = Transaction::where('user_id', $this->argument('user_id'))
                ->where(function($q) {
                    $q->where('total_usdt', 0)->orWhere('total_brl', 0);
                })
                ->count();
            
            if ($zeroValueCount > 0) {
                $this->warn("3. Existem {$zeroValueCount} transações com valores zero");
                $this->info("   Execute: php artisan transactions:verify-zero-values {$this->argument('user_id')}");
            }
        }
        
        if ($snapshots->isNotEmpty()) {
            $lastSnapshot = $snapshots->last();
            $lastSnapshotDate = Carbon::create($lastSnapshot->year, $lastSnapshot->month, 1);
            $monthsSinceLastSnapshot = Carbon::now()->diffInMonths($lastSnapshotDate);
            
            if ($monthsSinceLastSnapshot > 1) {
                $this->warn("4. O último snapshot é de {$monthsSinceLastSnapshot} meses atrás");
                $this->info("   O sistema pode não estar importando transações recentes");
            }
        }
    }
}

