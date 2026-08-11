<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FifoOpeningBalance
 *
 * Representa o estoque existente em 31/12 do ano anterior e o respectivo
 * custo histórico. O registro é injetado como primeiro lote na fila FIFO do
 * fiscal_year informado.
 */
class FifoOpeningBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'fiscal_year',
        'reference_date',
        'asset',
        'quantity',
        'total_cost_brl',
        'source',
        'notes',
    ];

    protected $casts = [
        'fiscal_year'    => 'integer',
        'reference_date' => 'date',
        'quantity'       => 'decimal:12',
        'total_cost_brl' => 'decimal:10',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Custo médio informativo do lote de abertura, em BRL por unidade.
     */
    public function getUnitCostBrlAttribute(): float
    {
        $quantity = (float) $this->quantity;

        return $quantity > 0
            ? round((float) $this->total_cost_brl / $quantity, 10)
            : 0.0;
    }
}
