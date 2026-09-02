<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionReconciliation;
use App\Models\User;
use App\Support\DecimalMath;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BinanceApiCsvReconciliationService
{
    public const MATCH_TYPE_DETERMINISTIC = 'binance_api_csv_stable_id_v2';

    public const MATCH_TYPE_HEURISTIC = 'binance_api_csv_window_v2';

    public function __construct(private readonly DecimalMath $decimal) {}

    /** @return array{status:string,reconciliation:?TransactionReconciliation,candidates:int} */
    public function reconcileTransaction(Transaction $transaction, bool $persist = true): array
    {
        if (strtolower((string) $transaction->type) !== 'convert'
            || ! in_array($transaction->import_origin, ['binance_api', 'binance_annual_csv'], true)) {
            return ['status' => 'not_eligible', 'reconciliation' => null, 'candidates' => 0];
        }

        $transaction->refresh();
        $existing = TransactionReconciliation::query()
            ->where(fn (Builder $query) => $query
                ->where('canonical_transaction_id', $transaction->id)
                ->orWhere('matched_transaction_id', $transaction->id))
            ->first();
        if ($existing !== null) {
            return ['status' => 'already_reconciled', 'reconciliation' => $existing, 'candidates' => 1];
        }

        $transactionIsCsv = $transaction->import_origin === 'binance_annual_csv';
        $candidates = $this->counterpartCandidates($transaction, $transactionIsCsv)->get()
            ->filter(fn (Transaction $candidate): bool => $this->sameEconomicEvent($transaction, $candidate))
            ->values();
        if ($candidates->count() !== 1) {
            return [
                'status' => $candidates->isEmpty() ? 'no_match' : 'ambiguous',
                'reconciliation' => null,
                'candidates' => $candidates->count(),
            ];
        }

        $counterpart = $candidates->first();
        $csv = $transactionIsCsv ? $transaction : $counterpart;
        $api = $transactionIsCsv ? $counterpart : $transaction;
        $stableId = $this->commonStableId($csv, $api);

        if (! $persist) {
            return [
                'status' => $stableId === null ? 'heuristic_candidate_found' : 'deterministic_match_found',
                'reconciliation' => null,
                'candidates' => 1,
            ];
        }

        $reconciliation = DB::transaction(function () use ($csv, $api, $stableId): TransactionReconciliation {
            Transaction::query()->whereKey([$csv->id, $api->id])->lockForUpdate()->get();

            return TransactionReconciliation::query()->create($this->attributes($csv, $api, $stableId));
        });

        return [
            'status' => 'pending_review',
            'reconciliation' => $reconciliation,
            'candidates' => 1,
        ];
    }

    /** @return array<string, int> */
    public function reconcileUserYear(int $userId, int $year, bool $persist = false): array
    {
        $stats = array_fill_keys([
            'csv_transactions_scanned', 'confirmed', 'pending_review', 'deterministic_matches_found',
            'heuristic_candidates_found', 'already_reconciled', 'no_match', 'ambiguous',
        ], 0);

        Transaction::query()
            ->where('user_id', $userId)
            ->where('type', 'convert')
            ->where('import_origin', 'binance_annual_csv')
            ->whereYear('date', $year)
            ->orderBy('date')->orderBy('id')
            ->each(function (Transaction $transaction) use (&$stats, $persist): void {
                $stats['csv_transactions_scanned']++;
                $result = $this->reconcileTransaction($transaction, $persist);
                $key = match ($result['status']) {
                    'deterministic_match_found' => 'deterministic_matches_found',
                    'heuristic_candidate_found' => 'heuristic_candidates_found',
                    default => $result['status'],
                };
                if (array_key_exists($key, $stats)) {
                    $stats[$key]++;
                }
            });

        return $stats;
    }

    public function transition(
        TransactionReconciliation $reconciliation,
        string $status,
        User $actor,
        ?string $reason = null,
    ): TransactionReconciliation
    {
        if ($actor->id !== $reconciliation->user_id) {
            throw new InvalidArgumentException('O usuário responsável deve ser o proprietário da conciliação.');
        }

        return DB::transaction(function () use ($reconciliation, $status, $actor, $reason): TransactionReconciliation {
            $locked = TransactionReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            $current = $locked->status;
            $valid = ($current === TransactionReconciliation::STATUS_PENDING_REVIEW
                    && in_array($status, [TransactionReconciliation::STATUS_CONFIRMED, TransactionReconciliation::STATUS_REJECTED], true))
                || ($current === TransactionReconciliation::STATUS_CONFIRMED && $status === TransactionReconciliation::STATUS_REVOKED);
            if (! $valid) {
                throw new InvalidArgumentException('Transição de conciliação inválida.');
            }

            $occurredAt = now('UTC');
            $locked->forceFill([
                'status' => $status,
                $status.'_at' => $occurredAt,
            ])->save();

            DB::table('transaction_reconciliation_events')->insert([
                'reconciliation_id' => $locked->id,
                'actor_user_id' => $actor->id,
                'event_type' => $status,
                'previous_status' => $current,
                'new_status' => $status,
                'reason' => $reason,
                'evidence' => json_encode([
                    'match_type' => $locked->match_type,
                    'confidence' => $locked->confidence,
                    'fingerprint' => $locked->fingerprint,
                    'matching_evidence' => $locked->matching_evidence,
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);

            return $locked->refresh();
        });
    }

    private function counterpartCandidates(Transaction $transaction, bool $transactionIsCsv): Builder
    {
        $date = CarbonImmutable::parse($transaction->date)->utc();

        return Transaction::query()
            ->where('user_id', $transaction->user_id)
            ->where('id', '!=', $transaction->id)
            ->where('type', 'convert')
            ->where('import_origin', $transactionIsCsv ? 'binance_api' : 'binance_annual_csv')
            ->where('from_asset', strtoupper((string) $transaction->from_asset))
            ->where('to_asset', strtoupper((string) $transaction->to_asset))
            ->whereBetween('date', [$date->subSeconds(5), $date->addSeconds(5)])
            ->whereDoesntHave('canonicalReconciliations')
            ->whereDoesntHave('duplicateReconciliation')
            ->orderBy('id');
    }

    private function sameEconomicEvent(Transaction $left, Transaction $right): bool
    {
        return $this->sameDecimal($left->from_amount, $right->from_amount)
            && $this->sameDecimal($left->to_amount, $right->to_amount);
    }

    private function sameDecimal(mixed $left, mixed $right): bool
    {
        return is_numeric($left) && is_numeric($right)
            && $this->decimal->compare((string) $left, (string) $right) === 0;
    }

    private function commonStableId(Transaction $csv, Transaction $api): ?array
    {
        foreach (['reference', 'txid', 'order_id', 'trade_id'] as $field) {
            $csvValue = trim((string) $csv->{$field});
            $apiValue = trim((string) $api->{$field});
            if ($csvValue !== '' && hash_equals($csvValue, $apiValue)) {
                return ['field' => $field, 'value' => $csvValue];
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function attributes(Transaction $csv, Transaction $api, ?array $stableId): array
    {
        $fingerprintData = [
            'type' => 'convert',
            'from_asset' => strtoupper((string) $csv->from_asset),
            'from_amount' => $this->decimal->normalize((string) $csv->from_amount),
            'to_asset' => strtoupper((string) $csv->to_asset),
            'to_amount' => $this->decimal->normalize((string) $csv->to_amount),
            'date_utc' => CarbonImmutable::parse($csv->date)->utc()->toIso8601String(),
        ];
        $now = now('UTC');

        return [
            'user_id' => $csv->user_id,
            'canonical_transaction_id' => $csv->id,
            'matched_transaction_id' => $api->id,
            'match_type' => $stableId === null ? self::MATCH_TYPE_HEURISTIC : self::MATCH_TYPE_DETERMINISTIC,
            'confidence' => $stableId === null ? 'medium' : 'high',
            'fingerprint' => hash('sha256', json_encode($fingerprintData, JSON_THROW_ON_ERROR)),
            'status' => TransactionReconciliation::STATUS_PENDING_REVIEW,
            'matching_evidence' => [
                ...$fingerprintData,
                'stable_id' => $stableId,
                'csv_reference' => $csv->reference,
                'api_reference' => $api->reference,
                'date_tolerance_seconds' => 5,
            ],
            'reconciled_at' => $now,
            'pending_review_at' => $now,
            'confirmed_at' => null,
        ];
    }
}
