<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adiciona campos fiscais FIFO à tabela de transações.
     * Esses campos suportam a apuração mensal de ganhos de capital (IN 1888 / GCAP).
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Custo médio ponderado de aquisição em BRL (base FIFO)
            $table->decimal('cost_basis_brl', 24, 10)->nullable()->after('total_brl')
                ->comment('Custo de aquisição apurado pelo método FIFO em BRL');

            // Lucro ou prejuízo realizado em BRL (somente em saídas tributáveis)
            $table->decimal('profit_loss_brl', 24, 10)->nullable()->after('cost_basis_brl')
                ->comment('Resultado da alienação: positivo = lucro, negativo = prejuízo');

            // Rastreabilidade dos lotes FIFO consumidos (JSON)
            $table->json('fifo_lots')->nullable()->after('profit_loss_brl')
                ->comment('Lotes FIFO consumidos nesta saída: [{lot_date, lot_qty, lot_cost_brl}]');

            // Indica se este registro já foi processado pelo recálculo FIFO
            $table->boolean('fifo_processed')->default(false)->after('fifo_lots')
                ->comment('Flag de controle: true quando o FIFO já foi calculado para esta transação');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['cost_basis_brl', 'profit_loss_brl', 'fifo_lots', 'fifo_processed']);
        });
    }
};
