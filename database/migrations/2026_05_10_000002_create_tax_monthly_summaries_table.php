<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabela de resumo mensal fiscal por usuário.
     * Armazena os totais apurados pelo método FIFO para cada mês/ano.
     * Idempotente: recálculo sobrescreve o registro existente (upsert).
     */
    public function up(): void
    {
        Schema::create('tax_monthly_summaries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            $table->smallInteger('year')->unsigned();
            $table->tinyInteger('month')->unsigned(); // 1-12

            // Totais do mês em BRL
            $table->decimal('total_alienacoes_brl', 24, 10)->default(0)
                ->comment('Soma de total_brl de todas as saídas tributáveis do mês');
            $table->decimal('lucro_realizado_brl', 24, 10)->default(0)
                ->comment('Soma dos profit_loss_brl positivos do mês');
            $table->decimal('prejuizo_realizado_brl', 24, 10)->default(0)
                ->comment('Soma absoluta dos profit_loss_brl negativos do mês');
            $table->decimal('resultado_liquido_brl', 24, 10)->default(0)
                ->comment('lucro_realizado_brl - prejuizo_realizado_brl');

            // Contadores auxiliares
            $table->integer('qtd_operacoes')->default(0)
                ->comment('Quantidade de operações de saída no mês');

            // Controle
            $table->timestamp('calculated_at')->nullable()
                ->comment('Última vez que este resumo foi recalculado');

            $table->timestamps();

            // Unicidade: um resumo por usuário/ano/mês
            $table->unique(['user_id', 'year', 'month']);
            $table->index(['user_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_monthly_summaries');
    }
};
