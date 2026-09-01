<?php

namespace Tests\Feature;

use App\Contracts\MarketDataProviderInterface;
use App\Models\Exchange;
use App\Models\PaperTradingSession;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use App\Services\ManualPaperTradingService;
use App\Services\MarketCandleDatasetService;
use App\Services\StrategyVersionService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class ManualPaperTradingServiceTest extends TestCase
{
    use RefreshDatabase;

    private Exchange $exchange;
    private User $owner;
    private TradingStrategyVersion $version;
    private MarketCandleDatasetService $datasets;
    private object $provider;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-01-01T00:30:00Z');
        $this->provider = new class implements MarketDataProviderInterface {
            public int $calls = 0;

            public function fetchCandles(string $symbol, string $timeframe, CarbonImmutable $startAt, CarbonImmutable $endAt): array
            {
                $this->calls++;

                throw new \RuntimeException('O provider público não deveria ser chamado quando o cache de teste está completo.');
            }
        };
        $this->app->instance(MarketDataProviderInterface::class, $this->provider);
        $this->exchange = Exchange::query()->create([
            'name' => 'binance',
            'country_code' => 'MT',
            'description' => 'Binance pública',
        ]);
        $this->owner = User::factory()->create();
        $this->version = $this->createVersion($this->owner);
        $this->datasets = app(MarketCandleDatasetService::class);
        $this->datasets->persist($this->candles(8));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_manual_cycle_creates_only_simulated_fills_and_is_idempotent_when_no_new_candle_exists(): void
    {
        $session = $this->createSession();
        Carbon::setTestNow('2026-01-01T08:00:00Z');

        $first = app(ManualPaperTradingService::class)->runCycle($this->owner, $session, now('UTC')->toImmutable());
        $session->refresh();

        $this->assertSame('completed', $first->status);
        $this->assertSame(7, $first->candles_processed);
        $this->assertCount(2, $first->trades);
        $this->assertSame('entry', $first->trades[0]->event_type);
        $this->assertSame('exit', $first->trades[1]->event_type);
        $this->assertSame('2026-01-01T01:00:00+00:00', $first->trades[0]->signal_candle_open_time->toIso8601String());
        $this->assertSame('2026-01-01T02:00:00+00:00', $first->trades[0]->fill_candle_open_time->toIso8601String());
        $this->assertSame('next_candle_open', $first->trades[0]->fill_rule);
        $this->assertNotNull($session->last_evaluated_candle_open_time);
        $this->assertSame('2026-01-01T07:00:00+00:00', $session->last_evaluated_candle_open_time->toIso8601String());
        $this->assertTrue(app(\App\Support\DecimalMath::class)->isZero($session->position_quantity));
        $this->assertSame(0, $this->provider->calls);
        $this->assertDatabaseCount('paper_trading_trades', 2);
        $this->assertDatabaseCount('bot_orders', 0);
        $this->assertDatabaseCount('transactions', 0);

        $cashAfterFirstCycle = $session->cash_balance;
        $second = app(ManualPaperTradingService::class)->runCycle($this->owner, $session, now('UTC')->toImmutable());
        $session->refresh();

        $this->assertSame('completed', $second->status);
        $this->assertSame(0, $second->candles_processed, json_encode([
            'last_evaluated' => $session->last_evaluated_candle_open_time?->toIso8601String(),
            'first_processed_start' => $first->processed_start_candle_open_time?->toIso8601String(),
            'second_processed_start' => $second->processed_start_candle_open_time?->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
        $this->assertSame('hold', $second->decision);
        $this->assertDatabaseCount('paper_trading_trades', 2);
        $this->assertSame($cashAfterFirstCycle, $session->cash_balance);
        $this->assertSame(0, $this->provider->calls);
    }

    public function test_session_configuration_and_audit_records_are_immutable_and_owned_by_the_user(): void
    {
        $session = $this->createSession();

        $this->expectException(LogicException::class);
        $session->symbol = 'ETHUSDT';
        $session->save();
    }

    public function test_other_user_cannot_create_or_run_a_session_for_the_owners_strategy(): void
    {
        $other = User::factory()->create();
        $service = app(ManualPaperTradingService::class);

        $this->expectException(InvalidArgumentException::class);
        $service->createSession($other, $this->version, $this->exchange, $this->configuration());
    }

    public function test_pause_resume_and_archive_never_create_real_orders_or_transactions(): void
    {
        $service = app(ManualPaperTradingService::class);
        $session = $this->createSession();

        $this->assertSame(PaperTradingSession::STATUS_PAUSED, $service->pause($this->owner, $session)->status);
        $this->assertSame(PaperTradingSession::STATUS_ACTIVE, $service->resume($this->owner, $session)->status);
        $this->assertSame(PaperTradingSession::STATUS_ARCHIVED, $service->archive($this->owner, $session)->status);
        $this->assertDatabaseCount('bot_orders', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseHas('trading_logs', ['event_type' => 'paper_trading_session_archived']);
    }

    private function createSession(): PaperTradingSession
    {
        return app(ManualPaperTradingService::class)->createSession(
            $this->owner,
            $this->version,
            $this->exchange,
            $this->configuration(),
        );
    }

    /** @return array<string, mixed> */
    private function configuration(): array
    {
        return [
            'symbol' => 'BTCUSDT',
            'timeframe' => '1h',
            'initial_capital' => '10000',
            'allocation_pct' => '100',
            'fee_rate' => '0.1',
            'slippage_rate' => '0.2',
            'history_start_at' => '2026-01-01T00:00:00Z',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function candles(int $count): array
    {
        $closes = ['100', '90', '80', '110', '120', '130', '140', '150'];
        $opens = ['100', '100', '89', '81', '111', '121', '131', '141'];

        return array_map(function (string $close, int $index) use ($opens): array {
            $openTime = CarbonImmutable::parse('2026-01-01T00:00:00Z')->addHours($index);
            $open = $opens[$index];

            return [
                'exchange_id' => $this->exchange->id,
                'symbol' => 'BTCUSDT',
                'timeframe' => '1h',
                'open_time' => $openTime->toIso8601String(),
                'close_time' => $openTime->addHour()->toIso8601String(),
                'open' => $open,
                'high' => (string) (max((int) $open, (int) $close) + 1),
                'low' => (string) (min((int) $open, (int) $close) - 1),
                'close' => $close,
                'volume' => '10',
                'source' => 'fixture',
            ];
        }, array_slice($closes, 0, $count), array_keys(array_slice($closes, 0, $count)));
    }

    private function createVersion(User $user): TradingStrategyVersion
    {
        return app(StrategyVersionService::class)->createStrategy($user, 'Paper manual', null, [
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
        ])->currentVersion;
    }
}
