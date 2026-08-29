<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ImportSession extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'type',
        'source',
        'filename',
        'file_path',
        'file_size',
        'status',
        'total_rows',
        'processed_rows',
        'successful_rows',
        'failed_rows',
        'errors',
        'settings',
        'started_at',
        'completed_at',
        'progress_percentage',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'total_rows' => 'integer',
        'processed_rows' => 'integer',
        'successful_rows' => 'integer',
        'failed_rows' => 'integer',
        'errors' => 'array',
        'settings' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'progress_percentage' => 'decimal:2',
    ];

    // Relacionamentos
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'import_session_id');
    }

    // Scopes
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeInProgress($query)
    {
        return $query->whereIn('status', ['pending', 'processing', 'pricing']);
    }

    // Métodos auxiliares
    public function start(): void
    {
        $this->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);
    }

    public function complete(): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'progress_percentage' => 100,
        ]);
    }

    public function fail(array $errors = []): void
    {
        $this->update([
            'status' => 'failed',
            'completed_at' => now(),
            'errors' => array_merge($this->errors ?? [], $errors),
        ]);
    }

    public function updateProgress(int $processedRows): void
    {
        $percentage = $this->total_rows > 0 
            ? ($processedRows / $this->total_rows) * 100 
            : 0;

        $this->update([
            'processed_rows' => $processedRows,
            'progress_percentage' => round($percentage, 2),
        ]);
    }

    public function incrementSuccessful(): void
    {
        $this->increment('successful_rows');
        $this->updateProgress($this->successful_rows + $this->failed_rows);
    }

    public function incrementFailed(string $error = null): void
    {
        $this->increment('failed_rows');
        
        if ($error) {
            $errors = $this->errors ?? [];
            $errors[] = $error;
            $this->update(['errors' => $errors]);
        }
        
        $this->updateProgress($this->successful_rows + $this->failed_rows);
    }

    public function getSuccessRateAttribute(): float
    {
        if ($this->processed_rows === 0) {
            return 0;
        }

        return round(($this->successful_rows / $this->processed_rows) * 100, 2);
    }

    public function getDurationAttribute(): ?string
    {
        if (!$this->started_at) {
            return null;
        }

        $end = $this->completed_at ?? now();
        $duration = $this->started_at->diffInSeconds($end);

        if ($duration < 60) {
            return $duration . 's';
        } elseif ($duration < 3600) {
            return round($duration / 60, 1) . 'min';
        } else {
            return round($duration / 3600, 1) . 'h';
        }
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isInProgress(): bool
    {
        return in_array($this->status, ['pending', 'processing', 'pricing']);
    }
}
