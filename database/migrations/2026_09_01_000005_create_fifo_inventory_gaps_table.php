<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fifo_inventory_gaps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->string('asset', 32);
            $table->decimal('required_quantity', 24, 12);
            $table->decimal('available_quantity', 24, 12);
            $table->decimal('missing_quantity', 24, 12);
            $table->timestamp('occurred_at');
            $table->string('status', 32)->default('open');
            $table->string('reason', 120);
            $table->string('source', 120)->nullable();
            $table->json('consumed_lots')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'transaction_id'], 'fifo_inventory_gaps_user_transaction_unique');
            $table->index(['user_id', 'status'], 'fifo_inventory_gaps_user_status_index');
            $table->index(['user_id', 'occurred_at'], 'fifo_inventory_gaps_user_occurred_at_index');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('fifo_status', 32)->nullable()->after('fifo_processed')
                ->comment('Estado da apuração FIFO: complete, incomplete ou null quando ainda não processada');
            $table->index(['user_id', 'fifo_status'], 'transactions_user_fifo_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex('transactions_user_fifo_status_index');
            $table->dropColumn('fifo_status');
        });

        Schema::dropIfExists('fifo_inventory_gaps');
    }
};
