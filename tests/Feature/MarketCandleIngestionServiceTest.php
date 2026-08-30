<?php

namespace Tests\Feature;

use App\Contracts\MarketDataProviderInterface;
use App\Models\Exchange;
use App\Services\MarketCandleDatasetService;
use App\Services\MarketCandleIngestionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketCandleIngestionServiceTest extends TestCase
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

    public function test_full_cache_is_used_without_calling_the_provider(): void
    {
        $candles = $this->candles();
        app(MarketCandleDatasetService::class)->persist($candles);
        $provider = new FakeMarketDataProvider($candles);
        $this->app->instance(MarketDataProviderInterface::class, $provider);

        $result = app(MarketCandleIngestionService::class)->cacheFirst(
            $this->exchange,
            'BTCUSDT',
            '1h',
            '2026-01-01T00:00:00Z',
            '2026-01-01T04:00:00Z',
            CarbonImmutable::parse('2026-01-02T00:00:00Z'),
        );

        $this->assertTrue($result['cache_hit']);
        $this->assertSame(0, $result['fetched_count']);
        $this->assertSame(0, $provider->calls);
        $this->assertCount(4, $result['candles']);
    }

    public function test_missing_range_uses_fake_provider_and_upsert_prevents_duplicates(): void
    {
        $candles = $this->candles();
        app(MarketCandleDatasetService::class)->persist([$candles[0]]);
        $provider = new FakeMarketDataProvider($candles);
        $this->app->instance(MarketDataProviderInterface::class, $provider);

        $result = app(MarketCandleIngestionService::class)->cacheFirst(
            $this->exchange,
            'BTCUSDT',
            '1h',
            '2026-01-01T00:00:00Z',
            '2026-01-01T04:00:00Z',
            CarbonImmutable::parse('2026-01-02T00:00:00Z'),
        );

        $this->assertFalse($result['cache_hit']);
        $this->assertSame(1, $provider->calls);
        $this->assertCount(4, $result['candles']);
        $this->assertDatabaseCount('market_candles', 4);
        $this->assertSame([], $result['gaps']);
    }

    /** @return array<int, array<string, mixed>> */
    private function candles(): array
    {
        return array_map(function (int $index): array {
            $hour = str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $nextHour = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            $price = 100 + $index;

            return [
                'exchange_id' => $this->exchange->id,
                'symbol' => 'BTCUSDT',
                'timeframe' => '1h',
                'open_time' => "2026-01-01T{$hour}:00:00Z",
                'close_time' => "2026-01-01T{$nextHour}:00:00Z",
                'open' => (string) $price,
                'high' => (string) ($price + 2),
                'low' => (string) ($price - 1),
                'close' => (string) ($price + 1),
                'volume' => '10',
                'source' => 'fixture',
                'fetched_at' => '2026-01-02T00:00:00Z',
            ];
        }, range(0, 3));
    }
}

class FakeMarketDataProvider implements MarketDataProviderInterface
{
    public int $calls = 0;

    /** @param array<int, array<string, mixed>> $candles */
    public function __construct(private readonly array $candles)
    {
    }

    public function fetchCandles(
        string $symbol,
        string $timeframe,
        CarbonImmutable $startAt,
        CarbonImmutable $endAt,
    ): array {
        $this->calls++;

        return array_values(array_filter($this->candles, function (array $candle) use ($startAt, $endAt): bool {
            $openTime = CarbonImmutable::parse($candle['open_time'], 'UTC');

            return $openTime->greaterThanOrEqualTo($startAt) && $openTime->lessThan($endAt);
        }));
    }
}
