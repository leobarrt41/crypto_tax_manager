<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionImportCoverage;
use App\Models\User;
use App\Models\UserApiKey;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TransactionImportCoverageService
{
    private const AUTOMATIC_SOURCES = [
        'spot_trade' => 'binance_spot_api',
        'convert' => 'binance_convert_api',
        'deposit' => 'binance_deposit_api',
        'withdrawal' => 'binance_withdrawal_api',
        'asset_dividend' => 'binance_asset_dividend_api',
    ];

    public function recordApiCoverage(
        User $user,
        int $exchangeId,
        int $year,
        int $month,
        string $eventType,
        string $status,
        int $recordsCount = 0,
        ?string $error = null,
    ): TransactionImportCoverage {
        return TransactionImportCoverage::updateOrCreate(
            [
                'user_id' => $user->id,
                'exchange_id' => $exchangeId,
                'year' => $year,
                'month' => $month,
                'event_type' => $eventType,
            ],
            [
                'api_status' => $status,
                'api_records_count' => $recordsCount,
                'api_checked_at' => now(),
                'api_error' => $error,
            ],
        );
    }

    public function wasApiCovered(User $user, int $exchangeId, int $year, int $month, string $eventType): bool
    {
        return TransactionImportCoverage::query()
            ->where([
                'user_id' => $user->id,
                'exchange_id' => $exchangeId,
                'year' => $year,
                'month' => $month,
                'event_type' => $eventType,
                'api_status' => 'completed',
            ])
            ->exists();
    }

    public function recordCsvCoverage(
        User $user,
        int $exchangeId,
        int $year,
        int $month,
        string $eventType,
        int $recordsCount,
        string $filename,
    ): TransactionImportCoverage {
        $coverage = TransactionImportCoverage::firstOrCreate(
            [
                'user_id' => $user->id,
                'exchange_id' => $exchangeId,
                'year' => $year,
                'month' => $month,
                'event_type' => $eventType,
            ],
            [
                'api_status' => 'not_checked',
                'api_records_count' => 0,
                'csv_status' => 'not_imported',
                'csv_records_count' => 0,
            ],
        );

        $coverage->fill([
            'csv_status' => 'imported',
            'csv_records_count' => $recordsCount,
            'csv_filename' => $filename,
            'csv_imported_at' => now(),
        ]);
        $coverage->saveOrFail();

        return $coverage;
    }

    public function forYear(User $user, int $exchangeId, int $year): array
    {
        $coverage = TransactionImportCoverage::query()
            ->where('user_id', $user->id)
            ->where('exchange_id', $exchangeId)
            ->where('year', $year)
            ->get()
            ->keyBy(fn (TransactionImportCoverage $item) => "{$item->month}:{$item->event_type}");

        $apiKeyIds = UserApiKey::query()
            ->where('user_id', $user->id)
            ->where('exchange_id', $exchangeId)
            ->pluck('id');

        $manualTransactions = $this->manualTransactionsByPeriod($user, $year, $apiKeyIds);
        $months = [];
        $summary = [
            'api_covered' => 0,
            'csv_confirmed' => 0,
            'csv_to_confirm' => 0,
            'api_failed' => 0,
        ];

        for ($month = 1; $month <= 12; $month++) {
            $monthDate = Carbon::create($year, $month, 1, 0, 0, 0, 'America/Sao_Paulo');
            $isFuture = $monthDate->greaterThan(now('America/Sao_Paulo')->startOfMonth());
            $events = [];

            foreach (TransactionImportCoverage::EVENT_TYPES as $eventType) {
                $record = $coverage->get("{$month}:{$eventType}");
                $csvCount = (int) ($manualTransactions[$month][$eventType] ?? 0);
                $apiStatus = $record?->api_status ?? 'not_checked';
                $status = $this->resolveStatus($eventType, $apiStatus, $csvCount, $isFuture);

                if (isset($summary[$status])) {
                    $summary[$status]++;
                }

                $events[] = [
                    'event_type' => $eventType,
                    'label' => TransactionImportCoverage::labelFor($eventType),
                    'status' => $status,
                    'api_status' => $apiStatus,
                    'api_records_count' => (int) ($record?->api_records_count ?? 0),
                    'api_checked_at' => $record?->api_checked_at?->toIso8601String(),
                    'api_error' => $record?->api_error,
                    'csv_status' => $record?->csv_status ?? ($csvCount > 0 ? 'imported' : 'not_imported'),
                    'csv_records_count' => max($csvCount, (int) ($record?->csv_records_count ?? 0)),
                    'csv_filename' => $record?->csv_filename,
                    'csv_imported_at' => $record?->csv_imported_at?->toIso8601String(),
                    'needs_csv_confirmation' => $status === 'csv_to_confirm',
                ];
            }

            $months[] = [
                'year' => $year,
                'month' => $month,
                'month_label' => ucfirst($monthDate->locale('pt_BR')->translatedFormat('F')),
                'is_future' => $isFuture,
                'events' => $events,
            ];
        }

        return [
            'year' => $year,
            'months' => $months,
            'summary' => $summary,
        ];
    }

    private function manualTransactionsByPeriod(User $user, int $year, Collection $apiKeyIds): array
    {
        if ($apiKeyIds->isEmpty()) {
            return [];
        }

        return Transaction::query()
            ->where('user_id', $user->id)
            ->where('source_type', UserApiKey::class)
            ->whereIn('source_id', $apiKeyIds)
            ->whereYear('date', $year)
            ->get(['date', 'type', 'source'])
            ->filter(function (Transaction $transaction) {
                return !in_array($transaction->source, self::AUTOMATIC_SOURCES, true);
            })
            ->groupBy(fn (Transaction $transaction) => $transaction->date->month)
            ->map(function (Collection $transactions) {
                return $transactions
                    ->groupBy(fn (Transaction $transaction) => $this->eventTypeForTransaction($transaction->type))
                    ->map(fn (Collection $items) => $items->count())
                    ->all();
            })
            ->all();
    }

    private function resolveStatus(string $eventType, string $apiStatus, int $csvCount, bool $isFuture): string
    {
        if ($isFuture) {
            return 'not_due';
        }

        if ($apiStatus === 'completed') {
            return 'api_covered';
        }

        if ($apiStatus === 'failed') {
            return $csvCount > 0 ? 'csv_confirmed' : 'api_failed';
        }

        if ($apiStatus === 'partial') {
            return $csvCount > 0 ? 'csv_confirmed' : 'csv_to_confirm';
        }

        if ($csvCount > 0) {
            return 'csv_confirmed';
        }

        return 'csv_to_confirm';
    }

    private function eventTypeForTransaction(?string $transactionType): string
    {
        return match ($transactionType) {
            'trade' => 'spot_trade',
            'convert' => 'convert',
            'deposit' => 'deposit',
            'withdrawal' => 'withdrawal',
            'mining', 'staking' => 'earn_staking',
            'fiat_buy', 'fiat_sell' => 'fiat',
            default => 'other',
        };
    }
}
