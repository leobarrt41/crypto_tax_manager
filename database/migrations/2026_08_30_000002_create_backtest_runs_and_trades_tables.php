<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backtest_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trading_strategy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trading_strategy_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('strategy_version_number');
            $table->string('strategy_definition_hash', 64);
            $table->foreignId('exchange_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 32);
            $table->string('timeframe', 4);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('requested_start_at');
            $table->timestamp('requested_end_at');
            $table->timestamp('dataset_start_at')->nullable();
            $table->timestamp('dataset_end_at')->nullable();
            $table->string('dataset_hash', 64);
            $table->unsignedInteger('candles_count');
            $table->json('source_metadata');
            $table->json('simulation_config');
            $table->string('status', 32);
            $table->json('metrics')->nullable();
            $table->json('warnings')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'backtest_runs_user_created_at_index');
            $table->index(['trading_strategy_version_id', 'created_at'], 'backtest_runs_version_created_at_index');
            $table->index(['exchange_id', 'symbol', 'timeframe'], 'backtest_runs_market_index');
            $table->index('dataset_hash', 'backtest_runs_dataset_hash_index');
        });

        Schema::create('backtest_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backtest_run_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 16);
            $table->timestamp('signal_candle_open_time');
            $table->timestamp('fill_candle_open_time')->nullable();
            $table->string('side', 8);
            $table->decimal('fill_price', 32, 16);
            $table->decimal('quantity', 32, 16);
            $table->decimal('gross_value', 32, 16);
            $table->decimal('fee_amount', 32, 16);
            $table->decimal('fee_rate', 12, 8);
            $table->decimal('slippage_rate', 12, 8);
            $table->decimal('cash_before', 32, 16);
            $table->decimal('cash_after', 32, 16);
            $table->decimal('realized_pnl', 32, 16)->nullable();
            $table->text('reason');
            $table->json('condition_results')->nullable();
            $table->string('fill_rule', 32);
            $table->timestamps();

            $table->index(['backtest_run_id', 'signal_candle_open_time'], 'backtest_trades_run_signal_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backtest_trades');
        Schema::dropIfExists('backtest_runs');
    }
};
