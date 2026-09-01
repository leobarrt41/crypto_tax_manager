<?php

namespace Tests\Feature;

use App\Contracts\MarketDataProviderInterface;
use App\Models\Exchange;
use App\Models\PaperTradingSession;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use App\Services\MarketCandleDatasetService;
use App\Services\StrategyVersionService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaperTradingControllerTest extends TestCase
{
    use RefreshDatabase;

    private Exchange $exchange;
    private User $owner;
    private TradingStrategyVersion $version;
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

                return [];
            }
        };
        $this->app->instance(MarketDataProviderInterface::class, $this->provider);
        $this->exchange = Exchange::query()->create(['name' => 'binance', 'country_code' => 'MT', 'description' => 'Binance']);
        $this->owner = User::factory()->create(['email_verified_at' => now()]);
        $this->version = $this->createVersion($this->owner);
        app(MarketCandleDatasetService::class)->persist($this->candles());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_guest_is_redirected_and_verified_owner_can_open_paper_trading_pages(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->get(route('trading-bot.paper-trading.index'))->assertRedirect(route('login'));
        $index = $this->actingAs($this->owner)->get(route('trading-bot.paper-trading.index'))->assertOk();
        $create = $this->actingAs($this->owner)->get(route('trading-bot.paper-trading.create'))->assertOk();

        $this->assertSame('TradingBot/PaperTrading/Index', $this->inertiaPage((string) $index->getContent())['component']);
        $this->assertSame('TradingBot/PaperTrading/Create', $this->inertiaPage((string) $create->getContent())['component']);
    }

    public function test_owner_can_create_and_manually_run_a_simulated_session_without_authenticated_exchange_or_real_order(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->actingAs($this->owner)->post(route('trading-bot.paper-trading.store'), $this->payload())
            ->assertRedirect();
        $session = PaperTradingSession::query()->firstOrFail();

        Carbon::setTestNow('2026-01-01T08:00:00Z');
        $this->actingAs($this->owner)->post(route('trading-bot.paper-trading.run', $session))
            ->assertRedirect(route('trading-bot.paper-trading.show', $session));

        $this->assertDatabaseCount('paper_trading_cycles', 1);
        $this->assertDatabaseCount('paper_trading_trades', 2);
        $this->assertDatabaseCount('bot_orders', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(1, $this->provider->calls, 'A fonte pública falsa pode ser consultada para o aquecimento; nenhum HTTP real é permitido na suíte.');
        $this->assertDatabaseHas('trading_logs', ['event_type' => 'paper_trading_cycle_completed']);
    }

    public function test_other_user_cannot_view_or_control_the_owners_paper_session(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->actingAs($this->owner)->post(route('trading-bot.paper-trading.store'), $this->payload());
        $session = PaperTradingSession::query()->firstOrFail();
        $other = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($other)->get(route('trading-bot.paper-trading.show', $session))->assertForbidden();
        $this->actingAs($other)->post(route('trading-bot.paper-trading.run', $session))->assertForbidden();
        $this->actingAs($other)->post(route('trading-bot.paper-trading.pause', $session))->assertForbidden();
        $this->assertSame(PaperTradingSession::STATUS_ACTIVE, $session->fresh()->status);
        $this->assertDatabaseCount('paper_trading_cycles', 0);
    }

    public function test_paper_trading_session_creation_requires_csrf_for_a_verified_user(): void
    {
        $this->withMiddleware();
        $this->app->bind(\App\Http\Middleware\VerifyCsrfToken::class, function ($app) {
            return new class($app, $app->make(\Illuminate\Contracts\Encryption\Encrypter::class)) extends \App\Http\Middleware\VerifyCsrfToken {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            };
        });

        $this->actingAs($this->owner)->post(route('trading-bot.paper-trading.store'), $this->payload())
            ->assertStatus(419);
        $this->assertDatabaseCount('paper_trading_sessions', 0);
    }

    /** @return array<string, mixed> */
    private function inertiaPage(string $content): array
    {
        preg_match('/data-page="([^"]+)"/', $content, $matches);
        $this->assertArrayHasKey(1, $matches, 'A resposta Inertia deve conter o atributo data-page.');

        return json_decode(htmlspecialchars_decode($matches[1]), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'strategy_version_id' => $this->version->id,
            'exchange_id' => $this->exchange->id,
            'symbol' => 'BTCUSDT',
            'timeframe' => '1h',
            'initial_capital' => '10000',
            'allocation_pct' => '100',
            'fee_rate' => '0.1',
            'slippage_rate' => '0.2',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function candles(): array
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
        }, $closes, array_keys($closes));
    }

    private function createVersion(User $user): TradingStrategyVersion
    {
        return app(StrategyVersionService::class)->createStrategy($user, 'Paper HTTP', null, [
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
