<?php

namespace Tests\Feature;

use App\Models\Exchange;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Services\DeterministicBacktestEngine;
use App\Services\MarketCandleDatasetService;
use App\Services\StrategyVersionService;
use App\Support\DecimalMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeterministicBacktestEngineTest extends TestCase
{
    use RefreshDatabase;

    private Exchange $exchange;
    private TradingStrategyVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        $this->exchange = Exchange::query()->create([
            'name' => 'binance',
            'country_code' => 'MT',
            'description' => 'Binance',
        ]);
        $this->version = $this->createVersion();
    }

    public function test_same_version_dataset_and_scenario_produce_an_identical_result_without_mutating_inputs(): void
    {
        $candles = $this->candles();
        $hash = app(MarketCandleDatasetService::class)->hash($candles);
        $scenario = $this->scenario();
        $originalDefinition = $this->version->definition;
        $originalCandles = $candles;

        $first = app(DeterministicBacktestEngine::class)->run($this->version, $candles, $scenario, $hash);
        $second = app(DeterministicBacktestEngine::class)->run($this->version, $candles, $scenario, $hash);

        $this->assertSame($first, $second);
        $this->assertSame($originalDefinition, $this->version->fresh()->definition);
        $this->assertSame($originalCandles, $candles);
        $this->assertSame('completed', $first['status']);
        $this->assertSame($hash, $first['dataset_hash']);
    }

    public function test_precalculated_signal_series_matches_the_existing_point_evaluator_at_each_candle(): void
    {
        $candles = $this->candles();
        $series = app(\App\Services\StrategySignalEvaluator::class)->evaluateSeries($this->version, $candles);

        foreach ($candles as $index => $_) {
            $point = app(\App\Services\StrategySignalEvaluator::class)->evaluate($this->version, array_slice($candles, 0, $index + 1));

            $this->assertSame($point['decision'], $series[$index]['decision']);
            $this->assertSame($point['data_status'], $series[$index]['data_status']);
            if ($point['data_status'] === 'complete') {
                $this->assertSame($point['reason'], $series[$index]['reason']);
                $this->assertSame($point['condition_results'], $series[$index]['condition_results']);
            }
        }
    }

    public function test_precalculated_series_preserves_all_supported_indicator_decisions(): void
    {
        $user = \App\Models\User::factory()->create();
        $conditions = [
            ['indicator' => 'sma', 'parameters' => ['period' => 2], 'operator' => 'less_than', 'value' => 100],
            ['indicator' => 'ema', 'parameters' => ['period' => 2], 'operator' => 'greater_than', 'value' => 0],
            ['indicator' => 'rsi', 'parameters' => ['period' => 2], 'operator' => 'less_than_or_equal', 'value' => 100],
            ['indicator' => 'macd', 'parameters' => ['fast_period' => 2, 'slow_period' => 3, 'signal_period' => 2], 'operator' => 'greater_than', 'value' => -100],
            ['indicator' => 'bollinger', 'parameters' => ['period' => 2, 'std_dev' => 2], 'operator' => 'greater_than', 'value' => 0],
            ['indicator' => 'ma_cross', 'parameters' => ['fast_period' => 2, 'slow_period' => 3], 'operator' => 'greater_than', 'value' => -100],
            ['indicator' => 'sma', 'parameters' => ['period' => 2], 'operator' => 'greater_than_indicator', 'compare_with' => ['indicator' => 'ema', 'parameters' => ['period' => 2]]],
        ];
        $evaluator = app(\App\Services\StrategySignalEvaluator::class);

        foreach ($conditions as $index => $condition) {
            $version = app(StrategyVersionService::class)->createStrategy($user, "Indicador {$index}", null, [
                'schema_version' => 1,
                'logic' => 'all',
                'entry_conditions' => [$condition],
                'exit_conditions' => [],
                'risk' => ['stop_loss_pct' => null, 'take_profit_pct' => null],
            ])->currentVersion;
            $candles = $this->candles();
            $series = $evaluator->evaluateSeries($version, $candles);

            foreach ($candles as $candleIndex => $_) {
                $point = $evaluator->evaluate($version, array_slice($candles, 0, $candleIndex + 1));

                $context = "Indicador {$condition['indicator']} (caso {$index}), candle {$candleIndex}";
                $this->assertSame($point['decision'], $series[$candleIndex]['decision'], $context);
                $this->assertSame($point['data_status'], $series[$candleIndex]['data_status'], $context);
                if ($point['data_status'] === 'complete') {
                    $this->assertSame($point['condition_results'], $series[$candleIndex]['condition_results']);
                }
            }
        }
    }

    public function test_signal_on_candle_n_is_filled_only_at_the_open_of_candle_n_plus_one(): void
    {
        $candles = $this->candles();
        $result = $this->executeBacktest($candles);
        $entry = $result['trades'][0];
        $exit = $result['trades'][1];

        $this->assertSame('entry', $entry['event_type']);
        $this->assertSame('2026-01-01T01:00:00+00:00', $entry['signal_candle_open_time']);
        $this->assertSame('2026-01-01T02:00:00+00:00', $entry['fill_candle_open_time']);
        $this->assertSame('next_candle_open', $entry['fill_rule']);
        $this->assertSame('2026-01-01T04:00:00+00:00', $exit['signal_candle_open_time']);
        $this->assertSame('2026-01-01T05:00:00+00:00', $exit['fill_candle_open_time']);
        $this->assertSame('sell', $exit['side']);
    }

    public function test_fees_and_slippage_reduce_result_and_only_one_long_position_is_open_at_a_time(): void
    {
        $candles = $this->candles();
        $withoutCosts = $this->executeBacktest($candles, ['fee_rate' => '0', 'slippage_rate' => '0']);
        $withCosts = $this->executeBacktest($candles, ['fee_rate' => '0.1', 'slippage_rate' => '0.2']);

        $decimal = app(DecimalMath::class);
        $this->assertGreaterThan(0, $decimal->compare($withCosts['metrics']['total_fees'], '0'));
        $this->assertGreaterThan(0, $decimal->compare($withCosts['metrics']['estimated_slippage_cost'], '0'));
        $this->assertGreaterThan(0, $decimal->compare($withoutCosts['metrics']['final_equity'], $withCosts['metrics']['final_equity']));
        $this->assertSame(1, $withCosts['metrics']['entries_count']);
        $this->assertSame(1, $withCosts['metrics']['exits_count']);
        $this->assertFalse($withCosts['metrics']['open_position_at_end']);
        $this->assertSame('long_only_spot', $withCosts['simulation_config']['position_mode']);
    }

    public function test_open_position_at_end_follows_explicit_scenario_policy(): void
    {
        $candles = array_slice($this->candles(), 0, 4);

        $markedToMarket = $this->executeBacktest($candles, ['close_open_position_at_end' => false]);
        $liquidated = $this->executeBacktest($candles, ['close_open_position_at_end' => true]);

        $this->assertTrue($markedToMarket['metrics']['open_position_at_end']);
        $this->assertFalse($liquidated['metrics']['open_position_at_end']);
        $this->assertSame('exit', $liquidated['trades'][1]['event_type']);
        $this->assertSame('last_candle_close', $liquidated['trades'][1]['fill_rule']);
    }

    public function test_equity_curve_contains_one_comparable_point_per_candle_and_trade_markers(): void
    {
        $candles = $this->candles();
        $result = $this->executeBacktest($candles);
        $curve = $result['metrics']['equity_curve'];

        $this->assertCount(count($candles), $curve);
        $this->assertSame('2026-01-01T01:00:00+00:00', $curve[0]['timestamp']);
        $this->assertSame('2026-01-01T06:00:00+00:00', $curve[array_key_last($curve)]['timestamp']);
        $this->assertSame('entry', $curve[2]['event']);
        $this->assertSame('exit', $curve[5]['event']);

        foreach ($curve as $point) {
            $this->assertArrayHasKey('strategy_equity', $point);
            $this->assertArrayHasKey('buy_and_hold_equity', $point);
            $this->assertArrayHasKey('close_price', $point);
        }

        $this->assertSame($result['metrics']['final_equity'], $curve[array_key_last($curve)]['strategy_equity']);
        $this->assertSame($result['metrics']['buy_and_hold']['final_equity'], $curve[array_key_last($curve)]['buy_and_hold_equity']);
    }

    public function test_gap_blocks_backtest_without_creating_simulated_trades(): void
    {
        $candles = $this->candles();
        unset($candles[2]);
        $candles = array_values($candles);

        $result = $this->executeBacktest($candles);

        $this->assertSame('invalid_data', $result['status']);
        $this->assertSame([], $result['trades']);
        $this->assertNotEmpty($result['data_gaps']);
    }

    public function test_last_signal_without_next_candle_never_creates_a_fill(): void
    {
        $candles = array_slice($this->candles(), 0, 2);
        $result = $this->executeBacktest($candles);

        $this->assertSame([], $result['trades']);
        $this->assertContains('O último sinal não foi preenchido porque não existe candle N+1 no dataset.', $result['warnings']);
    }

    /** @param array<int, array<string, mixed>> $candles @param array<string, mixed> $overrides @return array<string, mixed> */
    private function executeBacktest(array $candles, array $overrides = []): array
    {
        return app(DeterministicBacktestEngine::class)->run(
            $this->version,
            $candles,
            array_merge($this->scenario(), $overrides),
            app(MarketCandleDatasetService::class)->hash($candles),
        );
    }

    /** @return array<string, mixed> */
    private function scenario(): array
    {
        return [
            'exchange_id' => $this->exchange->id,
            'symbol' => 'BTCUSDT',
            'timeframe' => '1h',
            'initial_capital' => '10000',
            'allocation_pct' => '100',
            'fee_rate' => '0.1',
            'slippage_rate' => '0.2',
            'close_open_position_at_end' => false,
            'evaluation_time' => '2026-01-02T00:00:00Z',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function candles(): array
    {
        $closes = ['100', '90', '80', '110', '120', '130'];
        $opens = ['100', '100', '89', '81', '111', '121'];

        return array_map(function (string $close, int $index) use ($opens): array {
            $hour = str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $nextHour = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            $open = $opens[$index];

            return [
                'exchange_id' => $this->exchange->id,
                'symbol' => 'BTCUSDT',
                'timeframe' => '1h',
                'open_time' => "2026-01-01T{$hour}:00:00Z",
                'close_time' => "2026-01-01T{$nextHour}:00:00Z",
                'open' => $open,
                'high' => (string) (max((int) $open, (int) $close) + 1),
                'low' => (string) (min((int) $open, (int) $close) - 1),
                'close' => $close,
                'volume' => '10',
                'source' => 'fixture',
                'is_closed' => true,
            ];
        }, $closes, array_keys($closes));
    }

    private function createVersion(): TradingStrategyVersion
    {
        $user = \App\Models\User::factory()->create();
        $strategy = app(StrategyVersionService::class)->createStrategy($user, 'Backtest determinístico', null, [
            'schema_version' => 1,
            'logic' => 'all',
            'entry_conditions' => [[
                'indicator' => 'sma',
                'parameters' => ['period' => 2],
                'operator' => 'less_than',
                'value' => 100,
            ]],
            'exit_conditions' => [[
                'indicator' => 'sma',
                'parameters' => ['period' => 2],
                'operator' => 'greater_than',
                'value' => 100,
            ]],
            'risk' => ['stop_loss_pct' => null, 'take_profit_pct' => null],
        ]);

        return $strategy->currentVersion;
    }
}
