<?php

namespace Tests\Feature;

use App\Models\BotOrder;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\Transaction;
use App\Models\User;
use App\Services\IndicatorCalculator;
use App\Services\StrategyDefinitionValidator;
use App\Services\StrategySignalEvaluator;
use App\Services\StrategyVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class TradingStrategyPhaseOneTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_reusable_all_and_any_strategies_with_version_one_and_hash(): void
    {
        $user = User::factory()->create();

        foreach (['all', 'any'] as $logic) {
            $strategy = app(StrategyVersionService::class)->createStrategy(
                $user,
                "Estratégia {$logic}",
                'Somente definição reutilizável.',
                $this->definition($logic),
            );

            $this->assertSame(1, $strategy->currentVersion->version);
            $this->assertSame($logic, $strategy->currentVersion->definition['logic']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $strategy->currentVersion->definition_hash);
            $serialized = json_encode($strategy->currentVersion->definition, JSON_THROW_ON_ERROR);
            foreach (['symbol', 'pair', 'exchange', 'timeframe', 'side', 'mode'] as $forbidden) {
                $this->assertStringNotContainsString('"'.$forbidden.'"', $serialized);
            }
        }
    }

    public function test_rejects_invalid_logic_indicator_parameters_and_risk(): void
    {
        $invalidDefinitions = [];
        foreach ([1, 501] as $period) {
            $invalidDefinitions[] = $this->definition(condition: $this->condition('sma', ['period' => $period]));
        }
        foreach ([-1, 101] as $value) {
            $invalidDefinitions[] = $this->definition(condition: $this->condition('rsi', ['period' => 14], value: $value));
        }
        $invalidDefinitions[] = $this->definition('neither');
        $invalidDefinitions[] = $this->definition(condition: $this->condition('macd', ['fast_period' => 26, 'slow_period' => 12, 'signal_period' => 9]));
        $invalidDefinitions[] = $this->definition(condition: $this->condition('bollinger', ['period' => 20, 'std_dev' => 0]));
        $invalidDefinitions[] = $this->definition(condition: $this->condition('ma_cross', ['fast_period' => 20, 'slow_period' => 20], 'crosses_above', 0));
        $invalidDefinitions[] = $this->definition(condition: $this->condition('macd', ['fast_period' => 12, 'slow_period' => 26, 'signal_period' => 9, 'component' => 'histogram']));
        $invalidDefinitions[] = array_replace_recursive($this->definition(), ['risk' => ['trailing_stop_pct' => 2]]);

        foreach ($invalidDefinitions as $definition) {
            $this->assertInvalid($definition);
        }
    }

    public function test_rejects_every_operational_key_at_root_and_nested_levels(): void
    {
        $keys = [
            'symbol', 'pair', 'exchange', 'timeframe', 'side', 'mode', 'execution',
            'order_type', 'quantity', 'quote_amount', 'leverage', 'real_execution_enabled',
        ];

        foreach ($keys as $key) {
            $root = $this->definition();
            $root[$key] = 'proibido';
            $this->assertInvalid($root);

            $nested = $this->definition();
            $nested['entry_conditions'][0]['parameters']['nested'][$key] = 'proibido';
            $this->assertInvalid($nested);
        }
    }

    public function test_hash_is_canonical_for_object_keys_and_preserves_condition_order(): void
    {
        $user = User::factory()->create();
        $first = $this->definition(condition: $this->condition('sma', ['period' => 3], 'greater_than', 2));
        $same = [
            'risk' => ['take_profit_pct' => null, 'stop_loss_pct' => null],
            'exit_conditions' => [],
            'entry_conditions' => [[
                'value' => 2,
                'operator' => 'greater_than',
                'parameters' => ['period' => 3],
                'indicator' => 'sma',
            ]],
            'logic' => 'all',
            'schema_version' => 1,
        ];

        $hashA = app(StrategyVersionService::class)->createStrategy($user, 'A', null, $first)->currentVersion->definition_hash;
        $hashB = app(StrategyVersionService::class)->createStrategy($user, 'B', null, $same)->currentVersion->definition_hash;
        $this->assertSame($hashA, $hashB);

        $reordered = $first;
        $reordered['entry_conditions'] = [
            $this->condition('ema', ['period' => 2], 'greater_than', 1),
            $first['entry_conditions'][0],
        ];
        $opposite = $first;
        $opposite['entry_conditions'] = array_reverse($reordered['entry_conditions']);
        $hashC = app(StrategyVersionService::class)->createStrategy($user, 'C', null, $reordered)->currentVersion->definition_hash;
        $hashD = app(StrategyVersionService::class)->createStrategy($user, 'D', null, $opposite)->currentVersion->definition_hash;
        $this->assertNotSame($hashC, $hashD);
    }

    public function test_versions_are_immutable_and_archived_strategy_cannot_be_edited(): void
    {
        $user = User::factory()->create();
        $service = app(StrategyVersionService::class);
        $strategy = $service->createStrategy($user, 'Original', null, $this->definition());
        $versionOne = $strategy->currentVersion->replicate();

        $versionTwo = $service->createNewVersion(
            $strategy,
            $user,
            'Atualizada',
            null,
            $this->definition(condition: $this->condition('ema', ['period' => 3], 'greater_than', 2)),
        );

        $this->assertSame(2, $versionTwo->version);
        $this->assertSame($versionTwo->id, $strategy->fresh()->current_version_id);
        $persistedOne = TradingStrategyVersion::query()->where('trading_strategy_id', $strategy->id)->where('version', 1)->firstOrFail();
        $this->assertSame($versionOne->definition, $persistedOne->definition);
        $this->assertSame($versionOne->definition_hash, $persistedOne->definition_hash);

        $currentBeforeInvalidUpdate = $strategy->fresh()->current_version_id;
        try {
            $service->createNewVersion($strategy, $user, 'Inválida', null, $this->definition('invalid'));
            $this->fail('A definição inválida deveria ser rejeitada.');
        } catch (ValidationException) {
            $this->assertSame($currentBeforeInvalidUpdate, $strategy->fresh()->current_version_id);
        }

        $service->archive($strategy->fresh(), $user);
        $this->actingAs($user)->get(route('trading-bot.strategies.show', $strategy))->assertOk();
        $this->assertSame(2, $strategy->versions()->count());
        $this->assertEqualsCanonicalizing(
            ['strategy_created', 'strategy_version_created', 'strategy_archived'],
            DB::table('trading_logs')->where('trading_strategy_id', $strategy->id)->pluck('event_type')->all(),
        );
        $this->expectException(LogicException::class);
        $service->createNewVersion($strategy->fresh(), $user, 'Bloqueada', null, $this->definition());
    }

    public function test_guest_is_redirected_and_owner_can_access_protected_strategy_pages(): void
    {
        // Neste cenário, o CSRF é removido somente para comprovar que o guest é bloqueado por auth.
        // A proteção CSRF real é validada separadamente no teste seguinte.
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $owner = User::factory()->create();
        $strategy = app(StrategyVersionService::class)->createStrategy($owner, 'Protegida', null, $this->definition());

        $this->get(route('trading-bot.index'))->assertRedirect(route('login'));
        $this->get(route('trading-bot.strategies.create'))->assertRedirect(route('login'));
        $this->post(route('trading-bot.strategies.store'), [
            'name' => 'Tentativa visitante',
            'definition' => $this->definition(),
        ])->assertRedirect(route('login'));

        $this->actingAs($owner)->get(route('trading-bot.index'))->assertOk();
        $this->actingAs($owner)->get(route('trading-bot.strategies.show', $strategy))->assertOk();
    }

    public function test_trading_bot_overview_is_distinct_from_strategy_listing_and_uses_local_summary(): void
    {
        $user = User::factory()->create();
        app(StrategyVersionService::class)->createStrategy($user, 'Resumo local', null, $this->definition());

        $overviewResponse = $this->actingAs($user)->get(route('trading-bot.index'))
            ->assertOk();
        $overviewPage = $this->inertiaPage((string) $overviewResponse->getContent());

        $this->assertSame('TradingBot/Overview', $overviewPage['component']);
        $this->assertSame(1, $overviewPage['props']['summary']['strategies_count']);
        $this->assertSame(1, $overviewPage['props']['summary']['versions_count']);
        $this->assertFalse($overviewPage['props']['executionEnabled']);

        $strategiesResponse = $this->actingAs($user)->get(route('trading-bot.strategies.index'))
            ->assertOk();
        $strategiesPage = $this->inertiaPage((string) $strategiesResponse->getContent());

        $this->assertSame('TradingBot/Strategies/Index', $strategiesPage['component']);
        $this->assertNotSame($overviewPage['component'], $strategiesPage['component']);
    }

    public function test_production_strategy_mutations_require_csrf_for_an_authenticated_owner(): void
    {
        // withMiddleware() remove apenas o desligamento global. O middleware CSRF
        // padrão do Laravel também ignora requisições durante unit tests; esta instância
        // local ao teste preserva a pilha web e força a verificação do token ausente.
        $this->withMiddleware();
        $this->app->bind(\App\Http\Middleware\VerifyCsrfToken::class, function ($app) {
            return new class($app, $app->make(\Illuminate\Contracts\Encryption\Encrypter::class)) extends \App\Http\Middleware\VerifyCsrfToken {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            };
        });

        $owner = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $strategy = app(StrategyVersionService::class)->createStrategy($owner, 'CSRF', null, $this->definition());
        $currentVersionId = $strategy->current_version_id;

        $this->actingAs($owner)->patch(route('trading-bot.strategies.update', $strategy), [
            'name' => 'Sem token CSRF',
            'definition' => $this->definition(),
        ])->assertStatus(419);

        $this->assertSame('CSRF', $strategy->fresh()->name);
        $this->assertSame($currentVersionId, $strategy->fresh()->current_version_id);
    }

    public function test_other_user_cannot_view_edit_validate_preview_update_or_archive_strategy(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $strategy = app(StrategyVersionService::class)->createStrategy($owner, 'Privada', null, $this->definition());

        $this->actingAs($intruder)->get(route('trading-bot.strategies.show', $strategy))->assertForbidden();
        $this->actingAs($intruder)->get(route('trading-bot.strategies.edit', $strategy))->assertForbidden();
        $this->actingAs($intruder)->patch(route('trading-bot.strategies.update', $strategy), [
            'name' => 'Tentativa', 'definition' => $this->definition(),
        ])->assertForbidden();
        $this->actingAs($intruder)->post(route('trading-bot.strategies.validate-owned', $strategy), [
            'definition' => $this->definition(),
        ])->assertForbidden();
        $this->actingAs($intruder)->post(route('trading-bot.strategies.preview', $strategy), [
            'candles' => $this->candles([1, 2, 3]),
        ])->assertForbidden();
        $this->actingAs($intruder)->post(route('trading-bot.strategies.archive', $strategy))->assertForbidden();
        $this->assertNull($strategy->fresh()->archived_at);
        $this->assertSame(1, $strategy->versions()->count());
    }

    public function test_indicators_return_known_values_from_closed_synthetic_candles(): void
    {
        $calculator = app(IndicatorCalculator::class);
        $candles = $this->candles([1, 2, 3, 4, 5]);

        $this->assertEqualsWithDelta(4.0, $calculator->sma($candles, 3)[4], 0.000001);
        $this->assertEqualsWithDelta(4.0, $calculator->ema($candles, 3)[4], 0.000001);
        $this->assertEqualsWithDelta(100.0, $calculator->rsi($candles, 2)[4], 0.000001);
        $macd = $calculator->macd($candles, 2, 3, 2);
        $this->assertEqualsWithDelta(0.5, $macd['line'][4], 0.000001);
        $this->assertEqualsWithDelta(0.5, $macd['signal'][4], 0.000001);
        $bands = $calculator->bollinger($candles, 3, 2);
        $this->assertEqualsWithDelta(5.632993, $bands['upper'][4], 0.000001);
        $this->assertEqualsWithDelta(2.367007, $bands['lower'][4], 0.000001);

        $withOpenCandle = [...$candles, ['close' => 999, 'close_time' => '2026-08-30T00:06:00+00:00', 'is_closed' => false]];
        $this->assertCount(5, $calculator->sma($withOpenCandle, 3));
        $this->assertEqualsWithDelta(4.0, $calculator->sma($withOpenCandle, 3)[4], 0.000001);
    }

    public function test_evaluator_returns_entry_exit_hold_all_any_and_structured_reasons(): void
    {
        $entry = $this->evaluate($this->definition(condition: $this->condition('sma', ['period' => 2], 'greater_than', 2)), [1, 2, 3]);
        $this->assertSame('entry', $entry['decision']);
        $this->assertTrue($entry['condition_results']['entry'][0]['result']);
        $this->assertNotEmpty($entry['condition_results']['entry'][0]['reason']);

        $exitDefinition = $this->definition(condition: $this->condition('sma', ['period' => 2], 'less_than', 0));
        $exitDefinition['exit_conditions'] = [$this->condition('ema', ['period' => 2], 'greater_than', 2)];
        $exit = $this->evaluate($exitDefinition, [1, 2, 3]);
        $this->assertSame('exit', $exit['decision']);

        $hold = $this->evaluate($this->definition(condition: $this->condition('sma', ['period' => 2], 'less_than', 0)), [1, 2, 3]);
        $this->assertSame('hold', $hold['decision']);

        $any = $this->definition('any', $this->condition('sma', ['period' => 2], 'less_than', 0));
        $any['entry_conditions'][] = $this->condition('ema', ['period' => 2], 'greater_than', 2);
        $this->assertSame('entry', $this->evaluate($any, [1, 2, 3])['decision']);

        $all = $any;
        $all['logic'] = 'all';
        $this->assertSame('hold', $this->evaluate($all, [1, 2, 3])['decision']);
        $this->assertSame('2026-08-30T00:03:00+00:00', $entry['candle_close_time']);
        $this->assertSame(1, $entry['strategy_version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $entry['definition_hash']);
    }

    public function test_ma_cross_detects_bullish_cross_and_its_absence(): void
    {
        $definition = $this->definition(condition: $this->condition(
            'ma_cross',
            ['fast_period' => 2, 'slow_period' => 3],
            'crosses_above',
            0,
        ));

        $this->assertSame('entry', $this->evaluate($definition, [3, 2, 1, 4])['decision']);
        $this->assertSame('hold', $this->evaluate($definition, [1, 2, 3, 4])['decision']);
    }

    public function test_insufficient_data_and_preview_have_no_operational_side_effects_or_http(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        Http::fake();
        Queue::fake();
        $user = User::factory()->create();
        $strategy = app(StrategyVersionService::class)->createStrategy(
            $user,
            'Prévia segura',
            null,
            $this->definition(condition: $this->condition('sma', ['period' => 20], 'greater_than', 1)),
        );
        $ordersBefore = BotOrder::query()->count();
        $transactionsBefore = Transaction::query()->count();

        $response = $this->actingAs($user)->postJson(route('trading-bot.strategies.preview', $strategy), [
            'candles' => $this->candles([1, 2, 3]),
            'token' => 'nao-deve-ser-persistido',
        ]);

        $response->assertOk()->assertJsonPath('decision', 'hold')->assertJsonPath('data_status', 'insufficient_data');
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame($ordersBefore, BotOrder::query()->count());
        $this->assertSame($transactionsBefore, Transaction::query()->count());
        $this->assertStringNotContainsString(
            'nao-deve-ser-persistido',
            (string) DB::table('trading_logs')->latest('id')->value('payload'),
        );
    }

    public function test_only_safe_non_execution_trading_routes_are_active(): void
    {
        $allowedBacktestUris = [
            'trading-bot/backtests',
            'trading-bot/backtests/create',
            'trading-bot/backtests/{backtest}',
        ];
        $forbidden = collect(Route::getRoutes())->filter(function ($route) use ($allowedBacktestUris): bool {
            $uri = strtolower($route->uri());

            return str_contains($uri, 'trading-bot/start')
                || str_contains($uri, 'trading-bot/stop')
                || (str_contains($uri, 'backtest') && ! in_array($uri, $allowedBacktestUris, true))
                || str_contains($uri, 'order-update')
                || (str_contains($uri, 'order') && (bool) preg_match('#/(create|store|cancel|retry|update)$#', $uri));
        });

        $this->assertSame([], $forbidden->map(fn ($route) => $route->uri())->values()->all());
    }

    /** @return array<string, mixed> */
    private function inertiaPage(string $content): array
    {
        preg_match('/data-page="([^"]+)"/', $content, $matches);
        $this->assertArrayHasKey(1, $matches, 'A resposta Inertia deve conter o atributo data-page.');

        return json_decode(htmlspecialchars_decode($matches[1]), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function definition(string $logic = 'all', ?array $condition = null): array
    {
        return [
            'schema_version' => 1,
            'logic' => $logic,
            'entry_conditions' => [$condition ?? $this->condition('rsi', ['period' => 2], 'less_than', 70)],
            'exit_conditions' => [],
            'risk' => ['stop_loss_pct' => null, 'take_profit_pct' => null],
        ];
    }

    /** @return array<string, mixed> */
    private function condition(string $indicator, array $parameters, string $operator = 'greater_than', float|int $value = 50): array
    {
        return compact('indicator', 'parameters', 'operator', 'value');
    }

    /** @param array<int, int|float> $closes @return array<int, array<string, mixed>> */
    private function candles(array $closes): array
    {
        return array_map(fn ($close, $index) => [
            'close' => $close,
            'close_time' => sprintf('2026-08-30T00:%02d:00+00:00', $index + 1),
            'is_closed' => true,
        ], $closes, array_keys($closes));
    }

    /** @param array<string, mixed> $definition */
    private function assertInvalid(array $definition): void
    {
        try {
            app(StrategyDefinitionValidator::class)->validate($definition);
            $this->fail('A definição inválida deveria ter sido rejeitada.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    /** @param array<int, int|float> $closes @return array<string, mixed> */
    private function evaluate(array $definition, array $closes): array
    {
        $user = User::factory()->create();
        $strategy = app(StrategyVersionService::class)->createStrategy($user, uniqid('strategy-', true), null, $definition);

        return app(StrategySignalEvaluator::class)->evaluate($strategy->currentVersion, $this->candles($closes));
    }
}
