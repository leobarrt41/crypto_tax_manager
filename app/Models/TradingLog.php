<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TradingLog extends Model
{
    protected $fillable = [
        'bot_order_id',
        'log_type',
        'message',
        'timestamp',
    ];

    public $timestamps = false;
}
