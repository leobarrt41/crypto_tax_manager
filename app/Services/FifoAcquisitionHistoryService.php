<?php

namespace App\Services;

use App\Models\FifoInventoryGap;
use App\Models\User;
use App\Models\UserApiKey;

class FifoAcquisitionHistoryService
{
    public function hasOpenGaps(int $userId, int $year, ?int $month = null): bool
    {
        return $this->openGapsQuery($userId, $year, $month)->exists();
    }

    public function openGapsCount(int $userId, int $year, ?int $month = null): int
    {
        return $this->openGapsQuery($userId, $year, $month)->count();
    }

    /** @return array<string, mixed> */
    public function forYear(User $user, int $year): array
    {
        $gaps = $this->openGapsQuery($user->id, $year)
            ->with(['transaction:id,reference,txid,type,from_asset,to_asset,from_amount,to_amount,date'])
            ->orderBy('occurred_at')
            ->get()
            ->map(function (FifoInventoryGap $gap): array {
                return [
                    'id' => $gap->id,
                    'asset' => $gap->asset,
                    'required_quantity' => (float) $gap->required_quantity,
                    'available_quantity' => (float) $gap->available_quantity,
                    'missing_quantity' => (float) $gap->missing_quantity,
                    'pending_cost_quantity' => (float) $gap->pending_cost_quantity,
                    'quantity_status' => $gap->quantity_status,
                    'cost_status' => $gap->cost_status,
                    'occurred_at' => $gap->occurred_at?->toIso8601String(),
                    'status' => $gap->status,
                    'reason' => $gap->reason,
                    'source' => $gap->source,
                    'transaction' => $gap->transaction ? [
                        'id' => $gap->transaction->id,
                        'reference' => $gap->transaction->reference,
                        'txid' => $gap->transaction->txid,
                        'type' => $gap->transaction->type,
                        'from_asset' => $gap->transaction->from_asset,
                        'from_amount' => $gap->transaction->from_amount,
                        'to_asset' => $gap->transaction->to_asset,
                        'to_amount' => $gap->transaction->to_amount,
                        'date' => $gap->transaction->date?->toIso8601String(),
                    ] : null,
                ];
            })
            ->values();

        $coverageService = app(TransactionImportCoverageService::class);
        $coverage = UserApiKey::query()
            ->where('user_id', $user->id)
            ->with('exchange:id,name')
            ->get()
            ->filter(fn (UserApiKey $key) => $key->exchange !== null)
            ->map(fn (UserApiKey $key): array => [
                'exchange_id' => $key->exchange_id,
                'exchange_name' => $key->exchange->name,
                'data' => $coverageService->forYear($user, $key->exchange_id, $year),
            ])
            ->values();

        $quantityMissingCount = $gaps
            ->filter(fn (array $gap): bool => $gap['quantity_status'] !== FifoInventoryGap::QUANTITY_COMPLETE)
            ->count();
        $costPendingCount = $gaps
            ->filter(fn (array $gap): bool => $gap['quantity_status'] === FifoInventoryGap::QUANTITY_COMPLETE
                && $gap['cost_status'] !== FifoInventoryGap::COST_KNOWN)
            ->count();

        return [
            'year' => $year,
            'status' => $gaps->isEmpty() ? 'complete' : 'incomplete',
            'is_official_export_available' => $gaps->isEmpty(),
            // Mantido para consumidores existentes; a interface nova deve usar
            // as contagens separadas, que não podem ser somadas como se fossem
            // a mesma pendência.
            'open_gaps_count' => $gaps->count(),
            'quantity_missing_count' => $quantityMissingCount,
            'cost_pending_count' => $costPendingCount,
            'gaps' => $gaps,
            'coverage' => $coverage,
            'manual_correction_warning' => 'Use a correção manual somente quando a aquisição não estiver nas transações importadas. Cadastrar novamente um ativo já reconstruído pode duplicar os lotes FIFO.',
        ];
    }

    private function openGapsQuery(int $userId, int $year, ?int $month = null)
    {
        return FifoInventoryGap::query()
            ->where('user_id', $userId)
            ->where('status', FifoInventoryGap::STATUS_OPEN)
            ->whereYear('occurred_at', $year)
            ->when($month !== null, fn ($query) => $query->whereMonth('occurred_at', $month));
    }
}
