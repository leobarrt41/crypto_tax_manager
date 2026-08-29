<?php

namespace App\Services;

use App\Models\TradingLog;
use App\Models\TradingStrategy;

class TradingAuditLogger
{
    public function record(
        int $userId,
        string $eventType,
        string $message,
        string $severity = 'info',
        ?int $strategyId = null,
        array $payload = [],
        string $source = 'application',
    ): TradingLog {
        return TradingLog::create([
            'user_id' => $userId,
            'trading_strategy_id' => $strategyId,
            'event_type' => $eventType,
            'severity' => $severity,
            'message' => $message,
            'payload' => $this->maskSensitiveValues($payload),
            'source' => $source,
            'logged_at' => now(),
            'occurred_at' => now(),
        ]);
    }

    private function maskSensitiveValues(array $payload): array
    {
        $sensitiveKeys = [
            'api_key',
            'apikey',
            'secret',
            'secret_key',
            'signature',
            'authorization',
            'passphrase',
        ];

        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $payload[$key] = '[MASCARADO]';
                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->maskSensitiveValues($value);
            }
        }

        return $payload;
    }
}
