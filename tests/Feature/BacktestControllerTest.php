<?php

namespace Tests\Feature;

use App\Contracts\MarketDataProviderInterface;
use App\Models\BacktestRun;
use App\Models\Exchange;
use App\Models\User;
use App\Services\MarketCandleDatasetService;
use App\Services\StrategyVersionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BacktestControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Exchange $exchange;
    private \App\Models\TradingStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['email_verified_at' => now()]);
        $this->exchange = Exchange::query()->create([
            'name' => 'binance',
            'country_code' => 'MT',
            'description' => 'Binance',
        ]);
        $this->strategy = app(StrategyVersionService::class)->createStrategy($this->owner, 'Estratégia web', null, $this->definition());
        app(MarketCandleDatasetService::class)->persist($this->candles());
        $this->app->instance(MarketDataProviderInterface::class, new class implements MarketDataProviderInterface {
            public function fetchCandles(string $symbol, string $timeframe, CarbonImmutable $startAt, CarbonImmutable $endAt): array
            {
                throw new RuntimeException('A fonte externa não deve ser chamada pelos testes de controller.');
            }
        });
    }

    public function test_guest_is_redirected_and_verified_owner_can_list_and_create_backtests(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->get(route('trading-bot.backtests.index'))->assertRedirect();

        $index = $this->actingAs($this->owner)->get(route('trading-bot.backtests.index'))->assertOk();
        $indexPage = $this->inertiaPage((string) $index->getContent());
        $this->assertSame('TradingBot/Backtests/Index', $indexPage['component']);

        $create = $this->actingAs($this->owner)->get(route('trading-bot.backtests.create'))->assertOk();
        $createPage = $this->inertiaPage((string) $create->getContent());
        $this->assertSame('TradingBot/Backtests/Create', $createPage['component']);
        $this->assertSame(['BTCUSDT', 'ETHUSDT'], $createPage['props']['markets'][0]['symbols']);
    }

    public function test_owner_can_create_backtest_from_cache_without_external_request(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $response = $this->actingAs($this->owner)->post(route('trading-bot.backtests.store'), $this->payload());
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $run = BacktestRun::query()->sole();
        $response->assertRedirect(route('trading-bot.backtests.show', $run));
        $this->assertSame($this->owner->id, $run->user_id);
        $this->assertSame($this->strategy->currentVersion->id, $run->trading_strategy_version_id);
        $this->assertSame('completed', $run->status);
        $this->assertDatabaseCount('backtest_trades', 1);
        $this->assertDatabaseCount('bot_orders', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_other_user_cannot_view_another_users_backtest(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->actingAs($this->owner)->post(route('trading-bot.backtests.store'), $this->payload());
        $run = BacktestRun::query()->sole();
        $other = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($other)->get(route('trading-bot.backtests.show', $run))->assertForbidden();
    }

    public function test_owner_can_delete_backtest_with_trades_and_an_audit_record_is_preserved(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->actingAs($this->owner)->post(route('trading-bot.backtests.store'), $this->payload());
        $run = BacktestRun::query()->sole();

        $response = $this->actingAs($this->owner)->delete(route('trading-bot.backtests.destroy', $run));

        $response->assertRedirect(route('trading-bot.backtests.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('backtest_runs', ['id' => $run->id]);
        $this->assertDatabaseCount('backtest_trades', 0);
        $this->assertDatabaseHas('trading_logs', [
            'user_id' => $this->owner->id,
            'event_type' => 'backtest_deleted',
            'source' => 'backtest',
        ]);
    }

    public function test_user_cannot_delete_another_users_backtest(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->actingAs($this->owner)->post(route('trading-bot.backtests.store'), $this->payload());
        $run = BacktestRun::query()->sole();
        $other = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($other)->delete(route('trading-bot.backtests.destroy', $run))->assertForbidden();
        $this->assertDatabaseHas('backtest_runs', ['id' => $run->id]);
    }

    public function test_backtest_creation_requires_csrf_for_verified_user(): void
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

        $this->actingAs($this->owner)->post(route('trading-bot.backtests.store'), $this->payload())->assertStatus(419);
        $this->assertDatabaseCount('backtest_runs', 0);
    }

    public function test_backtest_rejects_an_interval_even_slightly_longer_than_180_days(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $payload = $this->payload();
        $payload['start_at'] = '2025-01-01T00:00:00Z';
        $payload['end_at'] = '2025-06-30T00:01:00Z';

        $response = $this->actingAs($this->owner)
            ->from(route('trading-bot.backtests.create'))
            ->post(route('trading-bot.backtests.store'), $payload);

        $response->assertRedirect(route('trading-bot.backtests.create'));
        $response->assertSessionHasErrors([
            'end_at' => 'O intervalo não pode ser maior que 180 dias.',
        ]);
        $this->assertDatabaseCount('backtest_runs', 0);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'strategy_version_id' => $this->strategy->currentVersion->id,
            'exchange_id' => $this->exchange->id,
            'symbol' => 'BTCUSDT',
            'timeframe' => '1h',
            'start_at' => '2026-01-01T00:00:00Z',
            'end_at' => '2026-01-01T04:00:00Z',
            'initial_capital' => '10000',
            'allocation_pct' => '100',
            'fee_rate' => '0.1',
            'slippage_rate' => '0.1',
            'close_open_position_at_end' => false,
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
                'fetched_at' => '2026-01-02T00:00:00Z',
            ];
        }, range(0, 3));
    }

    /** @return array<string, mixed> */
    private function definition(): array
    {
        return [
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
        ];
    }

    /** @return array<string, mixed> */
    private function inertiaPage(string $content): array
    {
        preg_match('/data-page="([^"]+)"/', $content, $matches);
        $this->assertArrayHasKey(1, $matches);

        return json_decode(htmlspecialchars_decode($matches[1]), true, 512, JSON_THROW_ON_ERROR);
    }
}
