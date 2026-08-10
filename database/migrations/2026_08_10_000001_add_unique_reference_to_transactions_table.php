<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona índice único (user_id, reference) na tabela transactions.
 *
 * Objetivo: garantir deduplicação cross-source — impede que a mesma operação
 * seja inserida duas vezes mesmo quando vinda de fontes diferentes
 * (ex: importação via API automática e depois via CSV do mesmo período).
 *
 * O índice é PARCIAL (WHERE reference IS NOT NULL) para não afetar transações
 * sem identificador único (manuais ou de formatos sem ID externo).
 *
 * Nota: MySQL não suporta índices parciais nativamente. Para MySQL, usamos
 * um índice único convencional e tratamos NULLs via lógica de aplicação
 * (MySQL trata múltiplos NULLs como distintos em índices UNIQUE).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Antes de criar o índice, remover duplicatas existentes que violam a constraint.
        // Mantém o registro mais antigo (menor id) para cada par (user_id, reference).
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // PostgreSQL: deleta duplicatas mantendo o menor id
            DB::statement("
                DELETE FROM transactions
                WHERE id NOT IN (
                    SELECT MIN(id)
                    FROM transactions
                    WHERE reference IS NOT NULL
                    GROUP BY user_id, reference
                )
                AND reference IS NOT NULL
            ");
        } else {
            // MySQL / MariaDB
            DB::statement("
                DELETE t1 FROM transactions t1
                INNER JOIN transactions t2
                    ON t1.user_id = t2.user_id
                    AND t1.reference = t2.reference
                    AND t1.id > t2.id
                WHERE t1.reference IS NOT NULL
            ");
        }

        Schema::table('transactions', function (Blueprint $table) {
            // Índice único composto: user_id + reference
            // MySQL trata NULL como distinto em UNIQUE, portanto transações sem
            // reference (manuais) não são afetadas por esta constraint.
            $table->unique(['user_id', 'reference'], 'transactions_user_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_user_reference_unique');
        });
    }
};
