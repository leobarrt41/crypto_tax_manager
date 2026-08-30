<?php

namespace App\Services;

use App\Contracts\MarketDataProviderInterface;
use App\Models\Exchange;
use App\Models\MarketCandle;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class MarketCandleIngestionService
{
    public function __construct(
        private readonly MarketDataProviderInterface $provider,
        private readonly MarketCandleDatasetService $datasets,
    ) {
    }

    /**
     * Atualiza somente os intervalos ausentes do cache. Não consulta conta, saldo ou endpoint autenticado.
     *
     * @return array{candles:array<int, MarketCandle>, fetched_count:int, cache_hit:bool, gaps:array<int, array{expected_open_time:string, actual_open_time:string}>, dataset_hash:string}
     */
    public function cacheFirst(
        Exchange $exchange,
        string $symbol,
        string $timeframe,
        CarbonImmutable|string $startAt,
        CarbonImmutable|string $endAt,
        ?CarbonImmutable $asOf = null,
    ): array {
        if (strtolower($exchange->name) !== 'binance') {
            throw new InvalidArgumentException('A primeira fonte pública de backtest suporta somente Binance.');
        }

        $symbol = MarketCandle::normalizeSymbol($symbol);
        $timeframe = MarketCandle::normalizeTimeframe($timeframe);
        $start = CarbonImmutable::parse($startAt, 'UTC')->utc();
        $end = CarbonImmutable::parse($endAt, 'UTC')->utc();
        $evaluationTime = ($asOf ?? now('UTC')->toImmutable())->utc();

        if ($end->lessThanOrEqualTo($start)) {
            throw new InvalidArgumentException('O fim solicitado deve ser posterior ao início.');
        }

        $cached = $this->datasets->select($exchange->id, $symbol, $timeframe, $start, $end, $evaluationTime);
        $ranges = $this->missingRanges($cached->all(), $start, $end, $timeframe, $evaluationTime);
        $fetched = 0;

        foreach ($ranges as $range) {
            $received = $this->provider->fetchCandles($symbol, $timeframe, $range['start_at'], $range['end_at']);
            $stored = $this->datasets->persist(array_map(
                fn (array $candle) => $candle + ['exchange_id' => $exchange->id],
                $received,
            ));
            $fetched += $stored->count();
        }

        $candles = $this->datasets->select($exchange->id, $symbol, $timeframe, $start, $end, $evaluationTime);

        return [
            'candles' => $candles->all(),
            'fetched_count' => $fetched,
            'cache_hit' => $ranges === [],
            'gaps' => $this->datasets->detectGaps($candles, $timeframe),
            'dataset_hash' => $this->datasets->hash($candles),
        ];
    }

    /**
     * @param array<int, MarketCandle> $candles
     * @return array<int, array{start_at:CarbonImmutable,end_at:CarbonImmutable}>
     */
    private function missingRanges(array $candles, CarbonImmutable $start, CarbonImmutable $end, string $timeframe, CarbonImmutable $evaluationTime): array
    {
        $duration = $timeframe === '1h' ? 3600 : 14400;
        $available = [];
        foreach ($candles as $candle) {
            $available[$candle->open_time->utc()->toIso8601String()] = true;
        }

        $lastEligibleOpen = $evaluationTime->subSeconds($duration);
        $limit = $end->lessThan($lastEligibleOpen->addSeconds($duration)) ? $end : $lastEligibleOpen->addSeconds($duration);
        $ranges = [];
        $rangeStart = null;
        $cursor = $start;

        while ($cursor->addSeconds($duration)->lessThanOrEqualTo($limit)) {
            $key = $cursor->toIso8601String();
            if (! isset($available[$key])) {
                $rangeStart ??= $cursor;
            } elseif ($rangeStart !== null) {
                $ranges[] = ['start_at' => $rangeStart, 'end_at' => $cursor];
                $rangeStart = null;
            }
            $cursor = $cursor->addSeconds($duration);
        }

        if ($rangeStart !== null) {
            $ranges[] = ['start_at' => $rangeStart, 'end_at' => $cursor];
        }

        return $ranges;
    }
}
