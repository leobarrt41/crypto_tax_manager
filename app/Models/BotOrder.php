<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotOrder extends Model
{
    protected $fillable = [
        'user_id',
        'exchange_id',
        'trading_strategy_id',
        'symbol',
        'side',
        'quantity',
        'price',
        'status',
        'executed_at',
    ];

  public function transactions()
{
    return $this->hasMany(Transaction::class);
}

public function tradingLogs()
{
    return $this->hasMany(TradingLog::class);
}

public function tradingStrategy()
{
    return $this->belongsTo(TradingStrategy::class);
}




}

