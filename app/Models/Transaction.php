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
        'pricing_status',
        'pricing_attempts',
        'pricing_last_attempted_at',
        'pricing_failure_reason',
        'fifo_status',
        'txid',
        'reference',
        'date',
        'source_type',
        'source_id',
        'symbol',
        'order_id',
        'trade_id',
        'qty',
        'quote_qty',
        'commission',
        'commission_asset',
        'commission_value_brl',
        'reconciliation_status',
        'quantity_status',
        'cost_status',
        'cost_evidence_type',
        'import_metadata',
        'side',
        'executed_at',
    ];

    protected $casts = [
        'date' => 'datetime',
        'from_amount' => 'decimal:10',
        'to_amount' => 'decimal:10',
        'price' => 'decimal:10',
        'total_usdt' => 'decimal:10',
        'total_brl' => 'decimal:10',
        'pricing_attempts' => 'integer',
        'pricing_last_attempted_at' => 'datetime',
        'qty' => 'decimal:12',
        'quote_qty' => 'decimal:12',
        'commission' => 'decimal:12',
        'commission_value_brl' => 'decimal:10',
        'import_metadata' => 'array',
        'executed_at' => 'datetime',
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

    /**
     * Pendência de histórico de aquisição aberta ou resolvida para esta saída.
     */
    public function fifoInventoryGap()
    {
        return $this->hasOne(FifoInventoryGap::class);
    }


    /**
     * Taxa da operação expressa no ativo enviado por uma unidade do ativo recebido.
     * Não representa, por si só, um preço em USD ou BRL.
     */
    public function getEffectiveConversionRateAttribute(): ?array
    {
        $fromAmount = (float) ($this->from_amount ?? 0);
        $toAmount = (float) ($this->to_amount ?? 0);

        if ($fromAmount <= 0 || $toAmount <= 0 || empty($this->from_asset) || empty($this->to_asset)) {
            return null;
        }

        return [
            'value' => $fromAmount / $toAmount,
            'base_asset' => $this->from_asset,
            'quoted_asset' => $this->to_asset,
        ];
    }

    public function getPriceInBRLAttribute()
{
    $price = $this->cryptoAsset->prices()
        ->whereDate('retrieved_at', '<=', $this->date)
        ->orderByDesc('retrieved_at')
        ->first();

    return $price ? $price->price_in_brl : null;
}

public function getTotalInBRLAttribute()
{
    return $this->price_in_brl ? $this->price_in_brl * $this->amount : null;
}


}

