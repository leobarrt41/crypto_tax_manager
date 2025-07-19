<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWalletBalancesTable extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_balances', function (Blueprint $table) {
            $table->id();

            // Relacionamento com carteira específica
            $table->foreignId('wallet_id')->constrained()->onDelete('cascade');

            // Moeda (ex: BTC, USDT)
            $table->string('asset');

            // Saldo disponível e bloqueado
            $table->decimal('available', 20, 10);
            $table->decimal('locked', 20, 10)->default(0);

            // Data da última atualização
            $table->timestamp('retrieved_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_balances');
    }
}
