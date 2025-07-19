<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'from_asset',
        'to_asset',
        'from_amount',
        'to_amount',
        'type',
        'operation',
        'price',
        'total_usdt',
        'total_brl',
        'txid',
        'reference',
        'date',
        'source_type',
        'source_id',
    ];

    protected $casts = [
        'date' => 'datetime',
        'from_amount' => 'decimal:10',
        'to_amount' => 'decimal:10',
        'price' => 'decimal:10',
        'total_usdt' => 'decimal:10',
        'total_brl' => 'decimal:10',
    ];

    /**
     * 🔗 Transação pertence a um usuário.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 🔗 Ativo que saiu (relacionado à tabela de ativos).
     */
    public function fromCryptoAsset()
    {
        return $this->belongsTo(CryptoAsset::class, 'from_asset', 'symbol');
    }

    /**
     * 🔗 Ativo que entrou (relacionado à tabela de ativos).
     */
    public function toCryptoAsset()
    {
        return $this->belongsTo(CryptoAsset::class, 'to_asset', 'symbol');
    }

    /**
     * 🔗 Origem da transação (wallet ou exchange).
     */
    public function source()
    {
        return $this->morphTo(); // Pode ser Wallet ou UserApiKey
    }

    /**
     * 🔗 Pedido associado de bot (opcional).
     */
    public function botOrder()
    {
        return $this->belongsTo(BotOrder::class);
    }
}

