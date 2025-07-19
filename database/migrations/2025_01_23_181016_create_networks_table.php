<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('networks', function (Blueprint $table) {
            $table->id();
            $table->string('name');              // Nome da rede (ex: Ethereum)
            $table->string('slug')->unique();    // Identificador amigável (ex: eth, bsc)
            $table->string('explorer_url')->nullable(); // URL do explorador (opcional)
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('networks');
    }
};


