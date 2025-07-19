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
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            $table->enum('type', ['in1888', 'irpf', 'monthly', 'yearly', 'custom', 'portfolio', 'pnl']);
            $table->string('title');
            $table->text('description')->nullable();
            
            // Período do relatório
            $table->date('period_start');
            $table->date('period_end');
            
            // Status e arquivo
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->string('file_path')->nullable();
            $table->bigInteger('file_size')->nullable();
            
            // Dados e configurações
            $table->json('data')->nullable();
            $table->json('settings')->nullable();
            
            // Controle de tempo
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Índices
            $table->index(['user_id', 'type']);
            $table->index(['user_id', 'status']);
            $table->index(['period_start', 'period_end']);
            $table->index(['generated_at']);
            $table->index(['expires_at']);
            $table->index(['deleted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};

