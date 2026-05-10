<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Serviço para verificar e atualizar transações com valores zero
 * 
 * Este serviço identifica transações que foram salvas com valores zero
 * e tenta recalcular seus valores usando dados atualizados de preços.
 */
class TransactionVerificationService
{
    private CryptoPriceService $priceService;
    private array $priceCache = [];
    private array $usdBrlCache = [];
    private const STABLECOINS = ['USDT', 'BUSD', 'TUSD', 'FDUSD', 'USDC'];
    
    public function __construct()
    {
        $this->priceService = app(CryptoPriceService::class);
    }
    
    /**
     * Verifica e atualiza todas as transações com valores zero de um usuário
     * 
     * @param User $user
     * @param int|null $exchangeId ID da exchange (opcional)
     * @return array Estatísticas da verificação
     */
    public function verifyAndUpdateZeroValueTransactions(User $user, ?int $exchangeId = null): array
    {
        Log::info("======================================================================");
        Log::info("🔍 [Verificação] Iniciando verificação de transações com valores zero");
        Log::info("======================================================================");
        Log::info("👤 Usuário: {$user->id}");
        if ($exchangeId) {
            Log::info("🏦 Exchange ID: {$exchangeId}");
        }
        
        $stats = [
            'total_checked' => 0,
            'total_updated' => 0,
            'total_failed' => 0,
            'updated_transactions' => [],
            'failed_transactions' => []
        ];
        
        // Buscar transações com valores zero
        $query = Transaction::where('user_id', $user->id)
            ->where(function($q) {
                $q->where('total_usdt', 0)
                  ->orWhere('total_brl', 0)
                  ->orWhereNull('total_usdt')
                  ->orWhereNull('total_brl');
            });
            
        if ($exchangeId) {
            $query->where('source_id', $exchangeId);
        }
        
        $zeroValueTransactions = $query->orderBy('date', 'asc')->get();
        
        $stats['total_checked'] = $zeroValueTransactions->count();
        
        Log::info("📊 Total de transações com valores zero encontradas: {$stats['total_checked']}");
        
        if ($stats['total_checked'] === 0) {
            Log::info("✅ Nenhuma transação com valor zero encontrada!");
            return $stats;
        }
        
        // Processar cada transação
        foreach ($zeroValueTransactions as $transaction) {
            $result = $this->updateTransactionValues($transaction);
            
            if ($result['success']) {
                $stats['total_updated']++;
                $stats['updated_transactions'][] = [
                    'id' => $transaction->id,
                    'reference' => $transaction->reference,
                    'date' => $transaction->date->toDateString(),
                    'old_usdt' => $result['old_usdt'],
                    'new_usdt' => $result['new_usdt'],
                    'old_brl' => $result['old_brl'],
                    'new_brl' => $result['new_brl']
                ];
                
                Log::info("✅ Transação #{$transaction->id} atualizada com sucesso", [
                    'reference' => $transaction->reference,
                    'old_usdt' => $result['old_usdt'],
                    'new_usdt' => $result['new_usdt'],
                    'old_brl' => $result['old_brl'],
                    'new_brl' => $result['new_brl']
                ]);
            } else {
                $stats['total_failed']++;
                $stats['failed_transactions'][] = [
                    'id' => $transaction->id,
                    'reference' => $transaction->reference,
                    'date' => $transaction->date->toDateString(),
                    'reason' => $result['reason']
                ];
                
                Log::warning("⚠️ Falha ao atualizar transação #{$transaction->id}", [
                    'reference' => $transaction->reference,
                    'reason' => $result['reason']
                ]);
            }
        }
        
        Log::info("======================================================================");
        Log::info("✅ [Verificação] Concluída!");
        Log::info("📊 Estatísticas:");
        Log::info("   - Total verificadas: {$stats['total_checked']}");
        Log::info("   - Total atualizadas: {$stats['total_updated']}");
        Log::info("   - Total com falha: {$stats['total_failed']}");
        Log::info("======================================================================");
        
        return $stats;
    }
    
