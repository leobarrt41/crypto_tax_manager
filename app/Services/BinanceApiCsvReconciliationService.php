<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionReconciliation;
use App\Models\UserApiKey;
use App\Support\DecimalMath;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class BinanceApiCsvReconciliationService
{
    public const MATCH_TYPE = 'binance_api_csv_convert_v1';

    public function __construct(private readonly DecimalMath $decimal) {}

    /** @return array{status:string,reconciliation:?TransactionReconciliation,candidates:int} */
    public function reconcileTransaction(Transaction $transaction, bool $persist = true): array
    {
        if (strtolower((string) $transaction->type) !== 'convert') {
            return ['status' => 'not_eligible', 'reconciliation' => null, 'candidates' => 0];
        }

        $transaction->refresh();
        $isCsv = $this->isAnnualCsv($transaction);
        $isApi = $this->isApiTransaction($transaction);
        if (! $isCsv && ! $isApi) {
            return ['status' => 'not_eligible', 'reconciliation' => null, 'candidates' => 0];
        }

        $existing = TransactionReconciliation::query()
            ->where(function (Builder $query) use ($transaction): void {
                $query->where('canonical_transaction_id', $transaction->id)
                    ->orWhere('matched_transaction_id', $transaction->id);
            })
            ->first();
        if ($existing !== null) {
            return ['status' => 'already_reconciled', 'reconciliation' => $existing, 'candidates' => 1];
        }

        $candidates = $this->counterpartCandidates($transaction, $isCsv)->get()
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
        $csv = $isCsv ? $transaction : $counterpart;
        $api = $isApi ? $transaction : $counterpart;
        $attributes = $this->attributes($csv, $api);

        if (! $persist) {
            return ['status' => 'match_found', 'reconciliation' => null, 'candidates' => 1];
        }

        $reconciliation = TransactionReconciliation::query()->updateOrCreate(
            ['matched_transaction_id' => $api->id],
            $attributes,
        );

        return ['status' => 'reconciled', 'reconciliation' => $reconciliation, 'candidates' => 1];
    }

    /** @return array<string, int> */
    public function reconcileUserYear(int $userId, int $year, bool $persist = false): array
    {
        $stats = [
            'csv_transactions_scanned' => 0,
            'reconciled' => 0,
            'matches_found' => 0,
            'already_reconciled' => 0,
            'no_match' => 0,
            'ambiguous' => 0,
        ];

        Transaction::query()
            ->where('user_id', $userId)
            ->where('type', 'convert')
            ->whereYear('date', $year)
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->filter(fn (Transaction $transaction): bool => $this->isAnnualCsv($transaction))
            ->each(function (Transaction $transaction) use (&$stats, $persist): void {
                $stats['csv_transactions_scanned']++;
                $result = $this->reconcileTransaction($transaction, $persist);
                if ($result['status'] === 'match_found') {
                    $stats['matches_found']++;
                } elseif (array_key_exists($result['status'], $stats)) {
                    $stats[$result['status']]++;
                }
            });

        return $stats;
    }

    private function counterpartCandidates(Transaction $transaction, bool $transactionIsCsv): Builder
    {
        $date = CarbonImmutable::parse($transaction->date)->utc();

        return Transaction::query()
            ->where('user_id', $transaction->user_id)
            ->where('id', '!=', $transaction->id)
            ->where('type', 'convert')
            ->where('from_asset', strtoupper((string) $transaction->from_asset))
            ->where('to_asset', strtoupper((string) $transaction->to_asset))
            ->whereBetween('date', [$date->subSeconds(5), $date->addSeconds(5)])
            ->when(
                $transactionIsCsv,
                fn (Builder $query): Builder => $query->where('source_type', UserApiKey::class)->whereNull('import_metadata'),
                fn (Builder $query): Builder => $query->whereNotNull('import_metadata'),
            )
            ->orderBy('id');
    }

    private function sameEconomicEvent(Transaction $left, Transaction $right): bool
    {
        return $this->sameDecimal($left->from_amount, $right->from_amount)
            && $this->sameDecimal($left->to_amount, $right->to_amount);
    }

    private function sameDecimal(mixed $left, mixed $right): bool
    {
        return is_numeric($left)
            && is_numeric($right)
            && $this->decimal->compare((string) $left, (string) $right) === 0;
    }

    private function isAnnualCsv(Transaction $transaction): bool
    {
        return data_get($transaction->import_metadata, 'format') === 'binance_annual_csv';
    }

    private function isApiTransaction(Transaction $transaction): bool
    {
        return $transaction->source_type === UserApiKey::class
            && ! $this->isAnnualCsv($transaction);
    }

    /** @return array<string, mixed> */
    private function attributes(Transaction $csv, Transaction $api): array
    {
        $brlValues = data_get($csv->import_metadata, 'brl_values', []);
        $fingerprintData = [
            'type' => 'convert',
            'from_asset' => strtoupper((string) $csv->from_asset),
            'from_amount' => $this->decimal->normalize((string) $csv->from_amount),
            'to_asset' => strtoupper((string) $csv->to_asset),
            'to_amount' => $this->decimal->normalize((string) $csv->to_amount),
            'date_utc' => CarbonImmutable::parse($csv->date)->utc()->toIso8601String(),
        ];

        return [
            'user_id' => $csv->user_id,
            'canonical_transaction_id' => $csv->id,
            'match_type' => self::MATCH_TYPE,
            'confidence' => 'high',
            'fingerprint' => hash('sha256', json_encode($fingerprintData, JSON_THROW_ON_ERROR)),
            'status' => TransactionReconciliation::STATUS_CONFIRMED,
            'matching_evidence' => [
                ...$fingerprintData,
                'csv_reference' => $csv->reference,
                'api_reference' => $api->reference,
                'csv_received_value_brl' => isset($brlValues['received_value_brl'])
                    ? (string) $brlValues['received_value_brl']
                    : null,
                'csv_selected_brl_source' => $brlValues['selected_source'] ?? null,
                'date_tolerance_seconds' => 5,
            ],
            'reconciled_at' => now('UTC'),
        ];
    }
}
