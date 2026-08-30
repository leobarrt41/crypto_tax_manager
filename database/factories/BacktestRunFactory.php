<?php

namespace Database\Factories;

use App\Models\BacktestRun;
use App\Models\Exchange;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BacktestRun>
 */
class BacktestRunFactory extends Factory
{
    protected $model = BacktestRun::class;

    public function definition(): array
    {
        $user = User::factory();
        $strategy = TradingStrategy::factory();
        $version = TradingStrategyVersion::factory();
        $exchangeId = Exchange::query()->firstOrCreate(
            ['name' => 'binance'],
            ['country_code' => 'MT', 'description' => 'Binance'],
        )->id;
        $start = CarbonImmutable::parse('2026-01-01T00:00:00Z');

        return [
            'user_id' => $user,
            'trading_strategy_id' => $strategy,
            'trading_strategy_version_id' => $version,
            'strategy_version_number' => 1,
            'strategy_definition_hash' => str_repeat('a', 64),
            'exchange_id' => $exchangeId,
            'symbol' => 'BTCUSDT',
            'timeframe' => '1h',
            'started_at' => $start,
            'finished_at' => $start->addMinute(),
            'requested_start_at' => $start,
            'requested_end_at' => $start->addHours(6),
            'dataset_start_at' => $start,
            'dataset_end_at' => $start->addHours(6),
            'dataset_hash' => str_repeat('b', 64),
            'candles_count' => 6,
            'source_metadata' => ['source' => 'fixture'],
            'simulation_config' => ['position_mode' => 'long_only_spot'],
            'status' => BacktestRun::STATUS_COMPLETED,
            'metrics' => ['initial_capital' => '10000.0000000000000000'],
            'warnings' => [],
        ];
    }
}
