<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionImportCoverage extends Model
{
    use HasFactory;

    public const EVENT_TYPES = [
        'spot_trade',
        'convert',
        'deposit',
        'withdrawal',
        'asset_dividend',
        'earn_staking',
        'fiat',
        'other',
    ];

    public const EVENT_LABELS = [
        'spot_trade' => 'Spot',
        'convert' => 'Convert',
        'deposit' => 'Depósitos',
        'withdrawal' => 'Saques',
        'asset_dividend' => 'Dividendos e distribuições',
        'earn_staking' => 'Earn e staking',
        'fiat' => 'Compra e venda em moeda fiduciária',
        'other' => 'Outras movimentações',
    ];

    protected $fillable = [
        'user_id',
        'exchange_id',
        'year',
        'month',
        'event_type',
        'api_status',
        'api_records_count',
        'api_checked_at',
        'api_error',
        'csv_status',
        'csv_records_count',
        'csv_filename',
        'csv_imported_at',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'api_records_count' => 'integer',
        'api_checked_at' => 'datetime',
        'csv_records_count' => 'integer',
        'csv_imported_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function exchange(): BelongsTo
    {
        return $this->belongsTo(Exchange::class);
    }

    public static function labelFor(string $eventType): string
    {
        return self::EVENT_LABELS[$eventType] ?? $eventType;
    }
}
