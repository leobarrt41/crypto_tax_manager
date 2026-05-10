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
        Schema::create('monthly_asset_snapshots', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->unsignedBigInteger('exchange_id'); // Mantido genérico
    $table->integer('year');
    $table->integer('month');
    $table->json('assets'); // ["BTC", "ETH", "SHIB"]
    $table->timestamps();
    $table->unique(['user_id', 'exchange_id', 'year', 'month']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monthly_asset_snapshots');
    }
};
