<?php

namespace App\Services;

use App\Models\MarketCandle;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class MarketCandleDatasetService
{
    /**
     * @param array<string, mixed> $candle
     * @return array<string, mixed>
     */
    public function normalize(array $candle): array
    {
        foreach (['exchange_id', 'symbol', 'timeframe', 'open_time', 'close_time', 'open', 'high', 'low', 'close', 'volume', 'source'] as $field) {
            if (! array_key_exists($field, $candle) || $candle[$field] === null || $candle[$field] === '') {
                throw new InvalidArgumentException("O campo {$field} é obrigatório para um candle de mercado.");
            }
        }

        $openTime = CarbonImmutable::parse($candle['open_time'], 'UTC')->utc();
        $closeTime = CarbonImmutable::parse($candle['close_time'], 'UTC')->utc();

        return [
            'exchange_id' => (int) $candle['exchange_id'],
            'symbol' => MarketCandle::normalizeSymbol((string) $candle['symbol']),
            'timeframe' => MarketCandle::normalizeTimeframe((string) $candle['timeframe']),
            'open_time' => $openTime,
            'close_time' => $closeTime,
            'open' => $this->normalizeDecimal($candle['open']), 'high' => $this->normalizeDecimal($candle['high']),
            'low' => $this->normalizeDecimal($candle['low']), 'close' => $this->normalizeDecimal($candle['close']),
            'volume' => $this->normalizeDecimal($candle['volume']),
            'trade_count' => isset($candle['trade_count']) ? (int) $candle['trade_count'] : null,
            'source' => trim((string) $candle['source']),
            'fetched_at' => isset($candle['fetched_at'])
                ? CarbonImmutable::parse($candle['fetched_at'], 'UTC')->utc()
                : now('UTC')->toImmutable(),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $candles
     * @return Collection<int, MarketCandle>
     */
    public function persist(array $candles): Collection
    {
        return collect($candles)
            ->map(fn (array $candle) => $this->normalize($candle))
            ->map(function (array $candle): MarketCandle {
                return MarketCandle::query()->updateOrCreate(
                    [
                        'exchange_id' => $candle['exchange_id'],
                        'symbol' => $candle['symbol'],
                        'timeframe' => $candle['timeframe'],
                        'open_time' => $candle['open_time'],
                    ],
                    $candle,
                );
            })
            ->values();
    }

    /** @return Collection<int, MarketCandle> */
    public function select(
        int $exchangeId,
        string $symbol,
        string $timeframe,
        CarbonInterface|string $startAt,
        CarbonInterface|string $endAt,
        ?CarbonInterface $asOf = null,
    ): Collection {
        $start = CarbonImmutable::parse($startAt, 'UTC')->utc();
        $end = CarbonImmutable::parse($endAt, 'UTC')->utc();
        $now = ($asOf ?? now('UTC'))->utc();

        if ($end->lessThanOrEqualTo($start)) {
            throw new InvalidArgumentException('O fim do período precisa ser posterior ao início.');
        }

        return MarketCandle::query()
            ->where('exchange_id', $exchangeId)
            ->where('symbol', MarketCandle::normalizeSymbol($symbol))
            ->where('timeframe', MarketCandle::normalizeTimeframe($timeframe))
            ->where('open_time', '>=', $start)
            ->where('close_time', '<=', $end)
            ->where('close_time', '<=', $now)
            ->orderBy('open_time')
            ->get();
    }

    public function isClosed(MarketCandle|array $candle, ?CarbonInterface $asOf = null): bool
    {
        $closeTime = $candle instanceof MarketCandle
            ? $candle->close_time
            : CarbonImmutable::parse($candle['close_time'] ?? null, 'UTC')->utc();

        return $closeTime->lessThanOrEqualTo(($asOf ?? now('UTC'))->utc());
    }

    /**
     * @param iterable<int, MarketCandle|array<string, mixed>> $candles
     * @return array<int, array{expected_open_time:string, actual_open_time:string}>
     */
    public function detectGaps(iterable $candles, string $timeframe): array
    {
        $duration = $this->durationFor($timeframe);
        $ordered = collect($candles)
            ->sortBy(fn (MarketCandle|array $candle) => $this->value($candle, 'open_time'))
            ->values();
        $gaps = [];

        for ($index = 1; $index < $ordered->count(); $index++) {
            $previous = CarbonImmutable::parse($this->value($ordered[$index - 1], 'open_time'), 'UTC')->utc();
            $current = CarbonImmutable::parse($this->value($ordered[$index], 'open_time'), 'UTC')->utc();
            $expected = $previous->addSeconds($duration);

            if (! $current->equalTo($expected)) {
                $gaps[] = [
                    'expected_open_time' => $expected->toIso8601String(),
                    'actual_open_time' => $current->toIso8601String(),
                ];
            }
        }

        return $gaps;
    }

    /**
     * @param iterable<int, MarketCandle|array<string, mixed>> $candles
     */
    public function hash(iterable $candles): string
    {
        $normalized = collect($candles)
            ->map(function (MarketCandle|array $candle): array {
                return [
                    'exchange_id' => (int) $this->value($candle, 'exchange_id'),
                    'symbol' => MarketCandle::normalizeSymbol((string) $this->value($candle, 'symbol')),
                    'timeframe' => MarketCandle::normalizeTimeframe((string) $this->value($candle, 'timeframe')),
                    'open_time' => CarbonImmutable::parse($this->value($candle, 'open_time'), 'UTC')->utc()->toIso8601String(),
                    'close_time' => CarbonImmutable::parse($this->value($candle, 'close_time'), 'UTC')->utc()->toIso8601String(),
                    'open' => $this->normalizeDecimal($this->value($candle, 'open')),
                    'high' => $this->normalizeDecimal($this->value($candle, 'high')),
                    'low' => $this->normalizeDecimal($this->value($candle, 'low')),
                    'close' => $this->normalizeDecimal($this->value($candle, 'close')),
                    'volume' => $this->normalizeDecimal($this->value($candle, 'volume')),
                    'source' => (string) $this->value($candle, 'source'),
                ];
            })
            ->sortBy('open_time')
            ->values()
            ->all();

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @return array<int, array<string, mixed>> */
    public function signalCandles(iterable $candles): array
    {
        return collect($candles)
            ->map(fn (MarketCandle|array $candle) => [
                'open' => (string) $this->value($candle, 'open'),
                'high' => (string) $this->value($candle, 'high'),
                'low' => (string) $this->value($candle, 'low'),
                'close' => (string) $this->value($candle, 'close'),
                'volume' => (string) $this->value($candle, 'volume'),
                'open_time' => CarbonImmutable::parse($this->value($candle, 'open_time'), 'UTC')->utc()->toIso8601String(),
                'close_time' => CarbonImmutable::parse($this->value($candle, 'close_time'), 'UTC')->utc()->toIso8601String(),
                'is_closed' => true,
            ])
            ->values()
            ->all();
    }

    private function durationFor(string $timeframe): int
    {
        return match (MarketCandle::normalizeTimeframe($timeframe)) {
            '1h' => 3600,
            '4h' => 14400,
        };
    }

    private function normalizeDecimal(mixed $value): string
    {
        $decimal = trim((string) $value);

        if (preg_match('/^\d+(?:\.\d+)?$/', $decimal) !== 1) {
            throw new InvalidArgumentException('Valores de candle devem ser decimais não negativos em formato simples.');
        }

        [$integer, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $fraction = rtrim($fraction, '0');

        return $fraction === '' ? $integer : "{$integer}.{$fraction}";
    }

    private function value(MarketCandle|array $candle, string $key): mixed
    {
        return $candle instanceof MarketCandle ? $candle->getAttribute($key) : ($candle[$key] ?? null);
    }
}
