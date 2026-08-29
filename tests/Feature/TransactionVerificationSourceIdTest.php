<?php

namespace Tests\Feature;

use App\Models\CryptoAssetPrice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CryptoPriceService;
use App\Services\TransactionVerificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class TransactionVerificationSourceIdTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['transactions', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type');
            $table->string('from_asset')->nullable();
            $table->decimal('from_amount', 30, 12)->nullable();
            $table->string('to_asset')->nullable();
            $table->decimal('to_amount', 30, 12)->nullable();
            $table->decimal('price', 30, 12)->nullable();
            $table->decimal('total_usdt', 30, 12)->nullable();
            $table->decimal('total_brl', 30, 12)->nullable();
            $table->string('pricing_status', 30)->nullable();
            $table->unsignedInteger('pricing_attempts')->default(0);
            $table->timestamp('pricing_last_attempted_at')->nullable();
            $table->text('pricing_failure_reason')->nullable();
            $table->string('reference')->nullable();
            $table->dateTime('date');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        foreach (['transactions', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Mockery::close();
        parent::tearDown();
    }

    public function test_verification_filters_binance_records_by_api_key_source_id(): void
    {
        $user = User::query()->create([
            'name' => 'Pessoa de Teste',
            'email' => 'verification-source@example.test',
            'email_verified_at' => now(),
            'password' => 'secret',
        ]);

        $target = Transaction::query()->create([
            'user_id' => $user->id,
            'type' => 'convert',
            'from_asset' => 'ALLO',
            'from_amount' => 10,
            'to_asset' => 'USDT',
            'to_amount' => 2,
            'total_usdt' => 0,
            'total_brl' => 0,
            'date' => '2026-08-22 20:09:00',
            'source_type' => 'App\\Models\\UserApiKey',
            'source_id' => 22,
        ]);
        $untargeted = Transaction::query()->create([
            'user_id' => $user->id,
            'type' => 'convert',
            'from_asset' => 'RIF',
            'from_amount' => 10,
            'to_asset' => 'USDT',
            'to_amount' => 3,
            'total_usdt' => 0,
            'total_brl' => 0,
            'date' => '2026-08-22 20:09:00',
            'source_type' => 'App\\Models\\UserApiKey',
            'source_id' => 11,
        ]);
        $sameSourceDifferentYear = Transaction::query()->create([
            'user_id' => $user->id,
            'type' => 'convert',
            'from_asset' => 'RIF',
            'from_amount' => 10,
            'to_asset' => 'USDT',
            'to_amount' => 4,
            'total_usdt' => 0,
            'total_brl' => 0,
            'date' => '2025-08-22 20:09:00',
            'source_type' => 'App\\Models\\UserApiKey',
            'source_id' => 22,
        ]);

        $price = new CryptoAssetPrice();
        $price->price_usd = 1;
        $price->price_brl = 5;

        $priceService = Mockery::mock(CryptoPriceService::class);
        $priceService->shouldReceive('getOrCreatePrice')->andReturn($price);
        app()->instance(CryptoPriceService::class, $priceService);

        $result = app(TransactionVerificationService::class)
            ->verifyAndUpdateZeroValueTransactions($user, 22, 2026);

        $this->assertSame(1, $result['total_checked']);
        $this->assertSame(1, $result['total_updated']);
        $this->assertSame('2.0000000000', $target->fresh()->total_usdt);
        $this->assertSame('10.0000000000', $target->fresh()->total_brl);
        $this->assertSame('completed', $target->fresh()->pricing_status);
        $this->assertSame('0.0000000000', $untargeted->fresh()->total_brl);
        $this->assertSame('0.0000000000', $sameSourceDifferentYear->fresh()->total_brl);
    }

    public function test_conversion_received_in_brl_uses_the_received_value_and_ptax(): void
    {
        $user = User::query()->create([
            'name' => 'Pessoa de Teste',
            'email' => 'verification-brl@example.test',
            'email_verified_at' => now(),
            'password' => 'secret',
        ]);

        $transaction = Transaction::query()->create([
            'user_id' => $user->id,
            'type' => 'convert',
            'from_asset' => 'BANANAS31',
            'from_amount' => 8652.22555487,
            'to_asset' => 'BRL',
            'to_amount' => 414.62595857,
            'total_usdt' => 0,
            'total_brl' => 0,
            'date' => '2026-04-10 05:37:00',
            'source_type' => 'App\\Models\\UserApiKey',
            'source_id' => 22,
        ]);

        $price = new CryptoAssetPrice();
        $price->price_usd = 1;
        $price->price_brl = 5;

        $priceService = Mockery::mock(CryptoPriceService::class);
        $priceService->shouldReceive('getOrCreatePrice')->once()->andReturn($price);
        app()->instance(CryptoPriceService::class, $priceService);

        $result = app(TransactionVerificationService::class)
            ->verifyAndUpdateZeroValueTransactions($user, 22);

        $this->assertSame(1, $result['total_updated']);
        $this->assertSame('414.6259585700', $transaction->fresh()->total_brl);
        $this->assertSame('82.9251917140', $transaction->fresh()->total_usdt);
        $this->assertSame('completed', $transaction->fresh()->pricing_status);
    }

    public function test_conversion_sent_in_usdt_uses_the_sent_value_without_pricing_the_received_asset(): void
    {
        $user = User::query()->create([
            'name' => 'Pessoa de Teste',
            'email' => 'verification-unavailable@example.test',
            'email_verified_at' => now(),
            'password' => 'secret',
        ]);

        $transaction = Transaction::query()->create([
            'user_id' => $user->id,
            'type' => 'convert',
            'from_asset' => 'USDT',
            'from_amount' => 50,
            'to_asset' => 'SEM_PAR_HISTORICO',
            'to_amount' => 10,
            'total_usdt' => 0,
            'total_brl' => 0,
            'pricing_status' => 'unavailable',
            'pricing_failure_reason' => 'Cotação histórica não encontrada.',
            'date' => '2026-04-10 05:37:00',
            'source_type' => 'App\\Models\\UserApiKey',
            'source_id' => 22,
        ]);

        $price = new CryptoAssetPrice();
        $price->price_usd = 1;
        $price->price_brl = 5;

        $priceService = Mockery::mock(CryptoPriceService::class);
        $priceService->shouldReceive('getOrCreatePrice')->once()->andReturn($price);
        app()->instance(CryptoPriceService::class, $priceService);

        $result = app(TransactionVerificationService::class)
            ->verifyAndUpdateZeroValueTransactions($user, 22);

        $this->assertSame(1, $result['total_updated']);
        $this->assertSame('50.0000000000', $transaction->fresh()->total_usdt);
        $this->assertSame('250.0000000000', $transaction->fresh()->total_brl);
        $this->assertSame('completed', $transaction->fresh()->pricing_status);
        $this->assertSame(1, $transaction->fresh()->pricing_attempts);
        $this->assertNull($transaction->fresh()->pricing_failure_reason);
    }
}
