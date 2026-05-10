<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaxMonthlySummary extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'year',
        'month',
        'total_alienacoes_brl',
        'lucro_realizado_brl',
        'prejuizo_realizado_brl',
        'resultado_liquido_brl',
        'qtd_operacoes',
        'calculated_at',
    ];

    protected $casts = [
        'year'                   => 'integer',
        'month'                  => 'integer',
        'total_alienacoes_brl'   => 'decimal:2',
        'lucro_realizado_brl'    => 'decimal:2',
        'prejuizo_realizado_brl' => 'decimal:2',
        'resultado_liquido_brl'  => 'decimal:2',
        'qtd_operacoes'          => 'integer',
        'calculated_at'          => 'datetime',
    ];

    // ─── Relacionamentos ────────────────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Nome do mês em português.
     */
    public function getNomeMesAttribute(): string
    {
        $meses = [
            1  => 'Janeiro',   2  => 'Fevereiro', 3  => 'Março',
            4  => 'Abril',     5  => 'Maio',       6  => 'Junho',
            7  => 'Julho',     8  => 'Agosto',     9  => 'Setembro',
            10 => 'Outubro',   11 => 'Novembro',   12 => 'Dezembro',
        ];

        return $meses[$this->month] ?? "Mês {$this->month}";
    }
}
