<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserApiKeysTable extends Migration
{
    public function up(): void
    {
        Schema::create('user_api_keys', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // Relacionamento com a tabela exchanges
            $table->foreignId('exchange_id')->constrained()->onDelete('cascade');

            $table->string('api_key');
            $table->string('secret_key');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_api_keys');
    }
}
