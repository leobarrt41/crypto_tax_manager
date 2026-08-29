<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class UserApiKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'exchange_id',
        'api_key',
        'secret_key',
        'read_enabled',
        'trading_enabled',
        'trading_enabled_at',
    ];

    protected $casts = [
        'read_enabled' => 'boolean',
        'trading_enabled' => 'boolean',
        'trading_enabled_at' => 'datetime',
    ];

    protected function apiKey(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $this->decryptCredential($value),
            set: fn (?string $value) => $this->encryptCredential($value),
        );
    }

    protected function secretKey(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $this->decryptCredential($value),
            set: fn (?string $value) => $this->encryptCredential($value),
        );
    }

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

    private function encryptCredential(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if ($this->isEncrypted($value)) {
            return $value;
        }

        return Crypt::encryptString($value);
    }

    private function decryptCredential(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            // Compatibilidade temporária para registros legados antes da migração.
            return $value;
        }
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
