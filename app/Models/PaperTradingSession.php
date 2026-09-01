<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class PaperTradingSession extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'user_id',
        'trading_strategy_id',
        'trading_strategy_version_id',
        'strategy_version_number',
        'strategy_definition_hash',
        'exchange_id',
        'symbol',
        'timeframe',
        'initial_capital',
        'cash_balance',
        'position_quantity',
        'position_cost_basis',
        'realized_pnl',
        'total_fees',
        'allocation_pct',
        'fee_rate',
        'slippage_rate',
        'history_start_at',
        'last_evaluated_candle_open_time',
        'pending_signal',
        'status',
        'paused_at',
        'archived_at',
    ];

    protected $casts = [
        'history_start_at' => 'immutable_datetime',
        'last_evaluated_candle_open_time' => 'immutable_datetime',
        'paused_at' => 'immutable_datetime',
        'archived_at' => 'immutable_datetime',
        'pending_signal' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $session): void {
            $immutable = [
                'user_id', 'trading_strategy_id', 'trading_strategy_version_id',
                'strategy_version_number', 'strategy_definition_hash', 'exchange_id',
                'symbol', 'timeframe', 'initial_capital', 'allocation_pct',
                'fee_rate', 'slippage_rate', 'history_start_at',
            ];

            foreach ($immutable as $attribute) {
                if ($session->isDirty($attribute)) {
                    throw new LogicException('A configuração de uma sessão de paper trading é imutável; crie uma nova sessão para alterar premissas.');
                }
            }

            if ($session->getOriginal('status') === self::STATUS_ARCHIVED) {
                throw new LogicException('Sessões arquivadas de paper trading não podem ser alteradas.');
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

    public function cycles(): HasMany
    {
        return $this->hasMany(PaperTradingCycle::class)->latest('sequence');
    }

    public function trades(): HasMany
    {
        return $this->hasMany(PaperTradingTrade::class)->orderBy('signal_candle_open_time');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
