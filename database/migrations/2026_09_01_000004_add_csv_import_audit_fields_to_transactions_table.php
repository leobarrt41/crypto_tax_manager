<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->decimal('commission_value_brl', 24, 10)->nullable()->after('commission_asset')
                ->comment('Valor em BRL da taxa quando explicitamente informado pela fonte de importação');
            $table->string('reconciliation_status', 64)->nullable()->after('commission_value_brl')
                ->comment('Estado de conciliação de transferências e operações que não podem receber tratamento fiscal automático');
            $table->json('import_metadata')->nullable()->after('reconciliation_status')
                ->comment('Metadados auditáveis do arquivo de importação, sem credenciais ou dados sensíveis');
            $table->index(['user_id', 'reconciliation_status'], 'transactions_user_reconciliation_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex('transactions_user_reconciliation_status_index');
            $table->dropColumn(['commission_value_brl', 'reconciliation_status', 'import_metadata']);
        });
    }
};
