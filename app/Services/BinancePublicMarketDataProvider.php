<?php

namespace App\Services;

use App\Contracts\MarketDataProviderInterface;
use App\Models\MarketCandle;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use InvalidArgumentException;
use RuntimeException;

class BinancePublicMarketDataProvider implements MarketDataProviderInterface
{
    private const BASE_URL = 'https://api.binance.com/api/v3/klines';
    private const SUPPORTED_SYMBOLS = ['BTCUSDT', 'ETHUSDT'];
    private const PAGE_SIZE = 1000;
    private const MAX_RANGE_DAYS = 180;

    public function __construct(private readonly HttpFactory $http)
    {
    }

    /**
     * Busca somente candles públicos, paginados e normalizados. Não aceita credenciais.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchCandles(
        string $symbol,
        string $timeframe,
        CarbonImmutable $startAt,
        CarbonImmutable $endAt,
    ): array {
        $symbol = MarketCandle::normalizeSymbol($symbol);
        $timeframe = MarketCandle::normalizeTimeframe($timeframe);
        $startAt = $startAt->utc();
        $endAt = $endAt->utc();

        if (! in_array($symbol, self::SUPPORTED_SYMBOLS, true)) {
            throw new InvalidArgumentException('A fonte pública inicial suporta somente BTCUSDT e ETHUSDT.');
        }

        if ($endAt->lessThanOrEqualTo($startAt)) {
            throw new InvalidArgumentException('O fim da coleta deve ser posterior ao início.');
        }

        if ($startAt->diffInDays($endAt) > self::MAX_RANGE_DAYS) {
            throw new InvalidArgumentException('A coleta pública é limitada a 180 dias por solicitação.');
        }

        $candles = [];
        $cursor = $startAt;
        $duration = $timeframe === '1h' ? 3600 : 14400;

        while ($cursor->lessThan($endAt)) {
            $response = $this->http->acceptJson()
                ->timeout(15)
                ->retry(2, 250, throw: false)
                ->get(self::BASE_URL, [
                    'symbol' => $symbol,
                    'interval' => $timeframe,
                    'startTime' => $cursor->valueOf(),
                    'endTime' => $endAt->valueOf(),
                    'limit' => self::PAGE_SIZE,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('Não foi possível obter candles públicos da Binance neste momento.');
            }

            $rows = $response->json();
            if (! is_array($rows) || $rows === []) {
                break;
            }

            $page = array_map(function (array $row) use ($symbol, $timeframe): array {
                return [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'open_time' => CarbonImmutable::createFromTimestampMs((int) $row[0], 'UTC'),
                    'open' => (string) $row[1],
                    'high' => (string) $row[2],
                    'low' => (string) $row[3],
                    'close' => (string) $row[4],
                    'volume' => (string) $row[5],
                    'close_time' => CarbonImmutable::createFromTimestampMs((int) $row[6], 'UTC')->addMillisecond(),
                    'trade_count' => isset($row[8]) ? (int) $row[8] : null,
                    'source' => 'binance_public',
                    'fetched_at' => now('UTC')->toImmutable(),
                ];
            }, $rows);

            $candles = array_merge($candles, array_filter(
                $page,
                fn (array $candle) => $candle['close_time']->lessThanOrEqualTo($endAt)
                    && $candle['close_time']->lessThanOrEqualTo(now('UTC')),
            ));
            $last = $page[array_key_last($page)];
            $nextCursor = $last['open_time']->addSeconds($duration);

            if ($nextCursor->lessThanOrEqualTo($cursor)) {
                throw new RuntimeException('A paginação de candles públicos não avançou de forma segura.');
            }

            $cursor = $nextCursor;
            if (count($rows) < self::PAGE_SIZE) {
                break;
            }

            usleep(100000);
        }

        return $candles;
    }
}
