<?php

namespace App\Contracts;

use Carbon\CarbonImmutable;

interface MarketDataProviderInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchCandles(
        string $symbol,
        string $timeframe,
        CarbonImmutable $startAt,
        CarbonImmutable $endAt,
    ): array;
}
