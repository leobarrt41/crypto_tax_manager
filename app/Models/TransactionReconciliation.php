<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionReconciliation extends Model
{
    public const STATUS_CONFIRMED = 'confirmed';

    protected $fillable = [
        'user_id',
        'canonical_transaction_id',
        'matched_transaction_id',
        'match_type',
        'confidence',
        'fingerprint',
        'status',
        'matching_evidence',
        'reconciled_at',
    ];

    protected $casts = [
        'matching_evidence' => 'array',
        'reconciled_at' => 'datetime',
    ];

    public function canonicalTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'canonical_transaction_id');
    }

    public function matchedTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'matched_transaction_id');
    }
}
