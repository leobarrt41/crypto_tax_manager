<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('trading_pairs', function (Blueprint $table) {
            $table->timestamp('listed_at')->nullable()->after('filters');
            $table->timestamp('delisted_at')->nullable()->after('listed_at');

            $table->index('listed_at');
            $table->index('delisted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trading_pairs', function (Blueprint $table) {
            $table->dropIndex(['listed_at']);
            $table->dropIndex(['delisted_at']);

            $table->dropColumn(['listed_at', 'delisted_at']);
        });
    }
};

