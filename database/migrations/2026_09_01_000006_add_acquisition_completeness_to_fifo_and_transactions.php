<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('quantity_status', 20)->nullable()->after('reconciliation_status');
            $table->string('cost_status', 20)->nullable()->after('quantity_status');
            $table->string('cost_evidence_type', 40)->nullable()->after('cost_status');
            $table->index(['user_id', 'quantity_status', 'cost_status'], 'transactions_user_acquisition_completeness_index');
        });

        Schema::table('fifo_inventory_gaps', function (Blueprint $table): void {
            $table->string('quantity_status', 20)->default('incomplete')->after('status');
            $table->string('cost_status', 20)->default('known')->after('quantity_status');
            $table->decimal('pending_cost_quantity', 24, 12)->default(0)->after('missing_quantity');
            $table->index(['user_id', 'quantity_status', 'cost_status'], 'fifo_gaps_user_completeness_index');
        });
    }

    public function down(): void
    {
        Schema::table('fifo_inventory_gaps', function (Blueprint $table): void {
            $table->dropIndex('fifo_gaps_user_completeness_index');
            $table->dropColumn(['quantity_status', 'cost_status', 'pending_cost_quantity']);
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex('transactions_user_acquisition_completeness_index');
            $table->dropColumn(['quantity_status', 'cost_status', 'cost_evidence_type']);
        });
    }
};
