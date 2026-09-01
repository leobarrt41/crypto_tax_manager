<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('from_quantity_status', 20)->nullable()->after('quantity_status');
            $table->string('from_cost_status', 20)->nullable()->after('cost_status');
            $table->string('from_cost_evidence_type', 50)->nullable()->after('cost_evidence_type');
            $table->string('to_quantity_status', 20)->nullable()->after('from_quantity_status');
            $table->string('to_cost_status', 20)->nullable()->after('from_cost_status');
            $table->string('to_cost_evidence_type', 50)->nullable()->after('from_cost_evidence_type');
            $table->decimal('to_cost_basis_brl', 24, 10)->nullable()->after('cost_basis_brl');
            $table->index(['user_id', 'type', 'from_cost_status', 'to_cost_status'], 'transactions_convert_leg_cost_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex('transactions_convert_leg_cost_status_index');
            $table->dropColumn([
                'from_quantity_status',
                'from_cost_status',
                'from_cost_evidence_type',
                'to_quantity_status',
                'to_cost_status',
                'to_cost_evidence_type',
                'to_cost_basis_brl',
            ]);
        });
    }
};
