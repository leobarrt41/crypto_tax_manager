<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWalletsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id(); // ID único da carteira
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Relacionamento com o usuário
            $table->string('name'); // Nome da carteira (ex.: Trust Wallet, Metamask)
            $table->foreignId('network_id')->constrained()->onDelete('restrict'); // Rede da carteira
            $table->string('address')->unique(); // Endereço da carteira pública
            $table->string('api_key')->nullable(); // Chave de API para consultas externas
            $table->string('description')->nullable(); // Descrição opcional da carteira
            $table->timestamps(); // Campos created_at e updated_at
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('wallets');
    }
}
