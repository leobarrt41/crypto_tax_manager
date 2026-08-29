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
        'wallet_id',
        'total_value_brl',
        'total_value_usd',
        'total_pnl',
        'snapshot_date',
        'source',
        'reconstruction_status',
        'coverage_percentage',
        'data',
    ];

    protected $casts = [
        'total_value_brl' => 'decimal:2',
        'total_value_usd' => 'decimal:2',
        'total_pnl' => 'decimal:2',
        'snapshot_date' => 'datetime',
        'coverage_percentage' => 'decimal:2',
        'data' => 'array',
    ];

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
