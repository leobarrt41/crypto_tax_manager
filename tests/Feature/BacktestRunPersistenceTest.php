<?php

namespace Tests\Feature;

use App\Models\BacktestRun;
use App\Models\Exchange;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use App\Services\BacktestRunService;
use App\Services\StrategyVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class BacktestRunPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Exchange $exchange;
    private TradingStrategyVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->exchange = Exchange::query()->create([
            'name' => 'binance',
            'country_code' => 'MT',
            'description' => 'Binance',
        ]);
        $this->version = $this->createVersion($this->owner);
    }

    public function test_it_persists_a_terminal_auditable_run_and_separate_simulated_trades(): void
    {
        $run = $this->createRun();

        $this->assertSame(BacktestRun::STATUS_COMPLETED, $run->status);
        $this->assertSame($this->owner->id, $run->user_id);
        $this->assertSame($this->version->id, $run->trading_strategy_version_id);
        $this->assertSame($this->version->definition_hash, $run->strategy_definition_hash);
        $this->assertSame(4, $run->candles_count);
        $this->assertSame('historical_only', $run->source_metadata['execution_boundary'] ?? 'historical_only');
        $this->assertCount(1, $run->trades);
        $this->assertSame('entry', $run->trades->first()->event_type);
        $this->assertDatabaseHas('trading_logs', [
            'user_id' => $this->owner->id,
            'event_type' => 'backtest_completed',
            'source' => 'backtest',
        ]);
        $this->assertDatabaseCount('bot_orders', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_terminal_run_and_simulated_trades_cannot_be_silently_changed(): void
    {
        $run = $this->createRun();

        try {
            $run->update(['symbol' => 'ETHUSDT']);
            $this->fail('Backtest concluído deveria ser imutável.');
        } catch (LogicException) {
            $this->assertSame('BTCUSDT', $run->fresh()->symbol);
        }

        try {
            $run->trades->first()->update(['fill_price' => '1']);
            $this->fail('Operação simulada deveria ser imutável.');
        } catch (LogicException) {
            $this->assertNotSame('1.0000000000000000', $run->trades->first()->fresh()->fill_price);
        }
    }

    public function test_a_user_cannot_create_a_run_with_another_users_strategy_version(): void
    {
        $otherUser = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        app(BacktestRunService::class)->create(
            $otherUser,
            $this->version,
            $this->candles(),
            $this->scenario(),
            '2026-01-01T00:00:00Z',
            '2026-01-01T04:00:00Z',
        );
    }

    private function createRun(): BacktestRun
    {
        return app(BacktestRunService::class)->create(
            $this->owner,
            $this->version,
            $this->candles(),
            $this->scenario(),
            '2026-01-01T00:00:00Z',
            '2026-01-01T04:00:00Z',
            ['execution_boundary' => 'historical_only'],
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
            'slippage_rate' => '0.1',
            'close_open_position_at_end' => false,
            'evaluation_time' => '2026-01-02T00:00:00Z',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function candles(): array
    {
        return array_map(function (int $index): array {
            $hour = str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $nextHour = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            $close = 100 - ($index * 5);

            return [
                'exchange_id' => $this->exchange->id,
                'symbol' => 'BTCUSDT',
                'timeframe' => '1h',
                'open_time' => "2026-01-01T{$hour}:00:00Z",
                'close_time' => "2026-01-01T{$nextHour}:00:00Z",
                'open' => (string) (101 - ($index * 5)),
                'high' => (string) (102 - ($index * 5)),
                'low' => (string) (99 - ($index * 5)),
                'close' => (string) $close,
                'volume' => '10',
                'source' => 'fixture',
                'is_closed' => true,
            ];
        }, range(0, 3));
    }

    private function createVersion(User $user): TradingStrategyVersion
    {
        $strategy = app(StrategyVersionService::class)->createStrategy($user, 'Persistência do backtest', null, [
            'schema_version' => 1,
            'logic' => 'all',
            'entry_conditions' => [[
                'indicator' => 'sma',
                'parameters' => ['period' => 2],
                'operator' => 'less_than',
                'value' => 100,
            ]],
            'exit_conditions' => [],
            'risk' => ['stop_loss_pct' => null, 'take_profit_pct' => null],
        ]);

        return $strategy->currentVersion;
    }
}
