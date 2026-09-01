<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PaperTradingTrade extends Model
{
    use HasFactory;

    protected $fillable = [
        'paper_trading_session_id',
        'paper_trading_cycle_id',
        'event_type',
        'side',
        'signal_candle_open_time',
        'fill_candle_open_time',
        'fill_rule',
        'fill_price',
        'quantity',
        'gross_value',
        'fee_amount',
        'fee_rate',
        'slippage_rate',
        'cash_before',
        'cash_after',
        'realized_pnl',
        'reason',
        'condition_results',
    ];

    protected $casts = [
        'signal_candle_open_time' => 'immutable_datetime',
        'fill_candle_open_time' => 'immutable_datetime',
        'condition_results' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Operações de paper trading são registros auditáveis e imutáveis.');
        });
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PaperTradingSession::class, 'paper_trading_session_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PaperTradingCycle::class, 'paper_trading_cycle_id');
    }
}
