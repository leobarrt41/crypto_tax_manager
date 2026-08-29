<?php

namespace Tests\Feature;

use App\Models\Exchange;
use App\Models\TradingLog;
use App\Models\User;
use App\Models\UserApiKey;
use App\Services\ExchangeConnector;
use App\Services\TradingAuditLogger;
use App\Services\TradingExecutionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TradingSafetyFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_credentials_are_encrypted_and_trading_is_disabled_by_default(): void
    {
        $apiKey = $this->createApiKey();

        $this->assertNotSame('api-key-de-teste', $apiKey->getRawOriginal('api_key'));
        $this->assertNotSame('secret-key-de-teste', $apiKey->getRawOriginal('secret_key'));

        $persistedApiKey = $apiKey->fresh();
        $this->assertSame('api-key-de-teste', $persistedApiKey->api_key);
        $this->assertSame('secret-key-de-teste', $persistedApiKey->secret_key);
        $this->assertTrue($persistedApiKey->read_enabled);
        $this->assertFalse($persistedApiKey->trading_enabled);
    }

    public function test_order_submission_is_blocked_before_any_http_request_is_sent(): void
    {
        Http::fake();

        $result = app(ExchangeConnector::class)->placeOrder($this->createApiKey(), [
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'type' => 'MARKET',
            'quoteOrderQty' => 25,
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('bloqueado', $result['error']);
        Http::assertNothingSent();
    }

    public function test_operation_lock_prevents_concurrent_processing_of_the_same_operation(): void
    {
        $guard = app(TradingExecutionGuard::class);
        $lock = Cache::lock('trading:operation:operation-42', 120);
        $this->assertTrue($lock->get());

        try {
            $guard->runExclusively('operation-42', fn () => $this->fail('O callback não deveria ser executado.'));
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            $this->assertTrue(true);
        } finally {
            $lock->release();
        }
    }

    public function test_audit_payload_masks_sensitive_credential_fields(): void
    {
        $user = User::factory()->create();

        $log = app(TradingAuditLogger::class)->record(
            $user->id,
            'execution_blocked',
            'Tentativa de execução bloqueada.',
            'warning',
            payload: [
                'api_key' => 'api-key-de-teste',
                'secret_key' => 'secret-key-de-teste',
                'nested' => ['signature' => 'assinatura-sensivel'],
            ],
            source: 'test',
        );

        $this->assertDatabaseHas('trading_logs', [
            'id' => $log->id,
            'event_type' => 'execution_blocked',
            'severity' => 'warning',
            'source' => 'test',
        ]);

        $this->assertSame('[MASCARADO]', $log->payload['api_key']);
        $this->assertSame('[MASCARADO]', $log->payload['secret_key']);
        $this->assertSame('[MASCARADO]', $log->payload['nested']['signature']);
        $this->assertInstanceOf(TradingLog::class, $log);
    }

    private function createApiKey(): UserApiKey
    {
        $user = User::factory()->create();
        $exchange = Exchange::create([
            'name' => 'binance-' . uniqid(),
            'country_code' => 'MT',
            'description' => 'Exchange de teste',
        ]);

        return UserApiKey::create([
            'user_id' => $user->id,
            'exchange_id' => $exchange->id,
            'api_key' => 'api-key-de-teste',
            'secret_key' => 'secret-key-de-teste',
        ]);
    }
}
