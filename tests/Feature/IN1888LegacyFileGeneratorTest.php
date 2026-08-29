<?php

namespace Tests\Feature;

use App\Models\CryptoReportingRuleVersion;
use App\Models\Exchange;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserApiKey;
use App\Services\CryptoReportingRuleResolver;
use App\Services\IN1888Service;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class IN1888LegacyFileGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['transactions', 'user_api_keys', 'exchanges', 'users'] as $table) {
            $this->dropTestTable($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('cpf', 11)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('exchanges', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('country_code', 2);
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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('type');
            $table->string('from_asset')->nullable();
            $table->decimal('from_amount', 24, 10)->nullable();
            $table->string('to_asset')->nullable();
            $table->decimal('to_amount', 24, 10)->nullable();
            $table->decimal('total_brl', 24, 2)->nullable();
            $table->string('reference')->nullable();
            $table->timestamp('date');
            $table->timestamps();
        });

        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_generates_only_read_file_with_official_legacy_records_for_binance(): void
    {
        [$user, $apiKey] = $this->binanceUser();
        $transactions = [
            $this->transaction($user, $apiKey, 'fiat_buy', null, null, 'BTC', 0.01, 5000, '2026-03-01'),
            $this->transaction($user, $apiKey, 'fiat_sell', 'BTC', 0.005, null, null, 3000, '2026-03-02'),
            $this->transaction($user, $apiKey, 'convert', 'BTC', 0.002, 'ETH', 0.04, 1200, '2026-03-03'),
            $this->transaction($user, $apiKey, 'deposit', null, null, 'USDT', 50, 250, '2026-03-04'),
            $this->transaction($user, $apiKey, 'withdrawal', 'USDT', 10, null, null, 50, '2026-03-05'),
        ];
        $before = Transaction::query()->orderBy('id')->get(['id', 'total_brl', 'from_amount', 'to_amount'])->toArray();

        $report = $this->service('in1888_2019_v1')->generateMonthlyFile($user->id, 3, 2026, true);
        $lines = array_values(array_filter(preg_split('/\r\n|\n/', $report['content'])));

        $this->assertTrue($report['validation_only']);
        $this->assertSame(['0110', '0120', '0210', '0410', '0510'], array_map(fn ($line) => substr($line, 0, 4), $lines));
        $this->assertSame([219, 219, 240, 204, 204], array_map('strlen', $lines));
        $this->assertStringContainsString('BINANCE', $lines[0]);
        $this->assertStringContainsString('HTTPS://WWW.BINANCE.COM', $lines[0]);
        $this->assertSame($before, Transaction::query()->orderBy('id')->get(['id', 'total_brl', 'from_amount', 'to_amount'])->toArray());
        Storage::disk('local')->assertExists('in1888/' . $report['filename']);
    }

    public function test_non_required_period_only_allows_explicit_validation_download(): void
    {
        [$user, $apiKey] = $this->binanceUser();
        $this->transaction($user, $apiKey, 'convert', 'USDT', 50, 'BTC', 0.001, 250, '2026-04-01');

        $preview = $this->service('in1888_2019_v1')->generateMonthlyFile($user->id, 4, 2026);
        $testFile = $this->service('in1888_2019_v1')->generateMonthlyFile($user->id, 4, 2026, true);

        $this->assertFalse($preview['required']);
        $this->assertFalse($preview['export_available']);
        $this->assertTrue($preview['validation_available']);
        $this->assertTrue($testFile['validation_only']);
        $this->assertStringStartsWith('IN1888_VALIDACAO_', $testFile['filename']);
    }

    public function test_july_2026_never_generates_legacy_in1888_file(): void
    {
        [$user, $apiKey] = $this->binanceUser();
        $this->transaction($user, $apiKey, 'convert', 'USDT', 50, 'BTC', 0.001, 250, '2026-07-01');

        $report = $this->service('decripto_2026_v1', false)->generateMonthlyFile($user->id, 7, 2026, true);

        $this->assertFalse($report['export_available']);
        $this->assertFalse($report['validation_available']);
        $this->assertStringContainsString('DeCripto', $report['message']);
    }

    private function binanceUser(): array
    {
        $user = User::query()->create(['name' => 'Pessoa Teste', 'email' => 'arquivo@example.test', 'password' => 'secret', 'cpf' => '12345678901']);
        $exchange = Exchange::query()->create(['name' => 'binance', 'country_code' => 'MT']);
        $key = UserApiKey::query()->create(['user_id' => $user->id, 'exchange_id' => $exchange->id, 'api_key' => 'key', 'secret_key' => 'secret']);
        return [$user, $key];
    }

    private function transaction(User $user, UserApiKey $apiKey, string $type, ?string $fromAsset, ?float $fromAmount, ?string $toAsset, ?float $toAmount, float $totalBrl, string $date): Transaction
    {
        return Transaction::query()->create([
            'user_id' => $user->id,
            'source_type' => UserApiKey::class,
            'source_id' => $apiKey->id,
            'type' => $type,
            'from_asset' => $fromAsset,
            'from_amount' => $fromAmount,
            'to_asset' => $toAsset,
            'to_amount' => $toAmount,
            'total_brl' => $totalBrl,
            'date' => $date,
        ]);
    }

    private function service(string $code, bool $legacyAvailable = true): IN1888Service
    {
        $rule = new CryptoReportingRuleVersion([
            'code' => $code,
            'obligation_name' => $legacyAvailable ? 'IN 1888' : 'DeCripto',
            'monthly_threshold_brl' => $legacyAvailable ? 30000.0 : 35000.0,
            'legacy_export_available' => $legacyAvailable,
        ]);
        $resolver = Mockery::mock(CryptoReportingRuleResolver::class);
        $resolver->shouldReceive('resolve')->andReturn($rule);
        $resolver->shouldReceive('context')->andReturn([
            'code' => $code,
            'obligation_name' => $rule->obligation_name,
            'monthly_threshold_brl' => $rule->monthly_threshold_brl,
        ]);
        $resolver->shouldReceive('isMonthlyDeclarationRequired')->andReturn(false);

        return new IN1888Service($resolver);
    }
}
