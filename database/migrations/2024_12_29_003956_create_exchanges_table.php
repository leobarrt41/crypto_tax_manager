<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('exchanges', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();        // Nome curto: ex: binance, kucoin
            $table->string('country_code', 2);       // Código do país (ISO 3166-1 alpha-2): ex: BR, MT
            $table->string('description')->nullable(); // Descrição ou nome completo
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchanges');
    }
};
