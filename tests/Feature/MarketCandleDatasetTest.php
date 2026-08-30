<?php

namespace Tests\Feature;

use App\Models\Exchange;
use App\Models\MarketCandle;
use App\Services\MarketCandleDatasetService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class MarketCandleDatasetTest extends TestCase
{
    use RefreshDatabase;

    private Exchange $exchange;

    protected function setUp(): void
    {
        parent::setUp();

        $this->exchange = Exchange::query()->create([
            'name' => 'binance',
            'country_code' => 'MT',
            'description' => 'Binance',
        ]);
    }

    public function test_it_persists_normalized_candles_with_an_idempotent_unique_key(): void
    {
        $service = app(MarketCandleDatasetService::class);
        $candle = $this->candle([
            'symbol' => 'btc/usdt',
            'open' => '0100.0000',
            'high' => '102.0000',
            'low' => '099.0000',
            'close' => '101.5000',
            'volume' => '10.0000',
        ]);

        $service->persist([$candle]);
        $service->persist([array_merge($candle, ['close' => '101.7500'])]);

        $this->assertDatabaseCount('market_candles', 1);
        $stored = MarketCandle::query()->sole();
        $this->assertSame('BTCUSDT', $stored->symbol);
        $this->assertSame('101.7500000000000000', $stored->close);
        $this->assertSame('2026-01-01T00:00:00+00:00', $stored->open_time->toIso8601String());
        $this->assertSame('2026-01-01T01:00:00+00:00', $stored->close_time->toIso8601String());
    }

    public function test_it_rejects_invalid_ohlc_unsupported_timeframe_and_invalid_candle_duration(): void
    {
        $service = app(MarketCandleDatasetService::class);

        foreach ([
            $this->candle(['high' => '99.0000']),
            $this->candle(['timeframe' => '15m']),
            $this->candle(['close_time' => '2026-01-01T00:00:00Z']),
        ] as $invalidCandle) {
            try {
                $service->persist([$invalidCandle]);
                $this->fail('Um candle inválido não deveria ser persistido.');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('market_candles', 0);
            }
        }
    }

    public function test_it_selects_only_closed_candles_and_detects_a_gap_without_fabricating_data(): void
    {
        $service = app(MarketCandleDatasetService::class);
        $service->persist([
            $this->candle(['open_time' => '2026-01-01T00:00:00Z', 'close_time' => '2026-01-01T01:00:00Z']),
            $this->candle(['open_time' => '2026-01-01T02:00:00Z', 'close_time' => '2026-01-01T03:00:00Z']),
            $this->candle(['open_time' => '2026-01-01T03:00:00Z', 'close_time' => '2026-01-01T04:00:00Z']),
        ]);

        $candles = $service->select(
            $this->exchange->id,
            'BTCUSDT',
            '1h',
            '2026-01-01T00:00:00Z',
            '2026-01-01T04:00:00Z',
            CarbonImmutable::parse('2026-01-01T03:00:00Z'),
        );

        $this->assertCount(2, $candles);
        $this->assertSame([
            [
                'expected_open_time' => '2026-01-01T01:00:00+00:00',
                'actual_open_time' => '2026-01-01T02:00:00+00:00',
            ],
        ], $service->detectGaps($candles, '1h'));
    }

    public function test_dataset_hash_is_stable_after_normalization_and_independent_of_input_order(): void
    {
        $service = app(MarketCandleDatasetService::class);
        $first = $this->candle([
            'symbol' => 'btc/usdt',
            'open_time' => '2026-01-01T00:00:00Z',
            'close_time' => '2026-01-01T01:00:00Z',
            'open' => '100.0000',
            'high' => '102.0000',
            'low' => '99.0000',
            'close' => '101.0000',
            'volume' => '10.0000',
        ]);
        $second = $this->candle([
            'open_time' => '2026-01-01T01:00:00Z',
            'close_time' => '2026-01-01T02:00:00Z',
            'open' => '101.0000',
            'high' => '103.0000',
            'low' => '100.0000',
            'close' => '102.0000',
            'volume' => '11.0000',
        ]);

        $forward = $service->hash([$first, $second]);
        $reverse = $service->hash([$second, array_merge($first, ['symbol' => 'BTCUSDT'])]);

        $this->assertSame($forward, $reverse);
        $this->assertSame(64, strlen($forward));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function candle(array $overrides = []): array
    {
        return array_merge([
            'exchange_id' => $this->exchange->id,
            'symbol' => 'BTCUSDT',
            'timeframe' => '1h',
            'open_time' => '2026-01-01T00:00:00Z',
            'close_time' => '2026-01-01T01:00:00Z',
            'open' => '100.0000',
            'high' => '102.0000',
            'low' => '99.0000',
            'close' => '101.0000',
            'volume' => '10.0000',
            'trade_count' => 10,
            'source' => 'binance_public',
            'fetched_at' => '2026-01-02T00:00:00Z',
        ], $overrides);
    }
}
