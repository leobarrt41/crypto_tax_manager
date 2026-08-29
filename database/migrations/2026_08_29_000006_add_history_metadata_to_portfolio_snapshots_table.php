<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_snapshots', function (Blueprint $table) {
            $table->foreignId('wallet_id')
                ->nullable()
                ->after('portfolio_id')
                ->constrained()
                ->nullOnDelete();
            $table->string('source', 32)->default('local')->after('snapshot_date');
            $table->string('reconstruction_status', 32)->default('complete')->after('source');
            $table->decimal('coverage_percentage', 5, 2)->default(100)->after('reconstruction_status');

            $table->index(['wallet_id', 'snapshot_date'], 'portfolio_snapshots_wallet_date_index');
            $table->index(['source', 'snapshot_date'], 'portfolio_snapshots_source_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_snapshots', function (Blueprint $table) {
            $table->dropIndex('portfolio_snapshots_wallet_date_index');
            $table->dropIndex('portfolio_snapshots_source_date_index');
            $table->dropConstrainedForeignId('wallet_id');
            $table->dropColumn(['source', 'reconstruction_status', 'coverage_percentage']);
        });
    }
};
