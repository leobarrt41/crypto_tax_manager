<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FifoInventoryGap extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'user_id',
        'transaction_id',
        'asset',
        'required_quantity',
        'available_quantity',
        'missing_quantity',
        'occurred_at',
        'status',
        'reason',
        'source',
        'consumed_lots',
        'context',
        'resolved_at',
    ];

    protected $casts = [
        'required_quantity' => 'decimal:12',
        'available_quantity' => 'decimal:12',
        'missing_quantity' => 'decimal:12',
        'occurred_at' => 'datetime',
        'resolved_at' => 'datetime',
        'consumed_lots' => 'array',
        'context' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
