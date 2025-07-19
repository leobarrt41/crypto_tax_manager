<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserApiKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'exchange_id',
        'api_key',
        'secret_key',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function exchange()
    {
        return $this->belongsTo(Exchange::class);
    }

    public function transactions()
{
    return $this->morphMany(Transaction::class, 'source');
}

}
