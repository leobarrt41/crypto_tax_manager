<?php

namespace Tests\Feature;

use App\Models\CryptoAsset;
use App\Models\Exchange;
use App\Models\User;
use App\Models\UserApiKey;
use App\Models\WalletBalance;
use App\Services\BinancePortfolioSyncService;
use App\Services\CryptoPriceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class BinancePortfolioSyncServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['wallet_balances', 'wallets', 'networks', 'user_api_keys', 'exchanges', 'crypto_assets', 'crypto_asset_prices', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('exchanges', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('user_api_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('exchange_id');
            $table->text('api_key');
            $table->text('secret_key');
            $table->timestamps();
        });
        Schema::create('networks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('explorer_url')->nullable();
            $table->timestamps();
        });
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->unsignedBigInteger('network_id');
            $table->string('address')->unique();
            $table->text('api_key')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('wallet_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wallet_id');
            $table->string('asset');
            $table->decimal('available', 24, 10)->default(0);
            $table->decimal('locked', 24, 10)->default(0);
            $table->timestamp('retrieved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('crypto_assets', function (Blueprint $table) {
            $table->id();
            $table->string('symbol')->unique();
            $table->string('name');
            $table->decimal('current_price_brl', 24, 8)->nullable();
            $table->decimal('current_price_usd', 24, 8)->nullable();
            $table->decimal('price_change_24h', 12, 4)->nullable();
            $table->timestamp('price_updated_at')->nullable();
            $table->timestamp('market_data_updated_at')->nullable();
            $table->timestamps();
        });
        Schema::create('crypto_asset_prices', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->dateTime('recorded_at');
            $table->decimal('price_usdt', 24, 10)->nullable();
            $table->decimal('price_brl', 24, 10)->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        foreach (['wallet_balances', 'wallets', 'networks', 'user_api_keys', 'exchanges', 'crypto_assets', 'crypto_asset_prices', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Mockery::close();
        parent::tearDown();
    }

    public function test_sync_persists_current_binance_balances_and_prices_for_portfolio(): void
    {
        $user = User::query()->create([
            'name' => 'Pessoa de Teste',
            'email' => 'binance-portfolio@example.test',
            'password' => 'secret',
        ]);
        $exchange = Exchange::query()->create(['name' => 'Binance']);
        UserApiKey::query()->create([
            'user_id' => $user->id,
            'exchange_id' => $exchange->id,
            'api_key' => 'api-key',
            'secret_key' => 'secret-key',
        ]);

        Http::fake([
            'https://api.binance.com/api/v3/ticker/24hr' => Http::response([
                ['symbol' => 'BTCUSDT', 'lastPrice' => '100000.00', 'priceChangePercent' => '2.50'],
            ]),
            'https://api.binance.com/api/v3/account*' => Http::response([
                'balances' => [
                    ['asset' => 'BTC', 'free' => '0.10', 'locked' => '0.02'],
                    ['asset' => 'USDT', 'free' => '25.00', 'locked' => '0.50'],
                ],
            ]),
        ]);

        $priceService = Mockery::mock(CryptoPriceService::class);
        $priceService->shouldReceive('getUsdToBrlRate')->once()->andReturn(5.0);
        app()->instance(CryptoPriceService::class, $priceService);

        $result = app(BinancePortfolioSyncService::class)->sync($user);

        $this->assertSame(1, $result['keys_processed']);
        $this->assertSame(1, $result['wallets_updated']);
        $this->assertSame(2, $result['assets_with_balance']);
        $this->assertSame(2, $result['prices_updated']);
        $this->assertSame(0, $result['prices_unavailable']);
        $this->assertDatabaseCount('wallets', 1);
        $this->assertDatabaseCount('wallet_balances', 2);
        $this->assertEquals(0.1, WalletBalance::query()->where('asset', 'BTC')->value('available'));
        $this->assertEquals(500000.0, CryptoAsset::query()->where('symbol', 'BTC')->value('current_price_brl'));
        $this->assertEquals(5.0, CryptoAsset::query()->where('symbol', 'USDT')->value('current_price_brl'));
    }
}
