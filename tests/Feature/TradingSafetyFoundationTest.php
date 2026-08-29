<?php

namespace Tests\Feature;

use App\Models\Exchange;
use App\Models\TradingLog;
use App\Models\User;
use App\Models\UserApiKey;
use App\Services\ExchangeConnector;
use App\Services\TradingAuditLogger;
use App\Services\TradingBotEngine;
use App\Services\TradingExecutionGuard;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class TradingSafetyFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_test_suite_uses_discardable_in_memory_sqlite_database(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('sqlite', DB::connection()->getDriverName());
    }

    public function test_api_credentials_are_encrypted_at_rest_and_permissions_default_to_safe_values(): void
    {
        $apiKey = $this->createApiKey();
        $stored = DB::table('user_api_keys')->where('id', $apiKey->id)->first();

        $this->assertNotSame('chave-ficticia-fase-zero', $stored->api_key);
        $this->assertNotSame('segredo-ficticio-fase-zero', $stored->secret_key);
        $this->assertStringNotContainsString('chave-ficticia-fase-zero', $stored->api_key);
        $this->assertStringNotContainsString('segredo-ficticio-fase-zero', $stored->secret_key);

        $persisted = UserApiKey::query()->findOrFail($apiKey->id);
        $this->assertSame('chave-ficticia-fase-zero', $persisted->api_key);
        $this->assertSame('segredo-ficticio-fase-zero', $persisted->secret_key);
        $this->assertTrue($persisted->read_enabled);
        $this->assertFalse($persisted->trading_enabled);
        $this->assertNull($persisted->trading_enabled_at);
    }

    public function test_real_order_execution_is_blocked_even_when_called_directly(): void
    {
        Http::fake();
        config()->set('trading.real_orders_enabled', false);
        $apiKey = $this->createApiKey();

        $result = app(ExchangeConnector::class)->placeOrder($apiKey, [
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'type' => 'MARKET',
            'quoteOrderQty' => 25,
            'signature' => 'assinatura-ficticia',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('bloqueado', $result['error']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('bot_orders', 0);

        $log = TradingLog::query()->where('event_type', 'real_order_blocked')->firstOrFail();
        $this->assertSame('warning', $log->severity);
        $this->assertSame('exchange_connector', $log->source);
        $this->assertSame('[MASCARADO]', $log->payload['order']['signature']);
        $rawPayload = (string) DB::table('trading_logs')->where('id', $log->id)->value('payload');
        $this->assertStringNotContainsString('assinatura-ficticia', $rawPayload);
    }

    public function test_real_order_stays_blocked_even_if_feature_flag_and_key_permission_are_enabled(): void
    {
        Http::fake();
        config()->set('trading.real_orders_enabled', true);
        $apiKey = $this->createApiKey(['trading_enabled' => true]);

        $result = app(ExchangeConnector::class)->placeOrder($apiKey, ['symbol' => 'BTCUSDT']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ainda não está implementado', $result['error']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('bot_orders', 0);
    }

    public function test_legacy_engine_cannot_start_loop_dispatch_job_or_submit_order(): void
    {
        Http::fake();
        Queue::fake();
        $connector = Mockery::mock(ExchangeConnector::class);
        $connector->shouldNotReceive('placeOrder');
        $connector->shouldNotReceive('getCurrentPrice');
        $connector->shouldNotReceive('getBalance');

        $result = (new TradingBotEngine($connector))->start();

        $this->assertFalse($result);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('bot_orders', 0);
    }

    public function test_strategy_lock_is_deterministic_and_scoped_by_user_and_strategy(): void
    {
        $guard = app(TradingExecutionGuard::class);
        $sameKey = $guard->operationKeyForStrategy(10, 20);

        $this->assertSame($sameKey, $guard->operationKeyForStrategy(10, 20));
        $this->assertNotSame($sameKey, $guard->operationKeyForStrategy(11, 20));
        $this->assertNotSame($sameKey, $guard->operationKeyForStrategy(10, 21));

        $heldLock = Cache::lock("trading:operation:{$sameKey}", 120);
        $this->assertTrue($heldLock->get());

        try {
            $guard->runForStrategy(10, 20, fn () => $this->fail('O callback concorrente não pode executar.'));
            $this->fail('Era esperado bloqueio por lock concorrente.');
        } catch (LockTimeoutException) {
            $this->assertTrue(true);
        } finally {
            $heldLock->release();
        }

        $this->assertSame('outro-usuario', $guard->runForStrategy(11, 20, fn () => 'outro-usuario'));
        $this->assertSame('outra-estrategia', $guard->runForStrategy(10, 21, fn () => 'outra-estrategia'));
    }

    public function test_audit_log_recursively_masks_sensitive_arrays_and_objects(): void
    {
        $user = User::factory()->create();
        $log = app(TradingAuditLogger::class)->record(
            $user->id,
            'execution_blocked',
            'Tentativa de execução bloqueada.',
            'warning',
            payload: [
                'api_key' => 'valor-ficticio',
                'nested' => (object) [
                    'secret' => 'segredo-ficticio',
                    'deeper' => ['signature' => 'assinatura-ficticia'],
                ],
                'symbol' => 'BTCUSDT',
            ],
            source: 'security_test',
        );

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('warning', $log->severity);
        $this->assertSame('security_test', $log->source);
        $this->assertSame('execution_blocked', $log->event_type);
        $this->assertNotNull($log->logged_at);
        $this->assertNotNull($log->occurred_at);
        $this->assertSame('[MASCARADO]', $log->payload['api_key']);
        $this->assertSame('[MASCARADO]', $log->payload['nested']['secret']);
        $this->assertSame('[MASCARADO]', $log->payload['nested']['deeper']['signature']);
        $this->assertSame('BTCUSDT', $log->payload['symbol']);

        $rawPayload = (string) DB::table('trading_logs')->where('id', $log->id)->value('payload');
        $this->assertStringNotContainsString('valor-ficticio', $rawPayload);
        $this->assertStringNotContainsString('segredo-ficticio', $rawPayload);
        $this->assertStringNotContainsString('assinatura-ficticia', $rawPayload);
    }

    public function test_execution_backtest_order_and_exchange_order_webhook_routes_are_not_active(): void
    {
        $forbidden = collect(Route::getRoutes())->filter(function ($route): bool {
            $uri = strtolower($route->uri());

            return str_contains($uri, 'trading-bot/start')
                || str_contains($uri, 'trading-bot/stop')
                || str_contains($uri, 'backtesting')
                || (str_contains($uri, 'trading-strategies') && (bool) preg_match('#/(start|stop|backtest)$#', $uri))
                || (str_contains($uri, 'bot-orders') && (bool) preg_match('#/(cancel|retry)$#', $uri))
                || str_contains($uri, 'orders/cancel')
                || str_contains($uri, 'order-update');
        });

        $this->assertSame([], $forbidden->map(fn ($route) => $route->uri())->values()->all());
    }

    public function test_security_migration_columns_exist_on_sqlite(): void
    {
        $this->assertTrue(Schema::hasColumns('user_api_keys', [
            'read_enabled', 'trading_enabled', 'trading_enabled_at',
        ]));
        $this->assertTrue(Schema::hasColumns('trading_logs', [
            'event_type', 'severity', 'payload', 'source', 'occurred_at',
        ]));
        $this->assertTrue(Schema::hasColumn('transactions', 'reference'));

        $transactionIndexes = collect(DB::select("PRAGMA index_list('transactions')"));
        $uniqueReferenceIndex = $transactionIndexes->firstWhere('name', 'transactions_user_reference_unique');

        $this->assertNotNull($uniqueReferenceIndex);
        $this->assertSame(1, (int) $uniqueReferenceIndex->unique);
    }

    /** @param array<string, mixed> $overrides */
    private function createApiKey(array $overrides = []): UserApiKey
    {
        $user = User::factory()->create();
        $exchange = Exchange::query()->create([
            'name' => 'exchange-ficticia-' . $user->id,
            'country_code' => 'ZZ',
            'description' => 'Exchange exclusiva da suíte de segurança',
        ]);

        return UserApiKey::query()->create(array_merge([
            'user_id' => $user->id,
            'exchange_id' => $exchange->id,
            'api_key' => 'chave-ficticia-fase-zero',
            'secret_key' => 'segredo-ficticio-fase-zero',
        ], $overrides));
    }
}
