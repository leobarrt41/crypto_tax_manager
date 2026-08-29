<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TradingLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'bot_order_id',
        'trading_strategy_id',
        'event_type',
        'severity',
        'message',
        'payload',
        'source',
        'logged_at',
        'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'logged_at' => 'datetime',
        'occurred_at' => 'datetime',
    ];

    public function tradingStrategy()
    {
        return $this->belongsTo(TradingStrategy::class);
    }

    public function botOrder()
    {
        return $this->belongsTo(BotOrder::class);
    }
}
