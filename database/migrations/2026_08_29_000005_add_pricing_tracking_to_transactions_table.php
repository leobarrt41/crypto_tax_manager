<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('pricing_status', 30)->default('pending')->after('total_brl');
            $table->unsignedInteger('pricing_attempts')->default(0)->after('pricing_status');
            $table->timestamp('pricing_last_attempted_at')->nullable()->after('pricing_attempts');
            $table->text('pricing_failure_reason')->nullable()->after('pricing_last_attempted_at');
            $table->index('pricing_status');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['pricing_status']);
            $table->dropColumn([
                'pricing_status',
                'pricing_attempts',
                'pricing_last_attempted_at',
                'pricing_failure_reason',
            ]);
        });
    }
};
