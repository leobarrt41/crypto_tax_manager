<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBotOrdersTable extends Migration
{
    public function up(): void
    {
        Schema::create('bot_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('trading_strategy_id')->constrained()->onDelete('cascade');
            $table->string('exchange_order_id')->nullable(); // ID da ordem na exchange
            $table->string('pair'); // Ex: BTCUSDT
            $table->enum('side', ['buy', 'sell']);
            $table->decimal('quantity', 20, 10);
            $table->decimal('price', 20, 10);
            $table->timestamp('executed_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_orders');
    }
}
