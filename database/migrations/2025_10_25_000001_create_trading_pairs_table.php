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
        Schema::create('trading_pairs', function (Blueprint $table) {
            $table->id();
            $table->string('symbol')->unique();
            $table->string('base_asset');
            $table->string('quote_asset');
            $table->string('status')->nullable();
            $table->boolean('is_spot_trading_allowed')->default(false);
            $table->boolean('is_margin_trading_allowed')->default(false);
            $table->json('filters')->nullable();
            $table->timestamps();

            $table->index(['base_asset', 'quote_asset']);
            $table->index(['quote_asset']);
            $table->index(['status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trading_pairs');
    }
};
