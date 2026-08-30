<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class BacktestTrade extends Model
{
    use HasFactory;

    protected $fillable = [
        'backtest_run_id',
        'event_type',
        'signal_candle_open_time',
        'fill_candle_open_time',
        'side',
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
        'fill_rule',
    ];

    protected $casts = [
        'signal_candle_open_time' => 'immutable_datetime',
        'fill_candle_open_time' => 'immutable_datetime',
        'fill_price' => 'decimal:16',
        'quantity' => 'decimal:16',
        'gross_value' => 'decimal:16',
        'fee_amount' => 'decimal:16',
        'fee_rate' => 'decimal:8',
        'slippage_rate' => 'decimal:8',
        'cash_before' => 'decimal:16',
        'cash_after' => 'decimal:16',
        'realized_pnl' => 'decimal:16',
        'condition_results' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Operações simuladas de backtest são imutáveis.');
        });
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BacktestRun::class, 'backtest_run_id');
    }
}
