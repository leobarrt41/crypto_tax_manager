<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rules', function (Blueprint $table) {
            $table->id();

            $table->string('country')->nullable();         // Ex: 'BR', 'US', ou null para global
            $table->string('exchange_type');               // Ex: 'nacional', 'estrangeira', 'carteira', etc.

            $table->decimal('exemption_limit_brl', 20, 2)->nullable(); // Limite de isenção em BRL (ex: 35000)
            $table->decimal('tax_rate_percent', 5, 2)->default(15.00); // Alíquota (ex: 15%)

            $table->date('valid_from');                   // Data de início da regra
            $table->date('valid_until')->nullable();      // Data de fim (null = ainda válida)

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rules');
    }
};
