<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class FifoCalculatorService
{
    /**
     * Calcula lucro/prejuízo usando FIFO para uma transação de venda
     */
    public function calculateFor(Transaction $sale)
    {
        if (!in_array($sale->operation, ['saida']) || !$sale->to_asset || !$sale->to_amount) {
            return null; // Apenas transações de saída com ativos e quantidade
        }

        $userId = $sale->user_id;
        $asset = $sale->to_asset;
        $amountToMatch = $sale->to_amount;
        $dateLimit = $sale->date;

        $remaining = $amountToMatch;
        $totalCost = 0;

        DB::beginTransaction();

        try {
            // Seleciona entradas anteriores com saldo
            $buys = Transaction::where('user_id', $userId)
                ->where('to_asset', $asset)
                ->where('operation', 'entrada')
                ->where('date', '<=', $dateLimit)
                ->where('remaining_quantity', '>', 0)
                ->orderBy('date')
                ->lockForUpdate()
                ->get();

            foreach ($buys as $buy) {
                $available = $buy->remaining_quantity;

                if ($available <= 0) {
                    continue;
                }

                $used = min($remaining, $available);
                $unitCost = $buy->price ?? 0;
                $totalCost += $unitCost * $used;

                // Atualiza quantidade restante no lote original
                $buy->remaining_quantity -= $used;
                $buy->save();

                $remaining -= $used;

                if ($remaining <= 0) {
                    break;
                }
            }

            if ($remaining > 0) {
                Log::warning("Transação {$sale->id} possui quantidade superior ao disponível em FIFO.");
            }

            $saleValue = $sale->total_brl ?? 0;
            $profit = $saleValue - $totalCost;

            $sale->profit_loss = $profit;
            $sale->save();

            DB::commit();

            return $profit;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Erro no cálculo FIFO: " . $e->getMessage());
            return null;
        }
    }
}
