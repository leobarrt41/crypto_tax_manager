<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class MarketCandle extends Model
{
    use HasFactory;

    public const SUPPORTED_TIMEFRAMES = ['1h', '4h'];

    protected $fillable = [
        'exchange_id',
        'symbol',
        'timeframe',
        'open_time',
        'close_time',
        'open',
        'high',
        'low',
        'close',
        'volume',
        'trade_count',
        'source',
        'fetched_at',
    ];

    protected $casts = [
        'open_time' => 'immutable_datetime',
        'close_time' => 'immutable_datetime',
        'fetched_at' => 'immutable_datetime',
        'open' => 'decimal:16',
        'high' => 'decimal:16',
        'low' => 'decimal:16',
        'close' => 'decimal:16',
        'volume' => 'decimal:16',
        'trade_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $candle): void {
            $candle->symbol = self::normalizeSymbol((string) $candle->symbol);
            $candle->timeframe = self::normalizeTimeframe((string) $candle->timeframe);
            $candle->open_time = self::utcTime($candle->open_time);
            $candle->close_time = self::utcTime($candle->close_time);
            $candle->fetched_at = self::utcTime($candle->fetched_at ?? now('UTC'));

            self::assertValidOhlcv(
                (string) $candle->open,
                (string) $candle->high,
                (string) $candle->low,
                (string) $candle->close,
                (string) $candle->volume,
                $candle->open_time,
                $candle->close_time,
            );
        });
    }

    public function exchange(): BelongsTo
    {
        return $this->belongsTo(Exchange::class);
    }

    public static function normalizeSymbol(string $symbol): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($symbol)) ?? '');

        if ($normalized === '') {
            throw new InvalidArgumentException('O símbolo do candle é obrigatório e deve conter letras ou números.');
        }

        return $normalized;
    }

    public static function normalizeTimeframe(string $timeframe): string
    {
        $normalized = strtolower(trim($timeframe));

        if (! in_array($normalized, self::SUPPORTED_TIMEFRAMES, true)) {
            throw new InvalidArgumentException('Timeframe de candle não suportado. Use 1h ou 4h.');
        }

        return $normalized;
    }

    private static function utcTime(mixed $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC')->utc();
    }

    private static function assertValidOhlcv(
        string $open,
        string $high,
        string $low,
        string $close,
        string $volume,
        CarbonImmutable $openTime,
        CarbonImmutable $closeTime,
    ): void {
        foreach ([$open, $high, $low, $close] as $price) {
            if (! self::isNonNegativeDecimal($price) || self::compareDecimals($price, '0') <= 0) {
                throw new InvalidArgumentException('Os preços OHLC devem ser positivos.');
            }
        }

        if (! self::isNonNegativeDecimal($volume) || self::compareDecimals($volume, '0') < 0) {
            throw new InvalidArgumentException('O volume do candle deve ser igual ou superior a zero.');
        }

        if (self::compareDecimals($high, $open) < 0
            || self::compareDecimals($high, $close) < 0
            || self::compareDecimals($high, $low) < 0
            || self::compareDecimals($low, $open) > 0
            || self::compareDecimals($low, $close) > 0
            || self::compareDecimals($low, $high) > 0) {
            throw new InvalidArgumentException('OHLC inválido: máximo e mínimo não abrangem abertura e fechamento.');
        }

        if ($closeTime->lessThanOrEqualTo($openTime)) {
            throw new InvalidArgumentException('O fechamento do candle deve ser posterior à abertura.');
        }
    }

    private static function isNonNegativeDecimal(string $value): bool
    {
        return preg_match('/^\d+(?:\.\d+)?$/', trim($value)) === 1;
    }

    private static function compareDecimals(string $left, string $right): int
    {
        [$leftInteger, $leftFraction] = array_pad(explode('.', trim($left), 2), 2, '');
        [$rightInteger, $rightFraction] = array_pad(explode('.', trim($right), 2), 2, '');

        $leftInteger = ltrim($leftInteger, '0') ?: '0';
        $rightInteger = ltrim($rightInteger, '0') ?: '0';

        if (strlen($leftInteger) !== strlen($rightInteger)) {
            return strlen($leftInteger) <=> strlen($rightInteger);
        }

        $integerComparison = strcmp($leftInteger, $rightInteger);
        if ($integerComparison !== 0) {
            return $integerComparison <=> 0;
        }

        $precision = max(strlen($leftFraction), strlen($rightFraction));

        return strcmp(
            str_pad($leftFraction, $precision, '0'),
            str_pad($rightFraction, $precision, '0'),
        ) <=> 0;
    }
}
