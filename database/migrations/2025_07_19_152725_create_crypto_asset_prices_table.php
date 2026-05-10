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
       Schema::create('crypto_asset_prices', function (Blueprint $table) {
            $table->id();
            $table->string('symbol'); // ex: BTC, ETH
            $table->decimal('price_brl', 20, 10)->nullable();
            $table->decimal('price_usdt', 20, 10)->nullable();
            $table->timestamp('recorded_at'); // Data e hora do preço
            $table->timestamps();

            $table->index(['symbol', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crypto_asset_prices');
    }
};
