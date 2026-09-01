<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class PaperTradingCycle extends Model
{
    use HasFactory;

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_INSUFFICIENT_DATA = 'insufficient_data';
    public const STATUS_INVALID_DATA = 'invalid_data';
    public const STATUS_PAUSED = 'paused';

    protected $fillable = [
        'paper_trading_session_id',
        'sequence',
        'started_at',
        'finished_at',
        'processed_start_candle_open_time',
        'processed_end_candle_open_time',
        'candles_processed',
        'dataset_hash',
        'status',
        'decision',
        'signal_snapshot',
        'source_metadata',
        'warnings',
    ];

    protected $casts = [
        'started_at' => 'immutable_datetime',
        'finished_at' => 'immutable_datetime',
        'processed_start_candle_open_time' => 'immutable_datetime',
        'processed_end_candle_open_time' => 'immutable_datetime',
        'signal_snapshot' => 'array',
        'source_metadata' => 'array',
        'warnings' => 'array',
        'candles_processed' => 'integer',
        'sequence' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Ciclos de paper trading são registros auditáveis e imutáveis.');
        });
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PaperTradingSession::class, 'paper_trading_session_id');
    }

    public function trades(): HasMany
    {
        return $this->hasMany(PaperTradingTrade::class)->orderBy('fill_candle_open_time');
    }
}
