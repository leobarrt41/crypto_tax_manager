<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Portfolio extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'total_value_brl',
        'total_value_usd',
        'total_invested',
        'total_pnl',
        'pnl_percentage',
        'last_updated_at',
        'is_active',
        'settings',
    ];

    protected $casts = [
        'total_value_brl' => 'decimal:2',
        'total_value_usd' => 'decimal:2',
        'total_invested' => 'decimal:2',
        'total_pnl' => 'decimal:2',
        'pnl_percentage' => 'decimal:4',
        'last_updated_at' => 'datetime',
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    // Relacionamentos
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(PortfolioSnapshot::class);
    }

    public function walletBalances(): HasMany
    {
        return $this->hasMany(WalletBalance::class);
    }

    // Métodos auxiliares
    public function updateTotalValue(): void
    {
        $totalValue = $this->walletBalances()
            ->join('crypto_assets', 'wallet_balances.crypto_asset_id', '=', 'crypto_assets.id')
            ->selectRaw('SUM(wallet_balances.balance * crypto_assets.current_price_brl) as total')
            ->value('total') ?? 0;

        $this->update([
            'total_value_brl' => $totalValue,
            'last_updated_at' => now(),
        ]);
    }

    public function createSnapshot(): PortfolioSnapshot
    {
        return $this->snapshots()->create([
            'total_value_brl' => $this->total_value_brl,
            'total_value_usd' => $this->total_value_usd,
            'total_pnl' => $this->total_pnl,
            'snapshot_date' => now(),
            'data' => $this->walletBalances()->with('cryptoAsset')->get()->toArray(),
        ]);
    }
}
