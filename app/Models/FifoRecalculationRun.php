<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FifoRecalculationRun extends Model
{
    protected $fillable = [
        'user_id',
        'fiscal_year',
        'algorithm_version',
        'status',
        'result',
        'failure_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'fiscal_year' => 'integer',
        'result' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
