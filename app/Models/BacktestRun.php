<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class BacktestRun extends Model
{
    use HasFactory;

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_INVALID_DATA = 'invalid_data';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'trading_strategy_id',
        'trading_strategy_version_id',
        'strategy_version_number',
        'strategy_definition_hash',
        'exchange_id',
        'symbol',
        'timeframe',
        'started_at',
        'finished_at',
        'requested_start_at',
        'requested_end_at',
        'dataset_start_at',
        'dataset_end_at',
        'dataset_hash',
        'candles_count',
        'source_metadata',
        'simulation_config',
        'status',
        'metrics',
        'warnings',
    ];

    protected $casts = [
        'started_at' => 'immutable_datetime',
        'finished_at' => 'immutable_datetime',
        'requested_start_at' => 'immutable_datetime',
        'requested_end_at' => 'immutable_datetime',
        'dataset_start_at' => 'immutable_datetime',
        'dataset_end_at' => 'immutable_datetime',
        'source_metadata' => 'array',
        'simulation_config' => 'array',
        'metrics' => 'array',
        'warnings' => 'array',
        'candles_count' => 'integer',
        'strategy_version_number' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $run): void {
            if ($run->isTerminal($run->getOriginal('status'))) {
                throw new LogicException('Backtests concluídos são imutáveis e não podem ser alterados.');
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'trading_strategy_id');
    }

    public function strategyVersion(): BelongsTo
    {
        return $this->belongsTo(TradingStrategyVersion::class, 'trading_strategy_version_id');
    }

    public function exchange(): BelongsTo
    {
        return $this->belongsTo(Exchange::class);
    }

    public function trades(): HasMany
    {
        return $this->hasMany(BacktestTrade::class)->orderBy('signal_candle_open_time');
    }

    public function isTerminal(?string $status = null): bool
    {
        return in_array($status ?? $this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_INVALID_DATA,
            self::STATUS_FAILED,
        ], true);
    }
}
