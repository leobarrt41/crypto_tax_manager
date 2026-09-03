<?php

namespace App\Support;

final class TransactionImportOrigin
{
    public const BINANCE_API = 'binance_api';

    public const BINANCE_ANNUAL_CSV = 'binance_annual_csv';

    public const MANUAL = 'manual';

    public const LEGACY_UNKNOWN = 'legacy_unknown';

    public static function inferLegacy(?string $sourceType, mixed $metadata): string
    {
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }
        $format = is_array($metadata) ? ($metadata['format'] ?? null) : null;

        return $format === self::BINANCE_ANNUAL_CSV
            ? self::BINANCE_ANNUAL_CSV
            : self::LEGACY_UNKNOWN;
    }
}
