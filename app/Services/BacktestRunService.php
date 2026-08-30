<?php

namespace App\Services;

use App\Models\BacktestRun;
use App\Models\MarketCandle;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BacktestRunService
{
    public function __construct(
        private readonly DeterministicBacktestEngine $engine,
        private readonly MarketCandleDatasetService $datasets,
        private readonly TradingAuditLogger $audit,
    ) {
    }

    /**
     * Persiste um resultado terminal e imutável; não cria job, ordem, saldo ou transação fiscal.
     *
     * @param array<int, MarketCandle|array<string, mixed>> $candles
     * @param array<string, mixed> $scenario
     * @param array<string, mixed> $sourceMetadata
     */
    public function create(
        User $user,
        TradingStrategyVersion $version,
        array $candles,
        array $scenario,
        CarbonImmutable|string $requestedStartAt,
        CarbonImmutable|string $requestedEndAt,
        array $sourceMetadata = [],
    ): BacktestRun {
        $version->loadMissing('strategy');
        if ((int) $version->strategy->user_id !== (int) $user->id) {
            throw new InvalidArgumentException('A versão selecionada não pertence ao usuário autenticado.');
        }

        $startedAt = now('UTC')->toImmutable();
        $requestedStart = CarbonImmutable::parse($requestedStartAt, 'UTC')->utc();
        $requestedEnd = CarbonImmutable::parse($requestedEndAt, 'UTC')->utc();
        if ($requestedEnd->lessThanOrEqualTo($requestedStart)) {
            throw new InvalidArgumentException('O fim solicitado deve ser posterior ao início solicitado.');
        }

        $datasetHash = $this->datasets->hash($candles);
        $result = $candles === []
            ? $this->emptyDatasetResult($scenario, $datasetHash)
            : $this->engine->run($version, $candles, $scenario, $datasetHash);
        $config = $result['simulation_config'];
        $firstCandle = $candles === [] ? null : $this->firstCandle($candles);
        $lastCandle = $candles === [] ? null : $this->lastCandle($candles);
        $metadata = array_merge($sourceMetadata, [
            'sources' => $this->sources($candles),
            'dataset_type' => 'market_candles_cache',
        ]);

        $run = DB::transaction(function () use ($user, $version, $startedAt, $requestedStart, $requestedEnd, $datasetHash, $result, $config, $firstCandle, $lastCandle, $metadata, $candles): BacktestRun {
            $run = BacktestRun::query()->create([
                'user_id' => $user->id,
                'trading_strategy_id' => $version->trading_strategy_id,
                'trading_strategy_version_id' => $version->id,
                'strategy_version_number' => $version->version,
                'strategy_definition_hash' => $version->definition_hash,
                'exchange_id' => $config['exchange_id'],
                'symbol' => $config['symbol'],
                'timeframe' => $config['timeframe'],
                'started_at' => $startedAt,
                'finished_at' => now('UTC')->toImmutable(),
                'requested_start_at' => $requestedStart,
                'requested_end_at' => $requestedEnd,
                'dataset_start_at' => $firstCandle ? CarbonImmutable::parse($firstCandle['open_time'], 'UTC')->utc() : null,
                'dataset_end_at' => $lastCandle ? CarbonImmutable::parse($lastCandle['close_time'], 'UTC')->utc() : null,
                'dataset_hash' => $datasetHash,
                'candles_count' => count($candles),
                'source_metadata' => $metadata,
                'simulation_config' => $config,
                'status' => $result['status'],
                'metrics' => $result['metrics'],
                'warnings' => $result['warnings'],
            ]);

            foreach ($result['trades'] as $trade) {
                $run->trades()->create($trade);
            }

            return $run->load('trades');
        });

        $this->audit->record(
            $user->id,
            'backtest_completed',
            'Backtest histórico concluído sem envio de ordens.',
            $run->status === BacktestRun::STATUS_COMPLETED ? 'info' : 'warning',
            $version->trading_strategy_id,
            [
                'backtest_run_id' => $run->id,
                'status' => $run->status,
                'strategy_version_id' => $version->id,
                'strategy_definition_hash' => $version->definition_hash,
                'dataset_hash' => $datasetHash,
                'candles_count' => $run->candles_count,
                'simulation_mode' => 'historical_only',
            ],
            'backtest',
        );

        return $run;
    }

    /** @param array<int, MarketCandle|array<string, mixed>> $candles @return array<string, mixed> */
    private function emptyDatasetResult(array $scenario, string $datasetHash): array
    {
        foreach (['exchange_id', 'symbol', 'timeframe', 'initial_capital'] as $field) {
            if (! isset($scenario[$field]) || $scenario[$field] === '') {
                throw new InvalidArgumentException("O cenário de backtest exige {$field}.");
            }
        }

        return [
            'status' => BacktestRun::STATUS_INVALID_DATA,
            'dataset_hash' => $datasetHash,
            'simulation_config' => [
                'exchange_id' => (int) $scenario['exchange_id'],
                'symbol' => MarketCandle::normalizeSymbol((string) $scenario['symbol']),
                'timeframe' => MarketCandle::normalizeTimeframe((string) $scenario['timeframe']),
                'initial_capital' => trim((string) $scenario['initial_capital']),
                'allocation_pct' => trim((string) ($scenario['allocation_pct'] ?? '100')),
                'fee_rate' => trim((string) ($scenario['fee_rate'] ?? '0')),
                'slippage_rate' => trim((string) ($scenario['slippage_rate'] ?? '0')),
                'close_open_position_at_end' => (bool) ($scenario['close_open_position_at_end'] ?? false),
                'fill_rule' => 'next_candle_open',
                'position_mode' => 'long_only_spot',
            ],
            'trades' => [],
            'warnings' => ['Não há candles fechados suficientes no cache para o período solicitado.'],
            'metrics' => ['candles_used' => 0],
        ];
    }

    /** @param array<int, MarketCandle|array<string, mixed>> $candles @return array<string, mixed> */
    private function firstCandle(array $candles): array
    {
        return $this->candleToArray($candles[0]);
    }

    /** @param array<int, MarketCandle|array<string, mixed>> $candles @return array<string, mixed> */
    private function lastCandle(array $candles): array
    {
        return $this->candleToArray($candles[array_key_last($candles)]);
    }

    /** @param array<int, MarketCandle|array<string, mixed>> $candles @return array<int, string> */
    private function sources(array $candles): array
    {
        return collect($candles)
            ->map(fn (MarketCandle|array $candle) => $this->candleToArray($candle)['source'] ?? 'unknown')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function candleToArray(MarketCandle|array $candle): array
    {
        if (is_array($candle)) {
            return $candle;
        }

        return $candle->getAttributes() + [
            'open_time' => $candle->open_time->toIso8601String(),
            'close_time' => $candle->close_time->toIso8601String(),
        ];
    }
}
