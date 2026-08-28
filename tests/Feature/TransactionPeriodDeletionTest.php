<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Services\FifoCalculatorService;
use Illuminate\Database\Schema\Blueprint;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TransactionPeriodDeletionTest extends TestCase
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

        Schema::dropIfExists('transactions');
        Schema::dropIfExists('users');

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
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_previews_and_deletes_only_the_selected_month_for_the_authenticated_user(): void
    {
        $user = $this->user('owner@example.test');
        $anotherUser = $this->user('other@example.test');
        $augustTransaction = $this->transaction($user, '2026-08-15 12:00:00', 1000);
        $septemberTransaction = $this->transaction($user, '2026-09-01 12:00:00', 2000);
        $otherUserTransaction = $this->transaction($anotherUser, '2026-08-15 12:00:00', 3000);
        $previousYearTransaction = $this->transaction($user, '2025-08-15 12:00:00', 4000);

        $this->actingAs($user)
            ->getJson('/transactions/delete-period/preview?year=2026&month=8')
            ->assertOk()
            ->assertJsonPath('period_label', '08/2026')
            ->assertJsonPath('transactions_count', 1)
            ->assertJsonPath('total_brl', 1000)
            ->assertJsonPath('confirmation_phrase', 'EXCLUIR 08/2026');

        $this->mock(FifoCalculatorService::class, function ($mock) use ($user) {
            $mock->shouldReceive('recalculateForUser')
                ->once()
                ->with($user->id, 2026)
                ->andReturn(['transactions_read' => 0]);
        });

        $this->actingAs($user)
            ->delete('/transactions/delete-period', [
                'year' => 2026,
                'month' => 8,
                'confirmation' => 'EXCLUIR 08/2026',
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('transactions', ['id' => $augustTransaction->id]);
        $this->assertDatabaseHas('transactions', ['id' => $septemberTransaction->id]);
        $this->assertDatabaseHas('transactions', ['id' => $otherUserTransaction->id]);
        $this->assertDatabaseHas('transactions', ['id' => $previousYearTransaction->id]);
    }

    public function test_deletes_all_months_of_a_year_when_month_is_omitted(): void
    {
        $user = $this->user('annual@example.test');
        $januaryTransaction = $this->transaction($user, '2026-01-01 12:00:00', 1000);
        $decemberTransaction = $this->transaction($user, '2026-12-31 12:00:00', 2000);
        $previousYearTransaction = $this->transaction($user, '2025-12-31 12:00:00', 3000);

        $this->mock(FifoCalculatorService::class, function ($mock) use ($user) {
            $mock->shouldReceive('recalculateForUser')
                ->once()
                ->with($user->id, 2026)
                ->andReturn(['transactions_read' => 0]);
        });

        $this->actingAs($user)
            ->delete('/transactions/delete-period', [
                'year' => 2026,
                'confirmation' => 'EXCLUIR 2026',
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('transactions', ['id' => $januaryTransaction->id]);
        $this->assertDatabaseMissing('transactions', ['id' => $decemberTransaction->id]);
        $this->assertDatabaseHas('transactions', ['id' => $previousYearTransaction->id]);
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'Pessoa de Teste',
            'email' => $email,
            'email_verified_at' => now(),
            'password' => 'secret',
        ]);
    }

    private function transaction(User $user, string $date, float $totalBrl): Transaction
    {
        return Transaction::query()->create([
            'user_id' => $user->id,
            'from_asset' => 'USDT',
            'to_asset' => 'BTC',
            'from_amount' => 1,
            'to_amount' => 0.00001,
            'type' => 'trade',
            'total_brl' => $totalBrl,
            'date' => $date,
        ]);
    }
}
