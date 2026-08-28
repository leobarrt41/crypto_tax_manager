<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'portfolio_id',
        'total_value_brl',
        'total_value_usd',
        'total_pnl',
        'snapshot_date',
        'data',
    ];

    protected $casts = [
        'total_value_brl' => 'decimal:2',
        'total_value_usd' => 'decimal:2',
        'total_pnl' => 'decimal:2',
        'snapshot_date' => 'datetime',
        'data' => 'array',
    ];

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }
}
