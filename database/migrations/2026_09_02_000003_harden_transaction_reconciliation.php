<?php

use App\Support\TransactionImportOrigin;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateCanonical = DB::table('transaction_reconciliations')
            ->select('canonical_transaction_id')
            ->groupBy('canonical_transaction_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicateCanonical) {
            throw new RuntimeException('Há transações canônicas conciliadas mais de uma vez. Revise-as antes de aplicar esta migration; nenhum registro será removido automaticamente.');
        }

        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('import_origin', 40)->default('legacy_unknown')->after('import_metadata');
            $table->index(['user_id', 'import_origin']);
        });

        DB::table('transactions')
            ->select(['id', 'source_type', 'import_metadata'])
            ->orderBy('id')
            ->chunkById(500, function ($transactions): void {
                foreach ($transactions as $transaction) {
                    $origin = TransactionImportOrigin::inferLegacy(
                        $transaction->source_type,
                        $transaction->import_metadata,
                    );

                    DB::table('transactions')->where('id', $transaction->id)->update(['import_origin' => $origin]);
                }
            });

        Schema::table('transaction_reconciliations', function (Blueprint $table): void {
            $table->timestamp('pending_review_at')->nullable()->after('reconciled_at');
            $table->timestamp('confirmed_at')->nullable()->after('pending_review_at');
            $table->timestamp('rejected_at')->nullable()->after('confirmed_at');
            $table->timestamp('revoked_at')->nullable()->after('rejected_at');
            $table->unique('canonical_transaction_id', 'transaction_reconciliations_canonical_unique');
        });

        Schema::create('transaction_reconciliation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reconciliation_id')->constrained('transaction_reconciliations')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 30);
            $table->string('previous_status', 20);
            $table->string('new_status', 20);
            $table->text('reason')->nullable();
            $table->json('evidence');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['reconciliation_id', 'occurred_at'], 'transaction_reconciliation_events_timeline');
            $table->index(['actor_user_id', 'occurred_at'], 'transaction_reconciliation_events_actor');
        });

        DB::table('transaction_reconciliations')
            ->where('status', 'confirmed')
            ->update([
                'status' => 'pending_review',
                'pending_review_at' => DB::raw('reconciled_at'),
                'confirmed_at' => null,
            ]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE transaction_reconciliations ALTER COLUMN status SET DEFAULT 'pending_review'");
        }

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('transaction_reconciliations', function (Blueprint $table): void {
                $table->dropForeign(['canonical_transaction_id']);
                $table->dropForeign(['matched_transaction_id']);
                $table->foreign('canonical_transaction_id')->references('id')->on('transactions')->restrictOnDelete();
                $table->foreign('matched_transaction_id')->references('id')->on('transactions')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('transaction_reconciliation_events')
            && DB::table('transaction_reconciliation_events')->exists()) {
            throw new RuntimeException('A migration não pode ser revertida enquanto houver eventos de auditoria de conciliação. Preserve ou exporte a trilha antes do rollback.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE transaction_reconciliations ALTER COLUMN status SET DEFAULT 'confirmed'");
        }
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('transaction_reconciliations', function (Blueprint $table): void {
                $table->dropForeign(['canonical_transaction_id']);
                $table->dropForeign(['matched_transaction_id']);
                $table->foreign('canonical_transaction_id')->references('id')->on('transactions')->cascadeOnDelete();
                $table->foreign('matched_transaction_id')->references('id')->on('transactions')->cascadeOnDelete();
            });
        }

        Schema::table('transaction_reconciliations', function (Blueprint $table): void {
            $table->dropUnique('transaction_reconciliations_canonical_unique');
            $table->dropColumn(['pending_review_at', 'confirmed_at', 'rejected_at', 'revoked_at']);
        });

        Schema::dropIfExists('transaction_reconciliation_events');

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'import_origin']);
            $table->dropColumn('import_origin');
        });
    }
};
