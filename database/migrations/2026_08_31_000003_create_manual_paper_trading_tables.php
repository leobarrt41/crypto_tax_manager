<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paper_trading_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trading_strategy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trading_strategy_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('strategy_version_number');
            $table->string('strategy_definition_hash', 64);
            $table->foreignId('exchange_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 32);
            $table->string('timeframe', 4);
            $table->decimal('initial_capital', 32, 16);
            $table->decimal('cash_balance', 32, 16);
            $table->decimal('position_quantity', 32, 16);
            $table->decimal('position_cost_basis', 32, 16);
            $table->decimal('realized_pnl', 32, 16);
            $table->decimal('total_fees', 32, 16);
            $table->decimal('allocation_pct', 12, 8);
            $table->decimal('fee_rate', 12, 8);
            $table->decimal('slippage_rate', 12, 8);
            $table->timestamp('history_start_at');
            $table->timestamp('last_evaluated_candle_open_time')->nullable();
            $table->json('pending_signal')->nullable();
            $table->string('status', 24);
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'created_at'], 'paper_sessions_user_status_created_index');
            $table->index(['trading_strategy_version_id', 'created_at'], 'paper_sessions_version_created_index');
            $table->index(['exchange_id', 'symbol', 'timeframe'], 'paper_sessions_market_index');
        });

        Schema::create('paper_trading_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paper_trading_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->timestamp('started_at');
            $table->timestamp('finished_at');
            $table->timestamp('processed_start_candle_open_time')->nullable();
            $table->timestamp('processed_end_candle_open_time')->nullable();
            $table->unsignedInteger('candles_processed');
            $table->string('dataset_hash', 64)->nullable();
            $table->string('status', 32);
            $table->string('decision', 16)->nullable();
            $table->json('signal_snapshot')->nullable();
            $table->json('source_metadata')->nullable();
            $table->json('warnings')->nullable();
            $table->timestamps();

            $table->unique(['paper_trading_session_id', 'sequence'], 'paper_cycles_session_sequence_unique');
            $table->index(['paper_trading_session_id', 'created_at'], 'paper_cycles_session_created_index');
        });

        Schema::create('paper_trading_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paper_trading_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('paper_trading_cycle_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 16);
            $table->string('side', 8);
            $table->timestamp('signal_candle_open_time');
            $table->timestamp('fill_candle_open_time');
            $table->string('fill_rule', 32);
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
            $table->timestamps();

            $table->index(['paper_trading_session_id', 'signal_candle_open_time'], 'paper_trades_session_signal_index');
            $table->index(['paper_trading_cycle_id', 'fill_candle_open_time'], 'paper_trades_cycle_fill_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paper_trading_trades');
        Schema::dropIfExists('paper_trading_cycles');
        Schema::dropIfExists('paper_trading_sessions');
    }
};
