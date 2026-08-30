<?php

namespace Database\Factories;

use App\Models\BacktestRun;
use App\Models\BacktestTrade;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BacktestTrade>
 */
class BacktestTradeFactory extends Factory
{
    protected $model = BacktestTrade::class;

    public function definition(): array
    {
        $signalTime = CarbonImmutable::parse('2026-01-01T01:00:00Z');

        return [
            'backtest_run_id' => BacktestRun::factory(),
            'event_type' => 'entry',
            'signal_candle_open_time' => $signalTime,
            'fill_candle_open_time' => $signalTime->addHour(),
            'side' => 'buy',
            'fill_price' => '100.0000000000000000',
            'quantity' => '99.9000999000999000',
            'gross_value' => '9990.0099900099900099',
            'fee_amount' => '9.9900099900099900',
            'fee_rate' => '0.10000000',
            'slippage_rate' => '0.00000000',
            'cash_before' => '10000.0000000000000000',
            'cash_after' => '0.0000000000000001',
            'realized_pnl' => null,
            'reason' => 'Fixture de operação simulada.',
            'condition_results' => [],
            'fill_rule' => 'next_candle_open',
        ];
    }
}
