<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Network extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'explorer_url',
    ];

    /**
     * Uma rede pode ter várias carteiras associadas.
     */
    public function wallets()
    {
        return $this->hasMany(Wallet::class);
    }
}
