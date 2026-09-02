<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionReconciliation extends Model
{
    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REVOKED = 'revoked';

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
        'pending_review_at',
        'confirmed_at',
        'rejected_at',
        'revoked_at',
    ];

    protected $casts = [
        'matching_evidence' => 'array',
        'reconciled_at' => 'datetime',
        'pending_review_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'rejected_at' => 'datetime',
        'revoked_at' => 'datetime',
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
