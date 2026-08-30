<?php

namespace Database\Factories;

use App\Models\Exchange;
use App\Models\MarketCandle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketCandle>
 */
class MarketCandleFactory extends Factory
{
    protected $model = MarketCandle::class;

    public function definition(): array
    {
        $openTime = CarbonImmutable::parse('2026-01-01T00:00:00Z');

        return [
            'exchange_id' => fn () => Exchange::query()->firstOrCreate(
                ['name' => 'binance'],
                ['country_code' => 'MT', 'description' => 'Binance'],
            )->id,
            'symbol' => 'BTCUSDT',
            'timeframe' => '1h',
            'open_time' => $openTime,
            'close_time' => $openTime->addHour(),
            'open' => '100.0000000000000000',
            'high' => '102.0000000000000000',
            'low' => '99.0000000000000000',
            'close' => '101.0000000000000000',
            'volume' => '10.0000000000000000',
            'trade_count' => 10,
            'source' => 'binance_public',
            'fetched_at' => CarbonImmutable::parse('2026-01-02T00:00:00Z'),
        ];
    }

    public function fourHours(): static
    {
        return $this->state(function (array $attributes): array {
            $openTime = CarbonImmutable::parse($attributes['open_time'])->utc();

            return [
                'timeframe' => '4h',
                'close_time' => $openTime->addHours(4),
            ];
        });
    }
}
