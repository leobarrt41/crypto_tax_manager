<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionImportEvidence extends Model
{
    protected $table = 'transaction_import_evidences';

    protected $fillable = [
        'user_id',
        'transaction_id',
        'format',
        'source_reference',
        'content_hash',
        'evidence',
        'captured_at',
    ];

    protected $casts = [
        'evidence' => 'array',
        'captured_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
