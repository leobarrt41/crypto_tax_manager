<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Identificadores únicos das operações Binance
            $table->string('symbol', 20)->nullable()->index()->after('source_id');
            $table->bigInteger('order_id')->nullable()->index()->after('symbol');
            $table->bigInteger('trade_id')->nullable()->index()->after('order_id');

            // Quantidades e valores específicos de trade
            $table->decimal('qty', 24, 12)->nullable()->after('price');
            $table->decimal('quote_qty', 24, 12)->nullable()->after('qty');
            $table->decimal('commission', 24, 12)->nullable()->after('quote_qty');
            $table->string('commission_asset', 20)->nullable()->after('commission');

            // Lado da operação (BUY/SELL)
            $table->enum('side', ['BUY', 'SELL'])->nullable()->after('type');

            // Data/hora de execução precisa
            $table->timestamp('executed_at')->nullable()->after('date');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'symbol', 'order_id', 'trade_id',
                'qty', 'quote_qty', 'commission',
                'commission_asset', 'side', 'executed_at'
            ]);
        });
    }
};
