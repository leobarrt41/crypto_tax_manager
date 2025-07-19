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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            
            // Informações básicas
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->string('password');
            
            // Informações pessoais
            $table->string('cpf', 14)->nullable()->unique();
            $table->string('phone', 20)->nullable();
            $table->date('birth_date')->nullable();
            
            // Configurações de localização
            $table->string('timezone', 50)->default('America/Sao_Paulo');
            $table->string('language', 10)->default('pt-BR');
            $table->string('currency', 3)->default('BRL');
            
            // Segurança e autenticação
            $table->boolean('two_factor_enabled')->default(false);
            $table->text('two_factor_secret')->nullable();
            $table->string('avatar')->nullable();
            $table->rememberToken();
            
            // Assinatura e planos
            $table->enum('subscription_plan', ['free', 'basic', 'premium', 'enterprise'])->default('free');
            $table->timestamp('subscription_expires_at')->nullable();
            
            // Logs de acesso
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            
            // Configurações em JSON
            $table->json('preferences')->nullable();
            $table->json('tax_settings')->nullable();
            
            // Timestamps e soft deletes
            $table->timestamps();
            $table->softDeletes();
            
            // Índices para performance
            $table->index(['email_verified_at']);

            $table->index(['subscription_plan', 'subscription_expires_at']);
            $table->index(['last_login_at']);
            $table->index(['deleted_at']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};