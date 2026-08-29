<?php

namespace Tests\Feature;

use App\Models\CryptoAsset;
use App\Models\PortfolioSnapshot;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletBalance;
use App\Services\PortfolioMetricsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PortfolioMetricsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'portfolio_snapshots', 'portfolios', 'fifo_opening_balances',
            'transactions', 'wallet_balances', 'wallets', 'crypto_assets', 'users',
        ] as $table) {
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
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
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
            $table->decimal('current_price_brl', 20, 8)->nullable();
            $table->decimal('price_change_24h', 12, 6)->nullable();
            $table->decimal('price_change_7d', 12, 6)->nullable();
            $table->decimal('price_change_30d', 12, 6)->nullable();
            $table->timestamps();
        });
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('from_asset')->nullable();
            $table->string('to_asset')->nullable();
            $table->decimal('from_amount', 24, 10)->nullable();
            $table->decimal('to_amount', 24, 10)->nullable();
            $table->string('type');
            $table->decimal('total_brl', 20, 2)->nullable();
            $table->dateTime('date');
            $table->nullableMorphs('source');
            $table->timestamps();
        });
        Schema::create('fifo_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('fiscal_year');
            $table->date('reference_date');
            $table->string('asset');
            $table->decimal('quantity', 24, 10);
            $table->decimal('total_cost_brl', 20, 2);
            $table->timestamps();
        });
        Schema::create('portfolios', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->decimal('total_value_brl', 20, 2)->default(0);
            $table->decimal('total_value_usd', 20, 2)->default(0);
            $table->decimal('total_invested', 20, 2)->default(0);
            $table->decimal('total_pnl', 20, 2)->default(0);
            $table->decimal('pnl_percentage', 12, 4)->default(0);
            $table->timestamp('last_updated_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('portfolio_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('portfolio_id');
            $table->unsignedBigInteger('wallet_id')->nullable();
            $table->decimal('total_value_brl', 20, 2);
            $table->decimal('total_value_usd', 20, 2)->nullable();
            $table->decimal('total_pnl', 20, 2);
            $table->dateTime('snapshot_date');
            $table->string('source')->default('local');
            $table->string('reconstruction_status')->default('complete');
            $table->decimal('coverage_percentage', 5, 2)->default(100);
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        foreach ([
            'portfolio_snapshots', 'portfolios', 'fifo_opening_balances',
            'transactions', 'wallet_balances', 'wallets', 'crypto_assets', 'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_calculates_portfolio_from_available_and_locked_balances_with_real_cost_basis(): void
    {
        $user = User::query()->create([
            'name' => 'Pessoa de Teste',
            'email' => 'portfolio@example.test',
            'password' => 'secret',
        ]);
        $wallet = Wallet::query()->create(['user_id' => $user->id, 'name' => 'Binance']);
        CryptoAsset::query()->create([
            'symbol' => 'BTC',
            'name' => 'Bitcoin',
            'current_price_brl' => 300000,
            'price_change_24h' => 5.0,
        ]);
        WalletBalance::query()->create([
            'wallet_id' => $wallet->id,
            'asset' => 'BTC',
            'available' => 1.0,
            'locked' => 0.2,
        ]);
        Transaction::query()->create([
            'user_id' => $user->id,
            'from_asset' => 'BRL',
            'to_asset' => 'BTC',
            'from_amount' => 240000,
            'to_amount' => 1.2,
            'type' => 'fiat_buy',
            'total_brl' => 240000,
            'date' => now()->subMonth(),
        ]);

        $overview = app(PortfolioMetricsService::class)->overview($user);

        $this->assertSame(360000.0, $overview['total_value']);
        $this->assertSame(240000.0, $overview['total_invested']);
        $this->assertSame(120000.0, $overview['total_profit_loss']);
        $this->assertSame(50.0, $overview['total_profit_loss_percentage']);
        $this->assertSame(1, $overview['assets_count']);
        $this->assertSame(1, $overview['wallets_count']);
        $this->assertSame(1.2, $overview['assets'][0]['quantity']);
        $this->assertSame(1, $overview['assets'][0]['wallets_count']);
        $this->assertDatabaseCount('portfolio_snapshots', 1);
    }

    public function test_filters_metrics_by_wallet_without_overwriting_the_consolidated_snapshot(): void
    {
        $user = User::query()->create([
            'name' => 'Pessoa de Teste',
            'email' => 'portfolio-wallet@example.test',
            'password' => 'secret',
        ]);
        $firstWallet = Wallet::query()->create(['user_id' => $user->id, 'name' => 'Binance']);
        $secondWallet = Wallet::query()->create(['user_id' => $user->id, 'name' => 'Carteira externa']);
        CryptoAsset::query()->create(['symbol' => 'BTC', 'name' => 'Bitcoin', 'current_price_brl' => 300000]);
        CryptoAsset::query()->create(['symbol' => 'ETH', 'name' => 'Ethereum', 'current_price_brl' => 10000]);
        WalletBalance::query()->create(['wallet_id' => $firstWallet->id, 'asset' => 'BTC', 'available' => 1, 'locked' => 0]);
        WalletBalance::query()->create(['wallet_id' => $secondWallet->id, 'asset' => 'ETH', 'available' => 2, 'locked' => 0]);

        $metrics = app(PortfolioMetricsService::class);
        $consolidated = $metrics->overview($user);
        $snapshotCount = PortfolioSnapshot::query()->count();
        $filtered = $metrics->overview($user, '30d', $firstWallet->id);

        $this->assertSame(320000.0, $consolidated['total_value']);
        $this->assertSame(300000.0, $filtered['total_value']);
        $this->assertSame(1, $filtered['wallets_count']);
        $this->assertSame($snapshotCount, PortfolioSnapshot::query()->count());
    }
}
