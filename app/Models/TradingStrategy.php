<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradingStrategy extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'type',
        'parameters',
        'user_id',
        'mode',
        'is_active',
        'current_version_id',
        'archived_at',
    ];

    protected $casts = [
        'parameters' => 'array',
        'archived_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function botOrders(): HasMany
    {
        return $this->hasMany(BotOrder::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TradingStrategyVersion::class)->orderByDesc('version');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(TradingStrategyVersion::class, 'current_version_id');
    }

    public function tradingLogs(): HasMany
    {
        return $this->hasMany(TradingLog::class);
    }

    public function backtestRuns(): HasMany
    {
        return $this->hasMany(BacktestRun::class);
    }

    public function scopeNotArchived($query)
    {
        return $query->whereNull('archived_at');
    }
}
