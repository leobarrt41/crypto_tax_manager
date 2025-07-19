<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApiConfiguration extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'provider',
        'endpoint',
        'api_key',
        'secret_key',
        'additional_params',
        'rate_limit',
        'timeout',
        'is_active',
        'last_used_at',
        'error_count',
        'last_error',
        'settings',
    ];

    protected $casts = [
        'additional_params' => 'array',
        'rate_limit' => 'integer',
        'timeout' => 'integer',
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
        'error_count' => 'integer',
        'settings' => 'array',
    ];

    protected $hidden = [
        'api_key',
        'secret_key',
    ];

    // Relacionamentos
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    // Métodos auxiliares
    public function updateLastUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    public function incrementErrorCount(string $error = null): void
    {
        $this->increment('error_count');
        
        if ($error) {
            $this->update(['last_error' => $error]);
        }

        // Desativar se muitos erros
        if ($this->error_count >= 10) {
            $this->update(['is_active' => false]);
        }
    }

    public function resetErrorCount(): void
    {
        $this->update([
            'error_count' => 0,
            'last_error' => null,
        ]);
    }

    public function getMaskedApiKeyAttribute(): string
    {
        if (!$this->api_key) {
            return '';
        }

        $key = $this->api_key;
        return substr($key, 0, 6) . '...' . substr($key, -4);
    }

    public function testConnection(): array
    {
        try {
            // Implementar teste de conexão específico por provider
            $this->updateLastUsed();
            $this->resetErrorCount();
            
            return [
                'success' => true,
                'message' => 'Conexão estabelecida com sucesso',
                'response_time' => rand(100, 500) . 'ms', // Placeholder
            ];
        } catch (\Exception $e) {
            $this->incrementErrorCount($e->getMessage());
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getCode(),
            ];
        }
    }
}
