<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CryptoReportingRuleVersion extends Model
{
    protected $fillable = [
        'code',
        'obligation_name',
        'reporting_format',
        'effective_from',
        'effective_until',
        'monthly_threshold_brl',
        'threshold_comparison',
        'reporting_scope',
        'deadline_rule',
        'legacy_export_available',
        'configuration',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_until' => 'date',
        'monthly_threshold_brl' => 'decimal:2',
        'legacy_export_available' => 'boolean',
        'configuration' => 'array',
    ];

    public function scopeApplicableOn(Builder $query, string $date): Builder
    {
        return $query
            ->whereDate('effective_from', '<=', $date)
            ->where(function (Builder $query) use ($date) {
                $query->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $date);
            });
    }
}
