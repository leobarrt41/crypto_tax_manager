<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trading_strategies', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->string('mode', 20)->default('paper')->after('parameters');
            $table->foreignId('current_version_id')->nullable()->after('mode');
            $table->timestamp('archived_at')->nullable()->after('is_active');
            $table->index(['user_id', 'archived_at'], 'trading_strategies_user_archived_at_index');
        });

        Schema::create('trading_strategy_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trading_strategy_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('definition');
            $table->string('definition_hash', 64);
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['trading_strategy_id', 'version'], 'strategy_versions_strategy_version_unique');
            $table->index(['trading_strategy_id', 'status'], 'strategy_versions_strategy_status_index');
            $table->index('definition_hash', 'strategy_versions_definition_hash_index');
        });

        Schema::table('trading_strategies', function (Blueprint $table) {
            $table->foreign('current_version_id', 'trading_strategies_current_version_foreign')
                ->references('id')
                ->on('trading_strategy_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trading_strategies', function (Blueprint $table) {
            $table->dropForeign('trading_strategies_current_version_foreign');
            $table->dropIndex('trading_strategies_user_archived_at_index');
            $table->dropColumn(['description', 'mode', 'current_version_id', 'archived_at']);
        });

        Schema::dropIfExists('trading_strategy_versions');
    }
};
