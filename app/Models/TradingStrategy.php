<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TradingStrategy extends Model
{
    protected $fillable = [
        'name',
        'description',
        'parameters',
        'user_id',
    ];

    protected $casts = [
        'parameters' => 'array',
    ];

    public function user()
{
    return $this->belongsTo(User::class);
}

public function botOrders()
{
    return $this->hasMany(BotOrder::class);
}

}
