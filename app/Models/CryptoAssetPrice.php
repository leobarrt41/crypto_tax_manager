<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CryptoAssetPrice extends Model
{
    protected $fillable = [
        'symbol',
        'price_brl',
        'price_usdt',
        'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'price_brl' => 'decimal:10',
        'price_usdt' => 'decimal:10',
    ];

    public $timestamps = true;
}
