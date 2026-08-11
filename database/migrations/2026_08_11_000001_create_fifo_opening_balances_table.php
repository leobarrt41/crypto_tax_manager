<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saldo de abertura a 31/12 do ano anterior, usado como primeiro lote
     * do FIFO no ano fiscal selecionado.
     *
     * Exemplo: fiscal_year = 2024 representa o estoque detido em 31/12/2023.
     */
    public function up(): void
    {
        Schema::create('fifo_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year')
                ->comment('Ano em que o lote será usado; o saldo refere-se a 31/12 do ano anterior');
            $table->date('reference_date')
                ->comment('Data de referência do estoque inicial: 31/12 do ano anterior');
            $table->string('asset', 20)->comment('Símbolo do criptoativo, por exemplo BTC');
            $table->decimal('quantity', 32, 12)->comment('Quantidade em custódia em 31/12');
            $table->decimal('total_cost_brl', 24, 10)->comment('Custo histórico total de aquisição em BRL');
            $table->string('source', 100)->nullable()->comment('Origem documental do saldo/custo informado');
            $table->text('notes')->nullable()->comment('Observações para auditoria');
            $table->timestamps();

            $table->unique(['user_id', 'fiscal_year', 'asset'], 'fifo_opening_balance_unique');
            $table->index(['user_id', 'fiscal_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fifo_opening_balances');
    }
};
