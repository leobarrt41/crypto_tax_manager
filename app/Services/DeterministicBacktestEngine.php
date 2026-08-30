<?php

namespace App\Services;

use App\Models\MarketCandle;
use App\Models\TradingStrategyVersion;
use App\Support\DecimalMath;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class DeterministicBacktestEngine
{
    public function __construct(
        private readonly DecimalMath $decimal,
        private readonly MarketCandleDatasetService $datasets,
        private readonly StrategySignalEvaluator $signals,
    ) {
    }

    /**
     * Executa somente uma simulação em memória. Não persiste, não chama HTTP e não cria ordens.
     *
     * @param array<int, MarketCandle|array<string, mixed>> $candles
     * @param array<string, mixed> $scenario
     * @return array<string, mixed>
     */
    public function run(
        TradingStrategyVersion $version,
        array $candles,
        array $scenario,
        string $datasetHash,
    ): array {
        $config = $this->normalizeScenario($scenario);
        $rows = $this->normalizeCandles($candles, $config);

        if ($datasetHash !== $this->datasets->hash($rows)) {
            throw new InvalidArgumentException('O hash informado não corresponde aos candles recebidos pelo backtest.');
        }

        $gaps = $this->datasets->detectGaps($rows, $config['timeframe']);
        if ($gaps !== []) {
            return $this->invalidDataResult($version, $config, $datasetHash, $rows, $gaps);
        }

        $actual = $this->initialState($config['initial_capital']);
        $gross = $this->initialState($config['initial_capital']);
        $slippageOnly = $this->initialState($config['initial_capital']);
        $trades = [];
        $warnings = [];
        $pending = null;
        $maximumDrawdown = '0.0000000000000000';
        $peakEquity = $config['initial_capital'];
        $exposedCandles = 0;
        $insufficientSignalData = false;

        foreach ($rows as $index => $candle) {
            if ($pending !== null) {
                $trade = $this->execute(
                    $actual,
                    $pending['decision'],
                    $candle['open'],
                    $config['allocation_pct'],
                    $config['fee_rate'],
                    $config['slippage_rate'],
                    $pending,
                    false,
                    $candle['open_time'],
                );
                $this->execute($gross, $pending['decision'], $candle['open'], $config['allocation_pct'], '0', '0', $pending, false, $candle['open_time']);
                $this->execute($slippageOnly, $pending['decision'], $candle['open'], $config['allocation_pct'], '0', $config['slippage_rate'], $pending, false, $candle['open_time']);

                if ($trade !== null) {
                    $trades[] = $trade;
                }
                $pending = null;
            }

            if ($actual['quantity'] !== '0.0000000000000000') {
                $exposedCandles++;
            }

            $equity = $this->equity($actual, $candle['close']);
            if ($this->decimal->compare($equity, $peakEquity) > 0) {
                $peakEquity = $equity;
            }
            $drawdown = $this->decimal->percent($this->decimal->subtract($peakEquity, $equity), $peakEquity);
            if ($this->decimal->compare($drawdown, $maximumDrawdown) > 0) {
                $maximumDrawdown = $drawdown;
            }

            $evaluation = $this->signals->evaluate($version, array_slice($rows, 0, $index + 1));
            if ($evaluation['data_status'] !== 'complete') {
                $insufficientSignalData = true;
                continue;
            }

            if ($index === array_key_last($rows) && $evaluation['decision'] !== 'hold') {
                $warnings[] = 'O último sinal não foi preenchido porque não existe candle N+1 no dataset.';
                continue;
            }

            if ($evaluation['decision'] === 'entry' && $actual['quantity'] === '0.0000000000000000') {
                $pending = [
                    'decision' => 'entry',
                    'signal_candle_open_time' => $candle['open_time'],
                    'reason' => $evaluation['reason'],
                    'condition_results' => $evaluation['condition_results'],
                ];
            }

            if ($evaluation['decision'] === 'exit' && $actual['quantity'] !== '0.0000000000000000') {
                $pending = [
                    'decision' => 'exit',
                    'signal_candle_open_time' => $candle['open_time'],
                    'reason' => $evaluation['reason'],
                    'condition_results' => $evaluation['condition_results'],
                ];
            }
        }

        $last = $rows[array_key_last($rows)];
        if ($actual['quantity'] !== '0.0000000000000000' && $config['close_open_position_at_end']) {
            $forced = [
                'decision' => 'exit',
                'signal_candle_open_time' => $last['open_time'],
                'reason' => 'Liquidação explícita da posição aberta no fechamento do último candle do período.',
                'condition_results' => [],
            ];
            $trade = $this->execute($actual, 'exit', $last['close'], '100', $config['fee_rate'], $config['slippage_rate'], $forced, true, $last['open_time']);
            $this->execute($gross, 'exit', $last['close'], '100', '0', '0', $forced, true, $last['open_time']);
            $this->execute($slippageOnly, 'exit', $last['close'], '100', '0', $config['slippage_rate'], $forced, true, $last['open_time']);
            if ($trade !== null) {
                $trades[] = $trade;
            }
            $warnings[] = 'A posição aberta foi liquidada no fechamento do último candle conforme configuração do cenário.';
        }

        if ($insufficientSignalData) {
            $warnings[] = 'Parte do período não possuía candles suficientes para avaliar todos os indicadores; nenhum fill foi criado nesses pontos.';
        }

        $finalEquity = $this->equity($actual, $last['close']);
        $grossFinalEquity = $this->equity($gross, $last['close']);
        $slippageFinalEquity = $this->equity($slippageOnly, $last['close']);
        $buyAndHold = $this->buyAndHold($rows, $config);
        $closedTrades = array_values(array_filter($trades, fn (array $trade) => $trade['event_type'] === 'exit'));
        $winningTrades = array_values(array_filter($closedTrades, fn (array $trade) => $trade['realized_pnl'] !== null && $this->decimal->compare($trade['realized_pnl'], '0') > 0));

        return [
            'status' => 'completed',
            'dataset_hash' => $datasetHash,
            'simulation_config' => $config,
            'trades' => $trades,
            'warnings' => array_values(array_unique($warnings)),
            'metrics' => [
                'initial_capital' => $config['initial_capital'],
                'final_equity' => $finalEquity,
                'gross_return' => $this->decimal->subtract($grossFinalEquity, $config['initial_capital']),
                'net_return' => $this->decimal->subtract($finalEquity, $config['initial_capital']),
                'return_percentage' => $this->decimal->percent($this->decimal->subtract($finalEquity, $config['initial_capital']), $config['initial_capital']),
                'realized_pnl' => $actual['realized_pnl'],
                'unrealized_pnl' => $actual['quantity'] === '0.0000000000000000'
                    ? '0.0000000000000000'
                    : $this->decimal->subtract($this->decimal->multiply($actual['quantity'], $last['close']), $actual['cost_basis']),
                'total_fees' => $actual['total_fees'],
                'estimated_slippage_cost' => $this->decimal->subtract($grossFinalEquity, $slippageFinalEquity),
                'estimated_fee_cost' => $this->decimal->subtract($slippageFinalEquity, $finalEquity),
                'entries_count' => count(array_filter($trades, fn (array $trade) => $trade['event_type'] === 'entry')),
                'exits_count' => count($closedTrades),
                'closed_trades_count' => count($closedTrades),
                'win_rate_percentage' => $closedTrades === [] ? '0.0000000000000000' : $this->decimal->percent((string) count($winningTrades), (string) count($closedTrades)),
                'max_drawdown_percentage' => $maximumDrawdown,
                'exposure_percentage' => $this->decimal->percent((string) $exposedCandles, (string) count($rows)),
                'open_position_at_end' => $actual['quantity'] !== '0.0000000000000000',
                'candles_used' => count($rows),
                'dataset_start_at' => $rows[0]['open_time'],
                'dataset_end_at' => $last['close_time'],
                'buy_and_hold' => $buyAndHold,
            ],
        ];
    }

    /** @param array<string, mixed> $scenario @return array<string, mixed> */
    private function normalizeScenario(array $scenario): array
    {
        foreach (['exchange_id', 'symbol', 'timeframe', 'initial_capital'] as $field) {
            if (! isset($scenario[$field]) || $scenario[$field] === '') {
                throw new InvalidArgumentException("O cenário de backtest exige {$field}.");
            }
        }

        $initialCapital = $this->decimal->normalize($scenario['initial_capital']);
        $allocation = $this->decimal->normalize($scenario['allocation_pct'] ?? '100');
        $feeRate = $this->decimal->normalize($scenario['fee_rate'] ?? '0');
        $slippageRate = $this->decimal->normalize($scenario['slippage_rate'] ?? '0');

        if ($this->decimal->compare($initialCapital, '0') <= 0
            || $this->decimal->compare($allocation, '0') <= 0
            || $this->decimal->compare($allocation, '100') > 0
            || $this->decimal->compare($feeRate, '0') < 0
            || $this->decimal->compare($feeRate, '100') > 0
            || $this->decimal->compare($slippageRate, '0') < 0
            || $this->decimal->compare($slippageRate, '100') > 0) {
            throw new InvalidArgumentException('Capital, alocação, taxa ou slippage estão fora dos limites permitidos.');
        }

        return [
            'exchange_id' => (int) $scenario['exchange_id'],
            'symbol' => MarketCandle::normalizeSymbol((string) $scenario['symbol']),
            'timeframe' => MarketCandle::normalizeTimeframe((string) $scenario['timeframe']),
            'initial_capital' => $initialCapital,
            'allocation_pct' => $allocation,
            'fee_rate' => $feeRate,
            'slippage_rate' => $slippageRate,
            'close_open_position_at_end' => (bool) ($scenario['close_open_position_at_end'] ?? false),
            'evaluation_time' => (isset($scenario['evaluation_time'])
                ? CarbonImmutable::parse($scenario['evaluation_time'], 'UTC')->utc()
                : now('UTC')->toImmutable())->toIso8601String(),
            'fill_rule' => 'next_candle_open',
            'position_mode' => 'long_only_spot',
        ];
    }

    /**
     * @param array<int, MarketCandle|array<string, mixed>> $candles
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCandles(array $candles, array $config): array
    {
        if ($candles === []) {
            throw new InvalidArgumentException('Não é possível executar backtest sem candles.');
        }

        $sourceCandles = array_values($candles);
        $rows = $this->datasets->signalCandles($sourceCandles);
        $previousOpenTime = null;

        foreach ($rows as $index => &$row) {
            $sourceCandle = $sourceCandles[$index];
            $sourceIsOpen = is_array($sourceCandle) && (($sourceCandle['is_closed'] ?? true) === false);
            if ($sourceIsOpen || ! $this->datasets->isClosed($row, CarbonImmutable::parse($config['evaluation_time'], 'UTC')->utc())) {
                throw new InvalidArgumentException('Backtest aceita somente candles fechados.');
            }

            $openTime = CarbonImmutable::parse($row['open_time'], 'UTC')->utc();
            if ($previousOpenTime !== null && $openTime->lessThanOrEqualTo($previousOpenTime)) {
                throw new InvalidArgumentException('Candles devem estar ordenados por abertura crescente e sem duplicidade.');
            }

            $row['exchange_id'] = $sourceCandle instanceof MarketCandle ? $sourceCandle->exchange_id : (int) $sourceCandle['exchange_id'];
            $row['symbol'] = $sourceCandle instanceof MarketCandle ? $sourceCandle->symbol : $sourceCandle['symbol'];
            $row['timeframe'] = $sourceCandle instanceof MarketCandle ? $sourceCandle->timeframe : $sourceCandle['timeframe'];
            $row['source'] = $sourceCandle instanceof MarketCandle ? $sourceCandle->source : $sourceCandle['source'];

            if ((int) $row['exchange_id'] !== $config['exchange_id']
                || MarketCandle::normalizeSymbol($row['symbol']) !== $config['symbol']
                || MarketCandle::normalizeTimeframe($row['timeframe']) !== $config['timeframe']) {
                throw new InvalidArgumentException('Todos os candles devem corresponder à exchange, símbolo e timeframe do cenário.');
            }

            $previousOpenTime = $openTime;
        }
        unset($row);

        return $rows;
    }

    /** @return array{cash:string,quantity:string,cost_basis:string,realized_pnl:string,total_fees:string} */
    private function initialState(string $capital): array
    {
        return [
            'cash' => $capital,
            'quantity' => '0.0000000000000000',
            'cost_basis' => '0.0000000000000000',
            'realized_pnl' => '0.0000000000000000',
            'total_fees' => '0.0000000000000000',
        ];
    }

    /**
     * @param array{cash:string,quantity:string,cost_basis:string,realized_pnl:string,total_fees:string} $state
     * @param array<string, mixed> $signal
     * @return array<string, mixed>|null
     */
    private function execute(
        array &$state,
        string $decision,
        string $marketPrice,
        string $allocationPct,
        string $feeRate,
        string $slippageRate,
        array $signal,
        bool $forcedAtEnd = false,
        ?string $forcedFillOpenTime = null,
    ): ?array {
        if ($decision === 'entry' && $state['quantity'] !== '0.0000000000000000') {
            return null;
        }
        if ($decision === 'exit' && $state['quantity'] === '0.0000000000000000') {
            return null;
        }

        $cashBefore = $state['cash'];
        $rateFraction = $this->decimal->divide($feeRate, '100');
        $slippageFraction = $this->decimal->divide($slippageRate, '100');
        $slippageMultiplier = $decision === 'entry'
            ? $this->decimal->add('1', $slippageFraction)
            : $this->decimal->subtract('1', $slippageFraction);
        $fillPrice = $this->decimal->multiply($marketPrice, $slippageMultiplier);

        if ($decision === 'entry') {
            $budget = $this->decimal->multiply($state['cash'], $this->decimal->divide($allocationPct, '100'));
            $grossValue = $this->decimal->divide($budget, $this->decimal->add('1', $rateFraction));
            $feeAmount = $this->decimal->multiply($grossValue, $rateFraction);
            $quantity = $this->decimal->divide($grossValue, $fillPrice);
            $state['cash'] = $this->decimal->subtract($state['cash'], $this->decimal->add($grossValue, $feeAmount));
            $state['quantity'] = $quantity;
            $state['cost_basis'] = $this->decimal->add($grossValue, $feeAmount);
            $state['total_fees'] = $this->decimal->add($state['total_fees'], $feeAmount);

            return $this->tradePayload('entry', 'buy', $signal, $forcedFillOpenTime, $fillPrice, $quantity, $grossValue, $feeAmount, $feeRate, $slippageRate, $cashBefore, $state['cash'], null, $forcedAtEnd);
        }

        $quantity = $state['quantity'];
        $grossValue = $this->decimal->multiply($quantity, $fillPrice);
        $feeAmount = $this->decimal->multiply($grossValue, $rateFraction);
        $netProceeds = $this->decimal->subtract($grossValue, $feeAmount);
        $realizedPnl = $this->decimal->subtract($netProceeds, $state['cost_basis']);
        $state['cash'] = $this->decimal->add($state['cash'], $netProceeds);
        $state['quantity'] = '0.0000000000000000';
        $state['cost_basis'] = '0.0000000000000000';
        $state['realized_pnl'] = $this->decimal->add($state['realized_pnl'], $realizedPnl);
        $state['total_fees'] = $this->decimal->add($state['total_fees'], $feeAmount);

        return $this->tradePayload('exit', 'sell', $signal, $forcedFillOpenTime, $fillPrice, $quantity, $grossValue, $feeAmount, $feeRate, $slippageRate, $cashBefore, $state['cash'], $realizedPnl, $forcedAtEnd);
    }

    /**
     * @param array<string, mixed> $signal
     * @return array<string, mixed>
     */
    private function tradePayload(
        string $eventType,
        string $side,
        array $signal,
        ?string $forcedFillOpenTime,
        string $fillPrice,
        string $quantity,
        string $grossValue,
        string $feeAmount,
        string $feeRate,
        string $slippageRate,
        string $cashBefore,
        string $cashAfter,
        ?string $realizedPnl,
        bool $forcedAtEnd,
    ): array {
        return [
            'event_type' => $eventType,
            'side' => $side,
            'signal_candle_open_time' => $signal['signal_candle_open_time'],
            'fill_candle_open_time' => $forcedFillOpenTime ?? null,
            'fill_rule' => $forcedAtEnd ? 'last_candle_close' : 'next_candle_open',
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

    /** @param array{cash:string,quantity:string,cost_basis:string,realized_pnl:string,total_fees:string} $state */
    private function equity(array $state, string $lastClose): string
    {
        return $this->decimal->add($state['cash'], $this->decimal->multiply($state['quantity'], $lastClose));
    }

    /** @param array<int, array<string, mixed>> $candles @param array<string, mixed> $config @return array<string, mixed> */
    private function buyAndHold(array $candles, array $config): array
    {
        $state = $this->initialState($config['initial_capital']);
        $first = $candles[0];
        $last = $candles[array_key_last($candles)];
        $signal = [
            'signal_candle_open_time' => $first['open_time'],
            'reason' => 'Referência buy-and-hold com o mesmo capital, custos e slippage declarados.',
            'condition_results' => [],
        ];
        $this->execute($state, 'entry', $first['open'], '100', $config['fee_rate'], $config['slippage_rate'], $signal);

        if ($config['close_open_position_at_end']) {
            $this->execute($state, 'exit', $last['close'], '100', $config['fee_rate'], $config['slippage_rate'], [
                'signal_candle_open_time' => $last['open_time'],
                'reason' => 'Liquidação de buy-and-hold no fechamento final conforme cenário.',
                'condition_results' => [],
            ], true, $last['open_time']);
        }

        $finalEquity = $this->equity($state, $last['close']);

        return [
            'final_equity' => $finalEquity,
            'net_return' => $this->decimal->subtract($finalEquity, $config['initial_capital']),
            'return_percentage' => $this->decimal->percent($this->decimal->subtract($finalEquity, $config['initial_capital']), $config['initial_capital']),
            'open_position_at_end' => $state['quantity'] !== '0.0000000000000000',
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array{expected_open_time:string, actual_open_time:string}> $gaps
     * @return array<string, mixed>
     */
    private function invalidDataResult(TradingStrategyVersion $version, array $config, string $datasetHash, array $rows, array $gaps): array
    {
        return [
            'status' => 'invalid_data',
            'dataset_hash' => $datasetHash,
            'simulation_config' => $config,
            'trades' => [],
            'warnings' => ['Foram detectadas lacunas dentro do intervalo solicitado; o backtest foi bloqueado sem interpolar dados.'],
            'data_gaps' => $gaps,
            'metrics' => [
                'candles_used' => count($rows),
                'dataset_start_at' => $rows[0]['open_time'],
                'dataset_end_at' => $rows[array_key_last($rows)]['close_time'],
                'strategy_version_id' => $version->id,
                'strategy_definition_hash' => $version->definition_hash,
            ],
        ];
    }
}
