<?php

namespace Tests\Feature;

use App\Models\Network;
use App\Models\Portfolio;
use App\Models\PortfolioSnapshot;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserApiKey;
use App\Models\Wallet;
use App\Models\WalletBalance;
use App\Services\CryptoPriceService;
use App\Services\PortfolioHistoryReconstructionService;
use App\Services\PortfolioMetricsService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class PortfolioHistoryReconstructionServiceTest extends TestCase
{
    private User $user;
    private Network $network;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'portfolio_snapshots', 'portfolios', 'transactions', 'wallet_balances', 'wallets',
            'networks', 'user_api_keys', 'crypto_asset_prices', 'crypto_assets', 'users',
        ] as $table) {
            $this->dropTestTable($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
            $table->softDeletes();
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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('from_asset')->nullable();
            $table->decimal('from_amount', 24, 10)->nullable();
            $table->string('to_asset')->nullable();
            $table->decimal('to_amount', 24, 10)->nullable();
            $table->decimal('commission', 24, 10)->nullable();
            $table->string('commission_asset')->nullable();
            $table->string('type');
            $table->string('operation')->nullable();
            $table->decimal('price', 24, 10)->nullable();
            $table->decimal('total_usdt', 24, 10)->nullable();
            $table->decimal('total_brl', 24, 10)->nullable();
            $table->string('reference')->nullable();
            $table->timestamp('date');
            $table->timestamps();
        });
        Schema::create('portfolios', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->decimal('total_value_brl', 20, 2)->default(0);
            $table->decimal('total_value_usd', 20, 2)->nullable();
            $table->decimal('total_invested', 20, 2)->default(0);
            $table->decimal('total_pnl', 20, 2)->nullable();
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
            $table->decimal('total_pnl', 20, 2)->nullable();
            $table->timestamp('snapshot_date');
            $table->string('source')->default('local');
            $table->string('reconstruction_status')->default('complete');
            $table->decimal('coverage_percentage', 5, 2)->default(100);
            $table->json('data')->nullable();
            $table->timestamps();
        });
        Schema::create('crypto_assets', function (Blueprint $table) {
            $table->id();
            $table->string('symbol')->unique();
            $table->string('name');
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
        Schema::create('user_api_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('exchange_id')->nullable();
            $table->text('api_key');
            $table->text('secret_key');
            $table->timestamps();
        });

        $this->user = User::query()->create([
            'name' => 'Pessoa de Teste',
            'email' => 'history@example.test',
            'password' => 'secret',
        ]);
        $this->network = Network::query()->create(['name' => 'Teste', 'slug' => 'test', 'explorer_url' => null]);
    }

    protected function tearDown(): void
    {
        foreach ([
            'portfolio_snapshots', 'portfolios', 'transactions', 'wallet_balances', 'wallets',
            'networks', 'user_api_keys', 'crypto_asset_prices', 'crypto_assets', 'users',
        ] as $table) {
            $this->dropTestTable($table);
        }

        Mockery::close();
        parent::tearDown();
    }

    public function test_reconstructs_purchase_sale_deposit_withdrawal_conversion_fee_and_reward(): void
    {
        $wallet = $this->wallet('Carteira principal', 'wallet:main');
        // Saldo final compatível com todas as operações abaixo, partindo de
        // 100 USDT antes da primeira compra e sem saldo BNB após a comissão.
        $this->balance($wallet, 'BTC', 0.91);
        $this->balance($wallet, 'USDT', 150);

        $this->transaction($wallet, 'buy', 'USDT', 100, 'BTC', 1, '2026-03-01 09:00:00');
        $this->transaction($wallet, 'sell', 'BTC', 0.2, 'USDT', 120, '2026-03-02 09:00:00');
        $this->transaction($wallet, 'deposit', null, null, 'USDT', 100, '2026-03-03 09:00:00');
        $this->transaction($wallet, 'withdrawal', 'USDT', 50, null, null, '2026-03-04 09:00:00');
        $this->transaction($wallet, 'convert', 'USDT', 20, 'BTC', 0.1, '2026-03-05 09:00:00', 0.01, 'BNB');
        $this->transaction($wallet, 'reward', null, null, 'BTC', 0.01, '2026-03-06 09:00:00');

        $this->reconstruct('2026-03-01', '2026-03-06');

        $closingOnFifth = $this->walletSnapshot($wallet, '2026-03-05');
        $closingOnFourth = $this->walletSnapshot($wallet, '2026-03-04');
        $closingOnFirst = $this->walletSnapshot($wallet, '2026-03-01');
        $fifthAssets = collect($closingOnFifth->data['assets'])->keyBy('symbol');
        $fourthAssets = collect($closingOnFourth->data['assets'])->keyBy('symbol');
        $firstAssets = collect($closingOnFirst->data['assets'])->keyBy('symbol');

        $this->assertEquals(0.9, $fifthAssets['BTC']['quantity']);
        $this->assertEquals(150.0, $fifthAssets['USDT']['quantity']);
        $this->assertFalse($fifthAssets->has('BNB'));
        $this->assertEquals(0.8, $fourthAssets['BTC']['quantity']);
        $this->assertEquals(170.0, $fourthAssets['USDT']['quantity']);
        $this->assertEquals(0.01, $fourthAssets['BNB']['quantity']);
        $this->assertEquals(1.0, $firstAssets['BTC']['quantity']);
        $this->assertFalse($firstAssets->has('USDT'));
    }

    public function test_reconstructs_and_consolidates_multiple_wallets_without_duplicate_snapshots(): void
    {
        $first = $this->wallet('Carteira A', 'wallet:a');
        $second = $this->wallet('Carteira B', 'wallet:b');
        $this->balance($first, 'BTC', 1);
        $this->balance($second, 'ETH', 2);

        $service = $this->reconstruct('2026-03-01', '2026-03-02');
        $service->reconstruct(
            $this->user,
            Carbon::parse('2026-03-01', 'America/Sao_Paulo'),
            Carbon::parse('2026-03-02', 'America/Sao_Paulo')->endOfDay(),
        );

        $portfolio = Portfolio::query()->where('user_id', $this->user->id)->firstOrFail();
        // Duas carteiras × dois dias, mais dois consolidados. A segunda execução
        // deve atualizar esses mesmos registros, sem duplicá-los.
        $this->assertSame(6, PortfolioSnapshot::query()->where('portfolio_id', $portfolio->id)->count());
        $consolidated = PortfolioSnapshot::query()
            ->where('portfolio_id', $portfolio->id)
            ->whereNull('wallet_id')
            ->whereDate('snapshot_date', '2026-03-02')
            ->where('source', 'reconstructed')
            ->firstOrFail();
        $this->assertEquals(300.0, $consolidated->total_value_brl);
    }

    public function test_marks_snapshot_partial_when_historical_price_is_unavailable_and_keeps_it_out_of_total(): void
    {
        $wallet = $this->wallet('Sem preço', 'wallet:unpriced');
        $this->balance($wallet, 'UNKNOWN', 2);

        $this->reconstruct('2026-03-01', '2026-03-01', ['UNKNOWN' => 0]);

        $snapshot = $this->walletSnapshot($wallet, '2026-03-01');
        $this->assertSame('partial', $snapshot->reconstruction_status);
        $this->assertEquals(0.0, $snapshot->coverage_percentage);
        $this->assertSame(['UNKNOWN'], $snapshot->data['unpriced_assets']);
        $this->assertEquals(0.0, $snapshot->total_value_brl);

        $history = app(PortfolioMetricsService::class)->history($this->user, 'all', $wallet->id);
        $this->assertSame([], $history['data']);
    }

    public function test_keeps_official_and_local_snapshots_above_reconstructed_history(): void
    {
        $wallet = $this->wallet('Prioridade', 'wallet:priority');
        $this->balance($wallet, 'BTC', 1);
        $service = $this->reconstruct('2026-03-01', '2026-03-01');
        $portfolio = Portfolio::query()->where('user_id', $this->user->id)->firstOrFail();
        $date = Carbon::parse('2026-03-01', 'America/Sao_Paulo')->endOfDay();

        PortfolioSnapshot::query()->create([
            'portfolio_id' => $portfolio->id,
            'wallet_id' => $wallet->id,
            'snapshot_date' => $date,
            'source' => 'official',
            'reconstruction_status' => 'complete',
            'coverage_percentage' => 100,
            'total_value_brl' => 200,
            'total_value_usd' => 40,
            'data' => ['assets' => [['symbol' => 'BTC', 'quantity' => 1]]],
        ]);
        $service->reconstruct($this->user, $date->copy()->startOfDay(), $date);

        $consolidated = PortfolioSnapshot::query()
            ->where('portfolio_id', $portfolio->id)
            ->whereNull('wallet_id')
            ->where('source', 'reconstructed')
            ->firstOrFail();
        $this->assertEquals(200.0, $consolidated->total_value_brl);

        PortfolioSnapshot::query()->create([
            'portfolio_id' => $portfolio->id,
            'wallet_id' => null,
            'snapshot_date' => $date,
            'source' => 'local',
            'reconstruction_status' => 'complete',
            'coverage_percentage' => 100,
            'total_value_brl' => 300,
            'total_value_usd' => 60,
            'data' => ['assets' => [['symbol' => 'BTC', 'quantity' => 1]]],
        ]);
        $history = app(PortfolioMetricsService::class)->history($this->user, 'all');

        $this->assertSame('local', $history['data'][0]['source']);
        $this->assertEquals(300.0, $history['data'][0]['value_brl']);
    }

    public function test_maps_binance_api_key_transactions_to_its_automatic_wallet(): void
    {
        $apiKey = UserApiKey::query()->create([
            'user_id' => $this->user->id,
            'api_key' => 'api-key',
            'secret_key' => 'secret-key',
        ]);
        $wallet = $this->wallet('Binance Spot', "exchange:binance:api-key:{$apiKey->id}");
        $this->balance($wallet, 'BTC', 1);
        Transaction::query()->create([
            'user_id' => $this->user->id,
            'source_type' => UserApiKey::class,
            'source_id' => $apiKey->id,
            'type' => 'deposit',
            'to_asset' => 'BTC',
            'to_amount' => 1,
            'date' => Carbon::parse('2026-03-01 09:00:00', 'America/Sao_Paulo'),
        ]);

        $this->reconstruct('2026-03-01', '2026-03-01');

        $snapshot = $this->walletSnapshot($wallet, '2026-03-01');
        $this->assertSame([], $snapshot->data['negative_assets']);
        $this->assertSame(0, $snapshot->data['unassigned_transactions']);
    }

    public function test_legacy_empty_local_snapshot_does_not_override_reconstructed_value(): void
    {
        $wallet = $this->wallet('Carteira válida', 'wallet:legacy-empty');
        $this->balance($wallet, 'BTC', 1);
        $portfolio = Portfolio::query()->firstOrCreate(
            ['user_id' => $this->user->id, 'name' => 'Portfolio Principal'],
            ['is_active' => true],
        );
        $date = Carbon::parse('2026-03-01', 'America/Sao_Paulo')->endOfDay();

        PortfolioSnapshot::query()->create([
            'portfolio_id' => $portfolio->id,
            'wallet_id' => null,
            'snapshot_date' => $date,
            'source' => 'local',
            'reconstruction_status' => 'complete',
            'coverage_percentage' => 100,
            'total_value_brl' => 0,
            'total_value_usd' => null,
            'data' => ['assets' => []],
        ]);

        $this->reconstruct('2026-03-01', '2026-03-01');
        $history = app(PortfolioMetricsService::class)->history($this->user, 'all');

        $this->assertCount(1, $history['data']);
        $this->assertSame('reconstructed', $history['data'][0]['source']);
        $this->assertEquals(100.0, $history['data'][0]['value_brl']);
    }

    public function test_places_transactions_without_identified_source_in_a_separate_partial_wallet(): void
    {
        $this->transaction(null, 'deposit', null, null, 'BTC', 1, '2026-03-01 09:00:00');
        $this->reconstruct('2026-03-01', '2026-03-01');

        $wallet = Wallet::query()->where('address', "unidentified:source:user:{$this->user->id}")->firstOrFail();
        $snapshot = $this->walletSnapshot($wallet, '2026-03-01');

        $this->assertSame('Origem não identificada', $wallet->name);
        $this->assertSame('partial', $snapshot->reconstruction_status);
        $this->assertSame(1, $snapshot->data['unassigned_transactions']);
    }

    private function wallet(string $name, string $address): Wallet
    {
        return Wallet::query()->create([
            'user_id' => $this->user->id,
            'name' => $name,
            'network_id' => $this->network->id,
            'address' => $address,
        ]);
    }

    private function balance(Wallet $wallet, string $asset, float $available): void
    {
        WalletBalance::query()->create([
            'wallet_id' => $wallet->id,
            'asset' => $asset,
            'available' => $available,
            'locked' => 0,
        ]);
    }

    private function transaction(?Wallet $wallet, string $type, ?string $fromAsset, ?float $fromAmount, ?string $toAsset, ?float $toAmount, string $date, ?float $commission = null, ?string $commissionAsset = null): void
    {
        Transaction::query()->create([
            'user_id' => $this->user->id,
            'source_type' => $wallet ? Wallet::class : null,
            'source_id' => $wallet?->id,
            'type' => $type,
            'from_asset' => $fromAsset,
            'from_amount' => $fromAmount,
            'to_asset' => $toAsset,
            'to_amount' => $toAmount,
            'commission' => $commission,
            'commission_asset' => $commissionAsset,
            'date' => Carbon::parse($date, 'America/Sao_Paulo'),
        ]);
    }

    /** @param array<string, float> $prices */
    private function reconstruct(string $from, string $to, array $prices = []): PortfolioHistoryReconstructionService
    {
        $this->mockPriceService($prices);
        $service = app(PortfolioHistoryReconstructionService::class);
        $service->reconstruct($this->user, Carbon::parse($from, 'America/Sao_Paulo'), Carbon::parse($to, 'America/Sao_Paulo')->endOfDay());

        return $service;
    }

    /** @param array<string, float> $prices */
    private function mockPriceService(array $prices = []): void
    {
        $priceService = Mockery::mock(CryptoPriceService::class);
        $priceService->shouldReceive('getOrCreatePrice')->zeroOrMoreTimes()->andReturnUsing(
            function (string $symbol) use ($prices) {
                $price = $prices[$symbol] ?? 100.0;

                return (object) ['price_usd' => $price > 0 ? 20.0 : 0.0, 'price_brl' => $price];
            },
        );
        app()->instance(CryptoPriceService::class, $priceService);
    }

    private function walletSnapshot(Wallet $wallet, string $date): PortfolioSnapshot
    {
        return PortfolioSnapshot::query()
            ->where('wallet_id', $wallet->id)
            ->where('source', 'reconstructed')
            ->whereDate('snapshot_date', $date)
            ->firstOrFail();
    }
}
