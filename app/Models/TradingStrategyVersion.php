<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradingStrategyVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'trading_strategy_id',
        'version',
        'definition',
        'definition_hash',
        'status',
        'created_by',
    ];

    protected $casts = [
        'definition' => 'array',
    ];

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'trading_strategy_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function backtestRuns(): HasMany
    {
        return $this->hasMany(BacktestRun::class);
    }

    public function paperTradingSessions(): HasMany
    {
        return $this->hasMany(PaperTradingSession::class);
    }
}
