<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class CryptoAsset extends Model
{
    use HasFactory;

    /**
     * Campos que podem ser preenchidos em massa.
     */
    protected $fillable = [
        'symbol',
        'name',
        'contract_address',
        'current_price_brl',
        'current_price_usd',
        'price_change_24h',
        'price_change_7d',
        'price_change_30d',
        'market_cap',
        'volume_24h',
        'circulating_supply',
        'total_supply',
        'max_supply',
        'logo_url',
        'description',
        'website',
        'blockchain',
        'social_links',
        'is_active',
        'is_stablecoin',
        'is_defi',
        'is_nft',
        'listed_at',
        'delisted_at',
        'price_updated_at',
        'market_data_updated_at',
    ];

    /**
     * Campos que devem ser convertidos para tipos específicos.
     */
    protected $casts = [
        'current_price_brl' => 'decimal:8',
        'current_price_usd' => 'decimal:8',
        'price_change_24h' => 'decimal:4',
        'price_change_7d' => 'decimal:4',
        'price_change_30d' => 'decimal:4',
        'market_cap' => 'decimal:2',
        'volume_24h' => 'decimal:2',
        'circulating_supply' => 'integer',
        'total_supply' => 'integer',
        'max_supply' => 'integer',
        'social_links' => 'array',
        'is_active' => 'boolean',
        'is_stablecoin' => 'boolean',
        'is_defi' => 'boolean',
        'is_nft' => 'boolean',
        'listed_at' => 'datetime',
        'delisted_at' => 'datetime',
        'price_updated_at' => 'datetime',
        'market_data_updated_at' => 'datetime',
    ];

    /**
     * Campos que devem ser ocultados na serialização.
     */
    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    /**
     * Relacionamentos
     */

    /**
     * Transações onde este ativo é o ativo de origem (from_asset)
     */
    public function fromTransactions()
    {
        return $this->hasMany(Transaction::class, 'from_asset', 'symbol');
    }

    /**
     * Transações onde este ativo é o ativo de destino (to_asset)
     */
    public function toTransactions()
    {
        return $this->hasMany(Transaction::class, 'to_asset', 'symbol');
    }

    /**
     * Todas as transações relacionadas a este ativo
     */
    public function transactions()
    {
        return $this->fromTransactions()->union($this->toTransactions());
    }

    /**
     * Saldos de carteiras para este ativo
     */
    public function walletBalances()
    {
        return $this->hasMany(WalletBalance::class, 'crypto_asset_symbol', 'symbol');
    }

    /**
     * Scopes
     */

    /**
     * Scope para ativos ativos
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope para stablecoins
     */
    public function scopeStablecoins(Builder $query): Builder
    {
        return $query->where('is_stablecoin', true);
    }

    /**
     * Scope para tokens DeFi
     */
    public function scopeDefi(Builder $query): Builder
    {
        return $query->where('is_defi', true);
    }

    /**
     * Scope para ordenar por performance
     */
    public function scopeOrderByPerformance(Builder $query, string $period = '24h'): Builder
    {
        $column = match($period) {
            '7d' => 'price_change_7d',
            '30d' => 'price_change_30d',
            default => 'price_change_24h',
        };

        return $query->orderByDesc($column);
    }

    /**
     * Scope para ordenar por capitalização de mercado
     */
    public function scopeOrderByMarketCap(Builder $query): Builder
    {
        return $query->orderByDesc('market_cap');
    }

    /**
     * Métodos auxiliares
     */

    /**
     * Verifica se o preço está atualizado (últimas 5 minutos)
     */
    public function isPriceUpdated(): bool
    {
        if (!$this->price_updated_at) {
            return false;
        }

        return $this->price_updated_at->diffInMinutes(now()) <= 5;
    }

    /**
     * Verifica se os dados de mercado estão atualizados (última hora)
     */
    public function isMarketDataUpdated(): bool
    {
        if (!$this->market_data_updated_at) {
            return false;
        }

        return $this->market_data_updated_at->diffInHours(now()) <= 1;
    }

    /**
     * Retorna a URL do logo ou um placeholder
     */
    public function getLogoUrlAttribute($value): string
    {
        return $value ?: "https://cryptologos.cc/logos/{$this->symbol}-logo.png";
    }

    /**
     * Retorna a variação de preço formatada com sinal
     */
    public function getFormattedPriceChange(string $period = '24h'): string
    {
        $change = match($period) {
            '7d' => $this->price_change_7d,
            '30d' => $this->price_change_30d,
            default => $this->price_change_24h,
        };

        if ($change === null) {
            return 'N/A';
        }

        $sign = $change >= 0 ? '+' : '';
        return $sign . number_format($change, 2) . '%';
    }

    /**
     * Retorna se o ativo teve performance positiva
     */
    public function isPositivePerformance(string $period = '24h'): bool
    {
        $change = match($period) {
            '7d' => $this->price_change_7d,
            '30d' => $this->price_change_30d,
            default => $this->price_change_24h,
        };

        return $change !== null && $change > 0;
    }

    /**
     * Retorna o preço formatado em BRL
     */
    public function getFormattedPriceBrl(): string
    {
        if (!$this->current_price_brl) {
            return 'N/A';
        }

        return 'R$ ' . number_format($this->current_price_brl, 2, ',', '.');
    }

    /**
     * Retorna o preço formatado em USD
     */
    public function getFormattedPriceUsd(): string
    {
        if (!$this->current_price_usd) {
            return 'N/A';
        }

        return '$' . number_format($this->current_price_usd, 2, '.', ',');
    }

    /**
     * Retorna a capitalização de mercado formatada
     */
    public function getFormattedMarketCap(): string
    {
        if (!$this->market_cap) {
            return 'N/A';
        }

        if ($this->market_cap >= 1000000000) {
            return '$' . number_format($this->market_cap / 1000000000, 2) . 'B';
        } elseif ($this->market_cap >= 1000000) {
            return '$' . number_format($this->market_cap / 1000000, 2) . 'M';
        } else {
            return '$' . number_format($this->market_cap, 0);
        }
    }

    /**
     * Atualiza os dados de preço
     */
    public function updatePriceData(array $priceData): bool
    {
        $this->fill($priceData);
        $this->price_updated_at = now();
        
        return $this->save();
    }

    /**
     * Atualiza os dados de mercado
     */
    public function updateMarketData(array $marketData): bool
    {
        $this->fill($marketData);
        $this->market_data_updated_at = now();
        
        return $this->save();
    }
}
