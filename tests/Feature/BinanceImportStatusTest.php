<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Exchange;
use App\Models\ImportSession;
use App\Models\User;
use App\Models\UserApiKey;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BinanceImportStatusTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDE=');
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDE=']);
        $this->withoutMiddleware(VerifyCsrfToken::class);

        foreach (['import_sessions', 'user_api_keys', 'exchanges', 'users'] as $table) {
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
            $table->string('description')->nullable();
            $table->string('country')->nullable();
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

        Schema::create('import_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type', 50);
            $table->string('source', 50);
            $table->string('filename')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('successful_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->json('errors')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('progress_percentage', 5, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        foreach (['import_sessions', 'user_api_keys', 'exchanges', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_returns_the_latest_status_for_the_selected_binance_key_and_year(): void
    {
        $user = User::query()->create([
            'name' => 'Pessoa de Teste',
            'email' => 'status@example.test',
            'email_verified_at' => now(),
            'password' => 'secret',
        ]);
        $exchange = Exchange::query()->create(['name' => 'binance', 'description' => 'Binance']);
        $apiKey = UserApiKey::query()->create([
            'user_id' => $user->id,
            'exchange_id' => $exchange->id,
            'api_key' => 'public-key',
            'secret_key' => 'private-key',
        ]);
        $session = ImportSession::query()->create([
            'user_id' => $user->id,
            'type' => 'exchange_sync',
            'source' => 'binance',
            'status' => 'completed',
            'successful_rows' => 12,
            'progress_percentage' => 100,
            'completed_at' => now(),
            'settings' => [
                'api_key_id' => $apiKey->id,
                'year' => 2026,
                'result' => ['spot_trades_imported' => 12],
                'pricing' => [
                    'status' => 'completed',
                    'checked' => 12,
                    'updated' => 10,
                    'unavailable' => 2,
                ],
            ],
        ]);

        $this->actingAs($user)
            ->getJson("/transactions/import-status?api_key_id={$apiKey->id}&year=2026")
            ->assertOk()
            ->assertJsonPath('session.id', $session->id)
            ->assertJsonPath('session.status', 'completed')
            ->assertJsonPath('session.transactions_imported', 12)
            ->assertJsonPath('session.result.spot_trades_imported', 12)
            ->assertJsonPath('session.pricing.status', 'completed')
            ->assertJsonPath('session.pricing.unavailable', 2);
    }

    public function test_pricing_status_is_considered_an_import_in_progress(): void
    {
        $session = new ImportSession(['status' => 'pricing']);

        $this->assertTrue($session->isInProgress());
    }
}
