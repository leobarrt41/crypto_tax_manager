<?php

namespace App\Services;

use App\Models\Exchange;
use App\Models\MarketCandle;
use App\Models\PaperTradingCycle;
use App\Models\PaperTradingSession;
use App\Models\PaperTradingTrade;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use App\Support\DecimalMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ManualPaperTradingService
{
    public function __construct(
        private readonly MarketCandleIngestionService $ingestion,
        private readonly MarketCandleDatasetService $datasets,
        private readonly StrategySignalEvaluator $signals,
        private readonly DecimalMath $decimal,
        private readonly TradingAuditLogger $audit,
    ) {
    }

    /** @param array<string, mixed> $configuration */
    public function createSession(User $user, TradingStrategyVersion $version, Exchange $exchange, array $configuration): PaperTradingSession
    {
        $version->loadMissing('strategy');
        if ((int) $version->strategy->user_id !== (int) $user->id) {
            throw new InvalidArgumentException('A versão selecionada não pertence ao usuário autenticado.');
        }
        if ($version->strategy->archived_at !== null) {
            throw new InvalidArgumentException('Não é possível iniciar paper trading com uma estratégia arquivada.');
        }
        if (strtolower($exchange->name) !== 'binance') {
            throw new InvalidArgumentException('O paper trading manual inicial suporta somente candles públicos da Binance.');
        }

        $symbol = MarketCandle::normalizeSymbol((string) ($configuration['symbol'] ?? ''));
        $timeframe = MarketCandle::normalizeTimeframe((string) ($configuration['timeframe'] ?? ''));
        if (! in_array($symbol, ['BTCUSDT', 'ETHUSDT'], true)) {
            throw new InvalidArgumentException('O paper trading manual inicial suporta BTCUSDT e ETHUSDT.');
        }

        $initialCapital = $this->positiveDecimal($configuration['initial_capital'] ?? null, 'O capital inicial deve ser maior que zero.');
        $allocation = $this->percentage($configuration['allocation_pct'] ?? '100', 'A alocação deve estar entre 0 e 100.');
        $feeRate = $this->percentage($configuration['fee_rate'] ?? '0', 'A taxa deve estar entre 0 e 100.');
        $slippageRate = $this->percentage($configuration['slippage_rate'] ?? '0', 'O slippage deve estar entre 0 e 100.');
        $historyStart = isset($configuration['history_start_at'])
            ? CarbonImmutable::parse($configuration['history_start_at'], 'UTC')->utc()
            : now('UTC')->toImmutable()->subSeconds($this->warmupSeconds($timeframe));
        $now = now('UTC')->toImmutable();

        if ($historyStart->greaterThanOrEqualTo($now)) {
            throw new InvalidArgumentException('O início do histórico precisa ser anterior ao momento atual.');
        }
        if ($historyStart->lessThan($now->subDays(180))) {
            throw new InvalidArgumentException('O histórico de aquecimento não pode exceder 180 dias.');
        }

        $session = PaperTradingSession::query()->create([
            'user_id' => $user->id,
            'trading_strategy_id' => $version->trading_strategy_id,
            'trading_strategy_version_id' => $version->id,
            'strategy_version_number' => $version->version,
            'strategy_definition_hash' => $version->definition_hash,
            'exchange_id' => $exchange->id,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'initial_capital' => $initialCapital,
            'cash_balance' => $initialCapital,
            'position_quantity' => '0',
            'position_cost_basis' => '0',
            'realized_pnl' => '0',
            'total_fees' => '0',
            'allocation_pct' => $allocation,
            'fee_rate' => $feeRate,
            'slippage_rate' => $slippageRate,
            'history_start_at' => $historyStart,
            'status' => PaperTradingSession::STATUS_ACTIVE,
        ]);

        $this->audit->record(
            $user->id,
            'paper_trading_session_created',
            'Sessão de paper trading manual criada sem credenciais, saldo de exchange ou envio de ordens.',
            'info',
            $version->trading_strategy_id,
            [
                'paper_trading_session_id' => $session->id,
                'strategy_version_id' => $version->id,
                'strategy_definition_hash' => $version->definition_hash,
                'exchange_id' => $exchange->id,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'mode' => 'manual_paper_trading_only',
            ],
            'paper_trading',
        );

        return $session;
    }

    /**
     * Executa um ciclo manual sobre candles públicos fechados. Não despacha jobs, não cria ordens e não consulta conta.
     */
    public function runCycle(User $user, PaperTradingSession $session, CarbonImmutable|string|null $asOf = null): PaperTradingCycle
    {
        $session->loadMissing(['strategyVersion.strategy', 'exchange']);
        if ((int) $session->user_id !== (int) $user->id) {
            throw new InvalidArgumentException('A sessão selecionada não pertence ao usuário autenticado.');
        }

        $evaluationTime = $asOf === null
            ? now('UTC')->toImmutable()
            : CarbonImmutable::parse($asOf, 'UTC')->utc();
        if (! $session->isActive()) {
            return $this->persistInactiveCycle($session, $evaluationTime);
        }

        $market = $this->ingestion->cacheFirst(
            $session->exchange,
            $session->symbol,
            $session->timeframe,
            $session->history_start_at->toImmutable(),
            $evaluationTime,
            $evaluationTime,
        );

        return DB::transaction(function () use ($user, $session, $evaluationTime, $market): PaperTradingCycle {
            /** @var PaperTradingSession $locked */
            $locked = PaperTradingSession::query()->lockForUpdate()->findOrFail($session->id);
            $locked->loadMissing(['strategyVersion.strategy', 'exchange']);
            if ((int) $locked->user_id !== (int) $user->id) {
                throw new InvalidArgumentException('A sessão selecionada não pertence ao usuário autenticado.');
            }
            if (! $locked->isActive()) {
                return $this->persistInactiveCycle($locked, $evaluationTime);
            }

            $candles = $market['candles'];
            if ($candles === []) {
                return $this->persistCycle($locked, $evaluationTime, [], null, PaperTradingCycle::STATUS_INSUFFICIENT_DATA, null, [], [
                    'Não há candles fechados suficientes no cache para o ciclo manual.',
                ], $market);
            }
            if ($market['gaps'] !== []) {
                return $this->persistCycle($locked, $evaluationTime, $candles, null, PaperTradingCycle::STATUS_INVALID_DATA, null, [], [
                    'Foram detectadas lacunas nos candles; o ciclo foi bloqueado sem interpolar dados.',
                ], $market);
            }

            $evaluations = $this->signals->evaluateSeries($locked->strategyVersion, $this->signalCandles($candles));
            $processed = [];
            $lastEvaluation = $locked->last_evaluated_candle_open_time?->toImmutable();
            $lastCycleCursor = $locked->cycles()
                ->whereNotNull('processed_end_candle_open_time')
                ->max('processed_end_candle_open_time');
            if ($lastCycleCursor !== null) {
                $fromCycles = CarbonImmutable::parse($lastCycleCursor, 'UTC')->utc();
                if ($lastEvaluation === null || $fromCycles->greaterThan($lastEvaluation)) {
                    $lastEvaluation = $fromCycles;
                }
            }
            $activationTime = $locked->created_at->toImmutable();
            $lastEvaluationTimestamp = $lastEvaluation?->getTimestamp();
            $activationTimestamp = $activationTime->getTimestamp();
            $state = $this->stateFromSession($locked);
            $pending = $locked->pending_signal;
            $cycleTrades = [];
            $lastDecision = null;
            $lastSignal = [];
            $warnings = [];

            foreach ($candles as $index => $candle) {
                $openTime = $candle->open_time->toImmutable();
                if ($openTime->getTimestamp() <= $activationTimestamp
                    || ($lastEvaluationTimestamp !== null && $openTime->getTimestamp() <= $lastEvaluationTimestamp)) {
                    continue;
                }

                if ($pending !== null) {
                    $trade = $this->execute($state, $pending['decision'], (string) $candle->open, $locked, $pending, $openTime);
                    if ($trade !== null) {
                        $cycleTrades[] = $trade;
                    }
                    $pending = null;
                }

                $evaluation = $evaluations[$index] ?? null;
                if ($evaluation === null || $evaluation['data_status'] !== 'complete') {
                    $warnings[] = $evaluation['reason'] ?? 'Dados insuficientes para avaliar a estratégia neste candle.';
                    $processed[] = $candle;
                    continue;
                }

                $lastDecision = $evaluation['decision'];
                $lastSignal = $evaluation;
                if ($evaluation['decision'] === 'entry' && $this->decimal->isZero($state['quantity'])) {
                    $pending = $this->pendingSignal($evaluation, 'entry', $openTime);
                }
                if ($evaluation['decision'] === 'exit' && ! $this->decimal->isZero($state['quantity'])) {
                    $pending = $this->pendingSignal($evaluation, 'exit', $openTime);
                }
                $processed[] = $candle;
            }

            if ($processed === []) {
                return $this->persistCycle($locked, $evaluationTime, [], null, PaperTradingCycle::STATUS_COMPLETED, 'hold', [], [
                    'Não há novo candle fechado desde o último ciclo manual.',
                ], $market);
            }

            $cycle = $this->persistCycle($locked, $evaluationTime, $processed, $market['dataset_hash'], PaperTradingCycle::STATUS_COMPLETED, $lastDecision ?? 'hold', $lastSignal, array_values(array_unique($warnings)), $market);
            foreach ($cycleTrades as $trade) {
                $cycle->trades()->create($trade + [
                    'paper_trading_session_id' => $locked->id,
                ]);
            }

            $lastProcessed = $processed[array_key_last($processed)];
            $locked->fill([
                'cash_balance' => $state['cash'],
                'position_quantity' => $state['quantity'],
                'position_cost_basis' => $state['cost_basis'],
                'realized_pnl' => $state['realized_pnl'],
                'total_fees' => $state['total_fees'],
                'pending_signal' => $pending,
                'last_evaluated_candle_open_time' => $lastProcessed->open_time,
            ])->save();

            $this->audit->record(
                $user->id,
                'paper_trading_cycle_completed',
                'Ciclo manual de paper trading concluído sem envio de ordens.',
                'info',
                $locked->trading_strategy_id,
                [
                    'paper_trading_session_id' => $locked->id,
                    'paper_trading_cycle_id' => $cycle->id,
                    'dataset_hash' => $market['dataset_hash'],
                    'decision' => $lastDecision ?? 'hold',
                    'simulated_trades_count' => count($cycleTrades),
                    'mode' => 'manual_paper_trading_only',
                ],
                'paper_trading',
            );

            return $cycle->load('trades');
        });
    }

    public function pause(User $user, PaperTradingSession $session): PaperTradingSession
    {
        return $this->changeStatus($user, $session, PaperTradingSession::STATUS_PAUSED);
    }

    public function resume(User $user, PaperTradingSession $session): PaperTradingSession
    {
        return $this->changeStatus($user, $session, PaperTradingSession::STATUS_ACTIVE);
    }

    public function archive(User $user, PaperTradingSession $session): PaperTradingSession
    {
        return $this->changeStatus($user, $session, PaperTradingSession::STATUS_ARCHIVED);
    }

    private function changeStatus(User $user, PaperTradingSession $session, string $status): PaperTradingSession
    {
        if ((int) $session->user_id !== (int) $user->id) {
            throw new InvalidArgumentException('A sessão selecionada não pertence ao usuário autenticado.');
        }
        if ($session->status === PaperTradingSession::STATUS_ARCHIVED) {
            throw new InvalidArgumentException('Sessões arquivadas não podem ser reativadas ou pausadas.');
        }

        $session->fill([
            'status' => $status,
            'paused_at' => $status === PaperTradingSession::STATUS_PAUSED ? now('UTC')->toImmutable() : null,
            'archived_at' => $status === PaperTradingSession::STATUS_ARCHIVED ? now('UTC')->toImmutable() : null,
        ])->save();

        $this->audit->record(
            $user->id,
            "paper_trading_session_{$status}",
            "Sessão de paper trading marcada como {$status} sem qualquer operação em exchange.",
            'info',
            $session->trading_strategy_id,
            ['paper_trading_session_id' => $session->id, 'status' => $status, 'mode' => 'manual_paper_trading_only'],
            'paper_trading',
        );

        return $session->fresh();
    }

    /** @param array<int, MarketCandle> $candles @return array<int, array<string, mixed>> */
    private function signalCandles(array $candles): array
    {
        return array_map(fn (MarketCandle $candle): array => [
            'open' => (string) $candle->open,
            'high' => (string) $candle->high,
            'low' => (string) $candle->low,
            'close' => (string) $candle->close,
            'volume' => (string) $candle->volume,
            'open_time' => $candle->open_time->toIso8601String(),
            'close_time' => $candle->close_time->toIso8601String(),
            'is_closed' => true,
        ], $candles);
    }

    /** @return array{cash:string,quantity:string,cost_basis:string,realized_pnl:string,total_fees:string} */
    private function stateFromSession(PaperTradingSession $session): array
    {
        return [
            'cash' => $this->decimal->normalize($session->cash_balance),
            'quantity' => $this->decimal->normalize($session->position_quantity),
            'cost_basis' => $this->decimal->normalize($session->position_cost_basis),
            'realized_pnl' => $this->decimal->normalize($session->realized_pnl),
            'total_fees' => $this->decimal->normalize($session->total_fees),
        ];
    }

    /** @param array<string, mixed> $evaluation @return array<string, mixed> */
    private function pendingSignal(array $evaluation, string $decision, CarbonImmutable $signalOpenTime): array
    {
        return [
            'decision' => $decision,
            'signal_candle_open_time' => $signalOpenTime->toIso8601String(),
            'reason' => $evaluation['reason'],
            'condition_results' => $evaluation['condition_results'],
        ];
    }

    /** @param array{cash:string,quantity:string,cost_basis:string,realized_pnl:string,total_fees:string} $state @param array<string,mixed> $signal @return array<string,mixed>|null */
    private function execute(array &$state, string $decision, string $marketPrice, PaperTradingSession $session, array $signal, CarbonImmutable $fillOpenTime): ?array
    {
        if ($decision === 'entry' && ! $this->decimal->isZero($state['quantity'])) {
            return null;
        }
        if ($decision === 'exit' && $this->decimal->isZero($state['quantity'])) {
            return null;
        }

        $cashBefore = $state['cash'];
        $feeRate = $this->decimal->normalize($session->fee_rate);
        $slippageRate = $this->decimal->normalize($session->slippage_rate);
        $slippageFraction = $this->decimal->divide($slippageRate, '100');
        $fillPrice = $this->decimal->multiply($marketPrice, $decision === 'entry'
            ? $this->decimal->add('1', $slippageFraction)
            : $this->decimal->subtract('1', $slippageFraction));
        $feeFraction = $this->decimal->divide($feeRate, '100');

        if ($decision === 'entry') {
            $budget = $this->decimal->multiply($state['cash'], $this->decimal->divide((string) $session->allocation_pct, '100'));
            $grossValue = $this->decimal->divide($budget, $this->decimal->add('1', $feeFraction));
            $feeAmount = $this->decimal->multiply($grossValue, $feeFraction);
            $quantity = $this->decimal->divide($grossValue, $fillPrice);
            $state['cash'] = $this->decimal->subtract($state['cash'], $this->decimal->add($grossValue, $feeAmount));
            $state['quantity'] = $quantity;
            $state['cost_basis'] = $this->decimal->add($grossValue, $feeAmount);
            $state['total_fees'] = $this->decimal->add($state['total_fees'], $feeAmount);

            return $this->tradePayload('entry', 'buy', $signal, $fillOpenTime, $fillPrice, $quantity, $grossValue, $feeAmount, $feeRate, $slippageRate, $cashBefore, $state['cash']);
        }

        $quantity = $state['quantity'];
        $grossValue = $this->decimal->multiply($quantity, $fillPrice);
        $feeAmount = $this->decimal->multiply($grossValue, $feeFraction);
        $netProceeds = $this->decimal->subtract($grossValue, $feeAmount);
        $realizedPnl = $this->decimal->subtract($netProceeds, $state['cost_basis']);
        $state['cash'] = $this->decimal->add($state['cash'], $netProceeds);
        $state['quantity'] = '0';
        $state['cost_basis'] = '0';
        $state['realized_pnl'] = $this->decimal->add($state['realized_pnl'], $realizedPnl);
        $state['total_fees'] = $this->decimal->add($state['total_fees'], $feeAmount);

        return $this->tradePayload('exit', 'sell', $signal, $fillOpenTime, $fillPrice, $quantity, $grossValue, $feeAmount, $feeRate, $slippageRate, $cashBefore, $state['cash'], $realizedPnl);
    }

    /** @param array<string,mixed> $signal @return array<string,mixed> */
    private function tradePayload(string $eventType, string $side, array $signal, CarbonImmutable $fillOpenTime, string $fillPrice, string $quantity, string $grossValue, string $feeAmount, string $feeRate, string $slippageRate, string $cashBefore, string $cashAfter, ?string $realizedPnl = null): array
    {
        return [
            'event_type' => $eventType,
            'side' => $side,
            'signal_candle_open_time' => CarbonImmutable::parse($signal['signal_candle_open_time'], 'UTC')->utc(),
            'fill_candle_open_time' => $fillOpenTime,
            'fill_rule' => 'next_candle_open',
            'fill_price' => $fillPrice,
            'quantity' => $quantity,
            'gross_value' => $grossValue,
            'fee_amount' => $feeAmount,
            'fee_rate' => $feeRate,
            'slippage_rate' => $slippageRate,
            'cash_before' => $cashBefore,
            'cash_after' => $cashAfter,
            'realized_pnl' => $realizedPnl,
            'reason' => $signal['reason'],
            'condition_results' => $signal['condition_results'],
        ];
    }

    /** @param array<int, MarketCandle> $candles @param array<string,mixed> $signal @param array<int,string> $warnings @param array<string,mixed> $market */
    private function persistCycle(PaperTradingSession $session, CarbonImmutable $evaluationTime, array $candles, ?string $datasetHash, string $status, ?string $decision, array $signal, array $warnings, array $market): PaperTradingCycle
    {
        $first = $candles[0] ?? null;
        $last = $candles[array_key_last($candles)] ?? null;

        return $session->cycles()->create([
            'sequence' => ((int) $session->cycles()->max('sequence')) + 1,
            'started_at' => $evaluationTime,
            'finished_at' => now('UTC')->toImmutable(),
            'processed_start_candle_open_time' => $first?->open_time,
            'processed_end_candle_open_time' => $last?->open_time,
            'candles_processed' => count($candles),
            'dataset_hash' => $datasetHash,
            'status' => $status,
            'decision' => $decision,
            'signal_snapshot' => $signal === [] ? null : $signal,
            'source_metadata' => [
                'mode' => 'manual_paper_trading_only',
                'cache_hit' => $market['cache_hit'] ?? true,
                'fetched_count' => $market['fetched_count'] ?? 0,
                'source' => 'public_market_candles_cache',
            ],
            'warnings' => $warnings,
        ]);
    }

    private function persistInactiveCycle(PaperTradingSession $session, CarbonImmutable $evaluationTime): PaperTradingCycle
    {
        return $this->persistCycle($session, $evaluationTime, [], null, PaperTradingCycle::STATUS_PAUSED, null, [], [
            'A sessão está pausada ou arquivada; nenhum candle foi processado e nenhuma operação simulada foi criada.',
        ], []);
    }

    private function positiveDecimal(mixed $value, string $message): string
    {
        $value = $this->decimal->normalize($value ?? '0');
        if ($this->decimal->compare($value, '0') <= 0) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    private function percentage(mixed $value, string $message): string
    {
        $value = $this->decimal->normalize($value);
        if ($this->decimal->compare($value, '0') < 0 || $this->decimal->compare($value, '100') > 0) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    private function warmupSeconds(string $timeframe): int
    {
        return $timeframe === '1h' ? 500 * 3600 : 500 * 14400;
    }
}
