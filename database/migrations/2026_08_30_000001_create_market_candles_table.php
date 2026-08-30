<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_candles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exchange_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 32);
            $table->string('timeframe', 4);
            $table->timestamp('open_time');
            $table->timestamp('close_time');
            $table->decimal('open', 32, 16);
            $table->decimal('high', 32, 16);
            $table->decimal('low', 32, 16);
            $table->decimal('close', 32, 16);
            $table->decimal('volume', 32, 16);
            $table->unsignedInteger('trade_count')->nullable();
            $table->string('source', 64);
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(
                ['exchange_id', 'symbol', 'timeframe', 'open_time'],
                'market_candles_exchange_symbol_timeframe_open_unique',
            );
            $table->index(
                ['exchange_id', 'symbol', 'timeframe', 'open_time'],
                'market_candles_lookup_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_candles');
    }
};
