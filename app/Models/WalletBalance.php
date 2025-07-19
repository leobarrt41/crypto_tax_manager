<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WalletBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'wallet_id',
        'asset',
        'available',
        'locked',
        'retrieved_at',
    ];

    /**
     * Relacionamento: este saldo pertence a uma carteira específica.
     */
    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * Relacionamento indireto com o usuário (via carteira).
     */
    public function user()
    {
        return $this->wallet->user();
    }

    /**
     * Retorna o saldo total (disponível + bloqueado).
     */
    public function getTotalAttribute()
    {
        return $this->available + $this->locked;
    }

    /**
     * Escopo para buscar saldo de um ativo específico.
     */
 public function scopeForUser($query, $userId)
{
    return $query->whereHas('wallet', function ($q) use ($userId) {
        $q->where('user_id', $userId);
    });
}
}