    /**
     * Atualiza os valores de uma transação específica
     * 
     * @param Transaction $transaction
     * @return array Resultado da atualização
     */
    private function updateTransactionValues(Transaction $transaction): array
    {
        $oldUsdt = $transaction->total_usdt ?? 0;
        $oldBrl = $transaction->total_brl ?? 0;
        
        try {
            $date = Carbon::parse($transaction->date);
            
            // Calcular novos valores baseado no tipo de transação
            if ($transaction->type === 'trade') {
                $values = $this->calculateTradeValues($transaction, $date);
            } elseif ($transaction->type === 'convert') {
                $values = $this->calculateConversionValues($transaction, $date);
            } else {
                return [
                    'success' => false,
                    'reason' => 'Tipo de transação não suportado: ' . $transaction->type
                ];
            }
            
            // Verificar se conseguimos calcular valores válidos
            if ($values['total_usdt'] > 0 || $values['total_brl'] > 0) {
                // Atualizar apenas se os novos valores forem diferentes de zero
                if ($values['total_usdt'] > 0 && $values['total_brl'] > 0) {
                    $transaction->update([
                        'total_usdt' => $values['total_usdt'],
                        'total_brl' => $values['total_brl']
                    ]);
                    
                    return [
                        'success' => true,
                        'old_usdt' => $oldUsdt,
                        'new_usdt' => $values['total_usdt'],
                        'old_brl' => $oldBrl,
                        'new_brl' => $values['total_brl']
                    ];
                } else {
                    return [
                        'success' => false,
                        'reason' => 'Valores calculados ainda são zero ou incompletos'
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'reason' => 'Não foi possível calcular valores válidos'
                ];
            }
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'reason' => 'Exceção: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Calcula valores para transações do tipo 'trade'
     */
    private function calculateTradeValues(Transaction $transaction, Carbon $date): array
    {
        $totalUsdt = 0;
        $totalBrl = 0;
        
        $baseAsset = $transaction->from_asset;
        $quoteAsset = $transaction->to_asset;
        $baseAmount = (float)$transaction->from_amount;
        $quoteAmount = (float)$transaction->to_amount;
        
        // Lógica de cálculo baseada nos ativos envolvidos
        if (in_array($quoteAsset, self::STABLECOINS)) {
            // Se o ativo de cotação é stablecoin, o valor em USDT é direto
            $totalUsdt = $quoteAmount;
            $totalBrl = $this->calculateTotalBrl($totalUsdt, $date);
            
        } elseif ($quoteAsset === 'BRL') {
            // Se o ativo de cotação é BRL
            $totalBrl = $quoteAmount;
            
            if (in_array($baseAsset, self::STABLECOINS)) {
                $totalUsdt = $baseAmount;
            } else {
                $totalUsdt = $this->calculateTotalUsdt($baseAsset, $baseAmount, $date);
            }
            
        } else {
            // Para outros pares, calcular baseado no ativo recebido
            $totalUsdt = $this->calculateTotalUsdt($quoteAsset, $quoteAmount, $date);
            $totalBrl = $this->calculateTotalBrl($totalUsdt, $date);
        }
        
        return [
            'total_usdt' => $totalUsdt,
            'total_brl' => $totalBrl
        ];
    }
    
    /**
     * Calcula valores para transações do tipo 'convert'
     */
    private function calculateConversionValues(Transaction $transaction, Carbon $date): array
    {
        $toAsset = $transaction->to_asset;
        $toAmount = (float)$transaction->to_amount;
        
        $totalUsdt = $this->calculateTotalUsdt($toAsset, $toAmount, $date);
        $totalBrl = $this->calculateTotalBrl($totalUsdt, $date);
        
        return [
            'total_usdt' => $totalUsdt,
            'total_brl' => $totalBrl
        ];
    }
    
    /**
     * Calcula o valor total em USDT
     */
    private function calculateTotalUsdt(string $asset, float $amount, Carbon $date): float
    {
        if (in_array($asset, self::STABLECOINS)) {
            return $amount;
        }
        
        $prices = $this->getHistoricalPrice($asset, $date);
        return $amount * ($prices['price_usd'] ?? 0);
    }
    
    /**
     * Calcula o valor total em BRL
     */
    private function calculateTotalBrl(float $totalUsdt, Carbon $date): float
    {
        if ($totalUsdt <= 0) {
            return 0;
        }
        
        $usdBrlRate = $this->getUsdBrlRate($date);
        return $totalUsdt * $usdBrlRate;
    }
    
    /**
     * Obtém o preço histórico de um ativo
     */
    private function getHistoricalPrice(string $asset, Carbon $date): array
    {
        $dateKey = $date->toDateString();
        $cacheKey = "{$asset}_{$dateKey}";
        
        if (isset($this->priceCache[$cacheKey])) {
            return $this->priceCache[$cacheKey];
        }
        
        $priceRecord = $this->priceService->getOrCreatePrice($asset, $date);
        
        $prices = [
            'price_usd' => $priceRecord->price_usd ?? 0,
            'price_brl' => $priceRecord->price_brl ?? 0
        ];
        
        if ($prices['price_usd'] > 0 || $prices['price_brl'] > 0) {
            $this->priceCache[$cacheKey] = $prices;
        }
        
        return $prices;
    }
    
    /**
     * Obtém a taxa de câmbio USD/BRL
     */
    private function getUsdBrlRate(Carbon $date): float
    {
        $dateKey = $date->toDateString();
        
        if (isset($this->usdBrlCache[$dateKey])) {
            return $this->usdBrlCache[$dateKey];
        }
        
        // Buscar via serviço de preços (que já tem lógica de USD/BRL)
        $priceRecord = $this->priceService->getOrCreatePrice('USDT', $date);
        $rate = $priceRecord->price_brl ?? 5.0; // Fallback para taxa aproximada
        
        $this->usdBrlCache[$dateKey] = $rate;
        
        return $rate;
    }
    
    /**
     * Verifica transações específicas por referências
     * 
     * @param User $user
     * @param array $references Array de referências (trade IDs ou quote IDs)
     * @return array Estatísticas da verificação
     */
    public function verifySpecificTransactions(User $user, array $references): array
    {
        Log::info("🔍 [Verificação Específica] Verificando transações específicas", [
            'user_id' => $user->id,
            'references_count' => count($references)
        ]);
        
        $stats = [
            'total_checked' => 0,
            'total_updated' => 0,
            'total_failed' => 0,
            'updated_transactions' => [],
            'failed_transactions' => []
        ];
        
        $transactions = Transaction::where('user_id', $user->id)
            ->whereIn('reference', $references)
            ->get();
        
        $stats['total_checked'] = $transactions->count();
        
        foreach ($transactions as $transaction) {
            $result = $this->updateTransactionValues($transaction);
            
            if ($result['success']) {
                $stats['total_updated']++;
                $stats['updated_transactions'][] = [
                    'id' => $transaction->id,
                    'reference' => $transaction->reference,
                    'old_usdt' => $result['old_usdt'],
                    'new_usdt' => $result['new_usdt'],
                    'old_brl' => $result['old_brl'],
                    'new_brl' => $result['new_brl']
                ];
            } else {
                $stats['total_failed']++;
                $stats['failed_transactions'][] = [
                    'id' => $transaction->id,
                    'reference' => $transaction->reference,
                    'reason' => $result['reason']
                ];
            }
        }
        
        return $stats;
    }
    
    /**
     * Gera relatório de transações com valores zero
     * 
     * @param User $user
     * @param int|null $exchangeId
     * @return array
     */
    public function generateZeroValueReport(User $user, ?int $exchangeId = null): array
    {
        $query = Transaction::where('user_id', $user->id)
            ->where(function($q) {
                $q->where('total_usdt', 0)
                  ->orWhere('total_brl', 0)
                  ->orWhereNull('total_usdt')
                  ->orWhereNull('total_brl');
            });
            
        if ($exchangeId) {
            $query->where('source_id', $exchangeId);
        }
        
        $transactions = $query->orderBy('date', 'desc')->get();
        
        $report = [
            'total_count' => $transactions->count(),
            'by_type' => [],
            'by_month' => [],
            'by_asset' => [],
            'transactions' => []
        ];
        
        foreach ($transactions as $transaction) {
            // Agrupar por tipo
            $type = $transaction->type;
            if (!isset($report['by_type'][$type])) {
                $report['by_type'][$type] = 0;
            }
            $report['by_type'][$type]++;
            
            // Agrupar por mês
            $month = Carbon::parse($transaction->date)->format('Y-m');
            if (!isset($report['by_month'][$month])) {
                $report['by_month'][$month] = 0;
            }
            $report['by_month'][$month]++;
            
            // Agrupar por ativo
            $asset = $transaction->to_asset;
            if (!isset($report['by_asset'][$asset])) {
                $report['by_asset'][$asset] = 0;
            }
            $report['by_asset'][$asset]++;
            
            // Adicionar à lista
            $report['transactions'][] = [
                'id' => $transaction->id,
                'reference' => $transaction->reference,
                'type' => $transaction->type,
                'date' => $transaction->date->toDateString(),
                'from_asset' => $transaction->from_asset,
                'from_amount' => $transaction->from_amount,
                'to_asset' => $transaction->to_asset,
                'to_amount' => $transaction->to_amount,
                'total_usdt' => $transaction->total_usdt,
                'total_brl' => $transaction->total_brl
            ];
        }
        
        return $report;
    }
}

