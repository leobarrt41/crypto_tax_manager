<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'cpf',
        'phone',
        'birth_date',
        'timezone',
        'language',
        'currency',
        'email_verified_at',
        'is_admin',
        'two_factor_enabled',
        'two_factor_secret',
        'avatar',
        'subscription_plan',
        'subscription_expires_at',
        'last_login_at',
        'last_login_ip',
        'preferences',
        'tax_settings',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'birth_date' => 'date',
        'subscription_expires_at' => 'datetime',
        'last_login_at' => 'datetime',
        'is_admin' => 'boolean',
        'two_factor_enabled' => 'boolean',
        'preferences' => 'array',
        'tax_settings' => 'array',
        'password' => 'hashed',
    ];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = [
        'deleted_at',
        'email_verified_at',
        'birth_date',
        'subscription_expires_at',
        'last_login_at',
    ];

    // ===== RELACIONAMENTOS (BASEADOS NOS MODELOS EXISTENTES) =====

    /**
     * Transações do usuário
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Carteiras do usuário
     */
    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    /**
     * Chaves de API das exchanges (UserApiKey)
     */
    public function userApiKeys(): HasMany
    {
        return $this->hasMany(UserApiKey::class);
    }

    /**
     * Estratégias de trading
     */
    public function tradingStrategies(): HasMany
    {
        return $this->hasMany(TradingStrategy::class);
    }

    public function createdTradingStrategyVersions(): HasMany
    {
        return $this->hasMany(TradingStrategyVersion::class, 'created_by');
    }

    public function exchangeKeys()
{
    return $this->hasMany(UserApiKey::class);
}

    /**
     * Ordens do bot de trading
     */
    public function botOrders(): HasMany
    {
        return $this->hasMany(BotOrder::class);
    }

    /**
     * Logs de trading
     */
    public function tradingLogs(): HasMany
    {
        return $this->hasMany(TradingLog::class);
    }

    /**
     * Regras fiscais personalizadas
     */
    public function taxRules(): HasMany
    {
        return $this->hasMany(TaxRule::class);
    }

    /**
     * Saldos das carteiras
     */
    public function walletBalances(): HasManyThrough
    {
        return $this->hasManyThrough(WalletBalance::class, Wallet::class);
    }

    // ===== RELACIONAMENTOS COM FILTROS =====

    /**
     * Transações ativas (não deletadas)
     */
    public function activeTransactions(): HasMany
    {
        return $this->transactions()->whereNull('deleted_at');
    }

    /**
     * Chaves de API ativas
     */
    public function activeApiKeys(): HasMany
    {
        return $this->userApiKeys()->where('status', 'active');
    }

    /**
     * Estratégias de trading ativas
     */
    public function activeTradingStrategies(): HasMany
    {
        return $this->tradingStrategies()->where('status', 'active');
    }

    /**
     * Ordens pendentes
     */
    public function pendingOrders(): HasMany
    {
        return $this->botOrders()->where('status', 'pending');
    }

    /**
     * Transações do mês atual
     */
    public function currentMonthTransactions(): HasMany
    {
        return $this->transactions()
            ->whereMonth('date', now()->month)
            ->whereYear('date', now()->year);
    }

    /**
     * Transações do ano atual
     */
    public function currentYearTransactions(): HasMany
    {
        return $this->transactions()
            ->whereYear('date', now()->year);
    }

    // ===== MÉTODOS AUXILIARES =====

    /**
     * Verificar se o usuário tem uma assinatura ativa
     */
    public function hasActiveSubscription(): bool
    {
        return $this->subscription_expires_at && 
               $this->subscription_expires_at->isFuture();
    }

    /**
     * Verificar se o usuário tem 2FA habilitado
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_enabled && !empty($this->two_factor_secret);
    }

    /**
     * Obter o nome completo ou primeiro nome
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->name ?? 'Usuário';
    }

    /**
     * Obter as iniciais do usuário
     */
    public function getInitialsAttribute(): string
    {
        $names = explode(' ', $this->name ?? 'U');
        $initials = '';
        
        foreach ($names as $name) {
            $initials .= strtoupper(substr($name, 0, 1));
        }
        
        return substr($initials, 0, 2);
    }

    /**
     * Verificar se o usuário é premium
     */
    public function isPremium(): bool
    {
        return in_array($this->subscription_plan, ['premium', 'enterprise']) && 
               $this->hasActiveSubscription();
    }

    /**
     * Obter o fuso horário do usuário
     */
    public function getTimezoneAttribute($value): string
    {
        return $value ?? 'America/Sao_Paulo';
    }

    /**
     * Obter a moeda preferida do usuário
     */
    public function getCurrencyAttribute($value): string
    {
        return $value ?? 'BRL';
    }

    /**
     * Obter o idioma preferido do usuário
     */
    public function getLanguageAttribute($value): string
    {
        return $value ?? 'pt-BR';
    }

    /**
     * Calcular o valor total do portfólio
     */
    public function getTotalPortfolioValue(): float
    {
        return $this->walletBalances()
            ->join('crypto_assets', 'wallet_balances.asset', '=', 'crypto_assets.symbol')
            ->selectRaw('SUM((wallet_balances.available + wallet_balances.locked) * crypto_assets.current_price_brl) as total')
            ->value('total') ?? 0;
    }

    /**
     * Calcular o P&L total
     */
    public function getTotalPnL(): float
    {
        return $this->transactions()
            ->whereNotNull('realized_gain')
            ->sum('realized_gain') ?? 0;
    }

    /**
     * Obter estatísticas do usuário
     */
    public function getStats(): array
    {
        return [
            'total_transactions' => $this->transactions()->count(),
            'total_portfolio_value' => $this->getTotalPortfolioValue(),
            'total_pnl' => $this->getTotalPnL(),
            'active_strategies' => $this->activeTradingStrategies()->count(),
            'connected_exchanges' => $this->activeApiKeys()->count(),
            'pending_orders' => $this->pendingOrders()->count(),
        ];
    }

    /**
     * Verificar se precisa declarar IN 1888
     */
    public function needsIN1888Declaration(int $month = null, int $year = null): bool
    {
        $month = $month ?? now()->month;
        $year = $year ?? now()->year;
        
        $monthlyVolume = $this->transactions()
            ->whereMonth('date', $month)
            ->whereYear('date', $year)
            ->sum('value_brl');
            
        return $monthlyVolume > 30000;
    }

    /**
     * Obter o volume mensal de transações
     */
    public function getMonthlyVolume(int $month = null, int $year = null): float
    {
        $month = $month ?? now()->month;
        $year = $year ?? now()->year;
        
        return $this->transactions()
            ->whereMonth('date', $month)
            ->whereYear('date', $year)
            ->sum('value_brl') ?? 0;
    }

    /**
     * Verificar se o usuário pode usar uma funcionalidade premium
     */
    public function canUsePremiumFeature(string $feature): bool
    {
        if (!$this->isPremium()) {
            return false;
        }
        
        $premiumFeatures = [
            'advanced_reports',
            'unlimited_strategies',
            'api_access',
            'priority_support',
            'custom_tax_rules',
            'bulk_import',
            'advanced_analytics',
        ];
        
        return in_array($feature, $premiumFeatures);
    }

    /**
     * Atualizar último login
     */
    public function updateLastLogin(string $ip = null): void
    {
        $this->update([
            'last_login_at' => now(),
            'last_login_ip' => $ip ?? request()->ip(),
        ]);
    }

    /**
     * Obter preferência específica
     */
    public function getPreference(string $key, $default = null)
    {
        return data_get($this->preferences, $key, $default);
    }

    /**
     * Definir preferência específica
     */
    public function setPreference(string $key, $value): void
    {
        $preferences = $this->preferences ?? [];
        data_set($preferences, $key, $value);
        $this->update(['preferences' => $preferences]);
    }

    /**
     * Obter configuração fiscal específica
     */
    public function getTaxSetting(string $key, $default = null)
    {
        return data_get($this->tax_settings, $key, $default);
    }

    /**
     * Definir configuração fiscal específica
     */
    public function setTaxSetting(string $key, $value): void
    {
        $taxSettings = $this->tax_settings ?? [];
        data_set($taxSettings, $key, $value);
        $this->update(['tax_settings' => $taxSettings]);
    }

    // ===== SCOPES =====

    /**
     * Scope para usuários ativos
     */
    public function scopeActive($query)
    {
        return $query->whereNull('deleted_at');
    }

    /**
     * Scope para usuários premium
     */
    public function scopePremium($query)
    {
        return $query->whereIn('subscription_plan', ['premium', 'enterprise'])
                    ->where('subscription_expires_at', '>', now());
    }

    /**
     * Scope para usuários verificados
     */
    public function scopeVerified($query)
    {
        return $query->whereNotNull('email_verified_at');
    }

    /**
     * Scope para usuários com 2FA
     */
    public function scopeWithTwoFactor($query)
    {
        return $query->where('two_factor_enabled', true);
    }
}
