<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCryptoAssetsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('crypto_assets', function (Blueprint $table) {
            $table->id(); // Identificador único
            $table->string('symbol')->unique(); // Símbolo da moeda (ex.: BTC, ETH)
            $table->string('name')->nullable(); // Nome completo da moeda (opcional)
            $table->string('contract_address')->nullable()->unique(); // Endereço do contrato na blockchain
            
            // Campos de preço e mercado
            $table->decimal('current_price_brl', 20, 8)->nullable(); // Preço atual em BRL
            $table->decimal('current_price_usd', 20, 8)->nullable(); // Preço atual em USD
            $table->decimal('price_change_24h', 10, 4)->nullable(); // Variação percentual 24h
            $table->decimal('price_change_7d', 10, 4)->nullable(); // Variação percentual 7d
            $table->decimal('price_change_30d', 10, 4)->nullable(); // Variação percentual 30d
            
            // Dados de mercado
            $table->decimal('market_cap', 20, 2)->nullable(); // Capitalização de mercado
            $table->decimal('volume_24h', 20, 2)->nullable(); // Volume 24h
            $table->bigInteger('circulating_supply')->nullable(); // Oferta circulante
            $table->bigInteger('total_supply')->nullable(); // Oferta total
            $table->bigInteger('max_supply')->nullable(); // Oferta máxima
            
            // Metadados
            $table->string('logo_url')->nullable(); // URL do logo
            $table->text('description')->nullable(); // Descrição do ativo
            $table->string('website')->nullable(); // Website oficial
            $table->string('blockchain')->nullable(); // Blockchain principal (Bitcoin, Ethereum, BSC, etc.)
            $table->json('social_links')->nullable(); // Links de redes sociais (Twitter, Telegram, etc.)
            
            // Configurações
            $table->boolean('is_active')->default(true); // Se o ativo está ativo
            $table->boolean('is_stablecoin')->default(false); // Se é uma stablecoin
            $table->boolean('is_defi')->default(false); // Se é um token DeFi
            $table->boolean('is_nft')->default(false); // Se é relacionado a NFTs
            
            // Dados de atualização
            $table->timestamp('price_updated_at')->nullable(); // Última atualização de preço
            $table->timestamp('market_data_updated_at')->nullable(); // Última atualização de dados de mercado
            
            $table->timestamps(); // Campos created_at e updated_at
            
            // Índices para performance
            $table->index(['symbol', 'is_active']);
            $table->index(['price_change_24h']);
            $table->index(['market_cap']);
            $table->index(['is_active', 'current_price_brl']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('crypto_assets');
    }
}
