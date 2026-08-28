<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Models\UserApiKey;
use App\Services\TransactionImportCoverageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TransactionImportCoverageServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['transaction_import_coverages', 'transactions', 'user_api_keys', 'exchanges', 'users'] as $table) {
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

        Schema::create('exchanges', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('country')->nullable();
            $table->timestamps();
        });

        Schema::create('user_api_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('exchange_id');
            $table->string('api_key');
            $table->string('secret_key');
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->nullableMorphs('source');
            $table->string('from_asset')->nullable();
            $table->string('to_asset')->nullable();
            $table->decimal('from_amount', 24, 10)->nullable();
            $table->decimal('to_amount', 24, 10)->nullable();
            $table->string('type');
            $table->string('reference')->nullable();
            $table->dateTime('date');
            $table->timestamps();
        });

        Schema::create('transaction_import_coverages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('exchange_id');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('event_type', 40);
            $table->string('api_status', 20)->default('not_checked');
            $table->unsignedInteger('api_records_count')->default(0);
            $table->timestamp('api_checked_at')->nullable();
            $table->text('api_error')->nullable();
            $table->string('csv_status', 20)->default('not_imported');
            $table->unsignedInteger('csv_records_count')->default(0);
            $table->string('csv_filename')->nullable();
            $table->timestamp('csv_imported_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'exchange_id', 'year', 'month', 'event_type'], 'coverage_period_event_unique');
        });
    }

    protected function tearDown(): void
    {
        foreach (['transaction_import_coverages', 'transactions', 'user_api_keys', 'exchanges', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_combines_api_checkpoint_and_csv_confirmation_without_claiming_missing_events(): void
    {
        $user = User::query()->create([
            'name' => 'Pessoa de Teste',
            'email' => 'coverage@example.test',
            'password' => 'secret',
        ]);
        $exchangeId = \DB::table('exchanges')->insertGetId([
            'name' => 'binance',
            'country' => 'KY',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $apiKey = UserApiKey::query()->create([
            'user_id' => $user->id,
            'exchange_id' => $exchangeId,
            'api_key' => 'key',
            'secret_key' => 'secret',
        ]);
        $service = app(TransactionImportCoverageService::class);

        $service->recordApiCoverage($user, $exchangeId, 2026, 8, 'deposit', 'completed', 2);
        $service->recordApiCoverage($user, $exchangeId, 2026, 8, 'spot_trade', 'partial', 0, 'spot_pairs_checked: Consulta por par requer CSV.');
        Transaction::query()->create([
            'user_id' => $user->id,
            'source_type' => UserApiKey::class,
            'source_id' => $apiKey->id,
            'from_asset' => 'USDT',
            'to_asset' => 'BTC',
            'from_amount' => 100,
            'to_amount' => 0.001,
            'type' => 'trade',
            'reference' => 'spot-csv-1',
            'date' => '2026-08-10 12:00:00',
        ]);
        $service->recordCsvCoverage($user, $exchangeId, 2026, 8, 'spot_trade', 1, 'spot-08-2026.csv');

        $annual = $service->forYear($user, $exchangeId, 2026);
        $august = collect($annual['months'])->firstWhere('month', 8);
        $events = collect($august['events'])->keyBy('event_type');

        $this->assertTrue($service->wasApiCovered($user, $exchangeId, 2026, 8, 'deposit'));
        $this->assertTrue($service->wasApiChecked($user, $exchangeId, 2026, 8, 'spot_trade'));
        $this->assertSame('api_covered', $events['deposit']['status']);
        $this->assertSame('csv_confirmed', $events['spot_trade']['status']);
        $this->assertSame('awaiting_sync', $events['withdrawal']['status']);
        $this->assertSame('spot-08-2026.csv', $events['spot_trade']['csv_filename']);
    }
}
