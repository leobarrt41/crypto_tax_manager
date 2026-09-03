<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionImportEvidence;
use App\Support\DecimalMath;
use Carbon\CarbonImmutable;

class TransactionImportEvidenceService
{
    public function __construct(private readonly DecimalMath $decimal) {}

    public function attachAnnualCsvEvidence(Transaction $transaction, array $mapped): ?TransactionImportEvidence
    {
        $metadata = $mapped['import_metadata'] ?? null;
        $reference = (string) ($mapped['reference'] ?? '');
        if ($transaction->import_origin !== 'binance_api'
            || ! is_array($metadata)
            || ($metadata['format'] ?? null) !== 'binance_annual_csv'
            || $reference === ''
            || $this->commonStableId($transaction, $mapped) === null
            || ! $this->sameEconomicEvent($transaction, $mapped)) {
            return null;
        }

        $evidence = [
            'format' => 'binance_annual_csv',
            'source_reference' => $reference,
            'type' => $mapped['type'] ?? null,
            'from_asset' => $mapped['from_asset'] ?? null,
            'from_amount' => isset($mapped['from_amount']) ? (string) $mapped['from_amount'] : null,
            'to_asset' => $mapped['to_asset'] ?? null,
            'to_amount' => isset($mapped['to_amount']) ? (string) $mapped['to_amount'] : null,
            'date_utc' => CarbonImmutable::parse($mapped['date'])->utc()->toIso8601String(),
            'metadata' => $metadata,
        ];
        $hash = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR));

        return TransactionImportEvidence::query()->updateOrCreate(
            [
                'transaction_id' => $transaction->id,
                'format' => 'binance_annual_csv',
                'source_reference' => $reference,
            ],
            [
                'user_id' => $transaction->user_id,
                'content_hash' => $hash,
                'evidence' => $evidence,
                'captured_at' => now('UTC'),
            ],
        );
    }

    /** @return array<string, mixed>|null */
    public function annualCsvMetadata(Transaction $transaction): ?array
    {
        if (data_get($transaction->import_metadata, 'format') === 'binance_annual_csv') {
            return $transaction->import_metadata;
        }

        $evidence = $transaction->relationLoaded('documentaryEvidences')
            ? $transaction->documentaryEvidences->firstWhere('format', 'binance_annual_csv')
            : $transaction->documentaryEvidences()->where('format', 'binance_annual_csv')->first();

        $metadata = data_get($evidence?->evidence, 'metadata');

        return is_array($metadata) ? $metadata : null;
    }

    private function sameEconomicEvent(Transaction $transaction, array $mapped): bool
    {
        if (strtolower((string) $transaction->type) !== strtolower((string) ($mapped['type'] ?? ''))
            || strtoupper((string) $transaction->from_asset) !== strtoupper((string) ($mapped['from_asset'] ?? ''))
            || strtoupper((string) $transaction->to_asset) !== strtoupper((string) ($mapped['to_asset'] ?? ''))
            || ! $this->sameDecimal($transaction->from_amount, $mapped['from_amount'] ?? null)
            || ! $this->sameDecimal($transaction->to_amount, $mapped['to_amount'] ?? null)) {
            return false;
        }

        $storedDate = CarbonImmutable::parse($transaction->date)->utc();
        $mappedDate = CarbonImmutable::parse($mapped['date'])->utc();

        return abs($storedDate->diffInSeconds($mappedDate, false)) <= 5;
    }

    /** @return array{field:string,value:string}|null */
    private function commonStableId(Transaction $transaction, array $mapped): ?array
    {
        foreach (['reference', 'txid', 'order_id', 'trade_id'] as $field) {
            $stored = trim((string) $transaction->{$field});
            $incoming = trim((string) ($mapped[$field] ?? ''));
            if ($stored !== '' && hash_equals($stored, $incoming)) {
                return ['field' => $field, 'value' => $stored];
            }
        }

        return null;
    }

    private function sameDecimal(mixed $left, mixed $right): bool
    {
        $leftDecimal = $this->comparableDecimal($left);
        $rightDecimal = $this->comparableDecimal($right);

        return $leftDecimal !== null
            && $rightDecimal !== null
            && $this->decimal->compare($leftDecimal, $rightDecimal) === 0;
    }

    private function comparableDecimal(mixed $value): ?string
    {
        return $this->decimal->canonical($value);
    }
}
