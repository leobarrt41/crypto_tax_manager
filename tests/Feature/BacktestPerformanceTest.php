<?php

namespace Tests\Feature;

use App\Models\Exchange;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use App\Services\DeterministicBacktestEngine;
use App\Services\MarketCandleDatasetService;
use App\Services\StrategyVersionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BacktestPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_backtest_of_180_days_in_one_hour_candles_completes_within_safe_http_budget(): void
    {
        $exchange = Exchange::query()->create([
            'name' => 'binance',
            'country_code' => 'MT',
            'description' => 'Binance',
        ]);
        $version = $this->createVersion();
        $candles = $this->candles($exchange->id, 180 * 24);
        $scenario = [
            'exchange_id' => $exchange->id,
            'symbol' => 'BTCUSDT',
            'timeframe' => '1h',
            'initial_capital' => '10000',
            'allocation_pct' => '100',
            'fee_rate' => '0.1',
            'slippage_rate' => '0.05',
            'close_open_position_at_end' => false,
            'evaluation_time' => '2026-07-02T00:00:00Z',
        ];

        $startedAt = hrtime(true);
        $result = app(DeterministicBacktestEngine::class)->run(
            $version,
            $candles,
            $scenario,
            app(MarketCandleDatasetService::class)->hash($candles),
        );
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        $this->assertSame('completed', $result['status']);
        $this->assertCount(4320, $result['metrics']['equity_curve']);
        $this->assertLessThan(10.0, $elapsedSeconds, 'O backtest de 180 dias em 1h ultrapassou o orçamento local de 10 segundos.');
    }

    /** @return array<int, array<string, mixed>> */
    private function candles(int $exchangeId, int $count): array
    {
        $start = CarbonImmutable::parse('2026-01-01T00:00:00Z');
        $candles = [];

        for ($index = 0; $index < $count; $index++) {
            $openTime = $start->addHours($index);
            $closeTime = $openTime->addHour();
            $open = 90000 + ($index % 720) + intdiv($index, 720);
            $close = $open + (($index % 9) - 4);

            $candles[] = [
                'exchange_id' => $exchangeId,
                'symbol' => 'BTCUSDT',
                'timeframe' => '1h',
                'open_time' => $openTime->toIso8601String(),
                'close_time' => $closeTime->toIso8601String(),
                'open' => (string) $open,
                'high' => (string) (max($open, $close) + 5),
                'low' => (string) (min($open, $close) - 5),
                'close' => (string) $close,
                'volume' => '10',
                'source' => 'performance_fixture',
                'is_closed' => true,
            ];
        }

        return $candles;
    }

    private function createVersion(): TradingStrategyVersion
    {
        $user = User::factory()->create();
        $strategy = app(StrategyVersionService::class)->createStrategy($user, 'Benchmark 180d', null, [
            'schema_version' => 1,
            'logic' => 'all',
            'entry_conditions' => [[
                'indicator' => 'sma',
                'parameters' => ['period' => 20],
                'operator' => 'greater_than',
                'value' => 0,
            ]],
            'exit_conditions' => [],
            'risk' => ['stop_loss_pct' => null, 'take_profit_pct' => null],
        ]);

        return $strategy->currentVersion;
    }
}
