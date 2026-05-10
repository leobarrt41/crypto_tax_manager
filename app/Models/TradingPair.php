<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TradingPair extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol',
        'base_asset',
        'quote_asset',
        'status',
        'is_spot_trading_allowed',
        'is_margin_trading_allowed',
        'filters',
        'listed_at',
        'delisted_at',
    ];

    protected $casts = [
        'is_spot_trading_allowed' => 'boolean',
        'is_margin_trading_allowed' => 'boolean',
        'filters' => 'array',
        'listed_at' => 'datetime',
        'delisted_at' => 'datetime',
    ];
}
