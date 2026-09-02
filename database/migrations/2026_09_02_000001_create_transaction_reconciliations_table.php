<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('canonical_transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('matched_transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->string('match_type', 50);
            $table->string('confidence', 20);
            $table->string('fingerprint', 64);
            $table->string('status', 20)->default('confirmed');
            $table->json('matching_evidence');
            $table->timestamp('reconciled_at');
            $table->timestamps();

            $table->unique('matched_transaction_id');
            $table->unique(['canonical_transaction_id', 'matched_transaction_id'], 'transaction_reconciliations_pair_unique');
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_reconciliations');
    }
};
