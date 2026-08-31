<?php

namespace App\Services;

use App\Models\TradingStrategyVersion;
use InvalidArgumentException;

class StrategySignalEvaluator
{
    public function __construct(
        private readonly StrategyDefinitionValidator $validator,
        private readonly IndicatorCalculator $indicators,
    ) {
    }

    /**
     * Avaliador pontual preservado para prévias e para a semântica original da Fase 1.
     *
     * @param array<int, array<string, mixed>> $candles
     * @return array<string, mixed>
     */
    public function evaluate(TradingStrategyVersion $version, array $candles): array
    {
        $definition = $this->validator->validate($version->definition);
        $closedCandles = $this->indicators->closedCandles($candles);
        $baseResult = $this->baseResult($version, $closedCandles === [] ? null : $closedCandles[array_key_last($closedCandles)]['close_time'] ?? null);

        try {
            $entryResults = $this->evaluateConditions($definition['entry_conditions'], $closedCandles);
            $exitResults = $this->evaluateConditions($definition['exit_conditions'], $closedCandles);
        } catch (InvalidArgumentException $exception) {
            return $baseResult + [
                'decision' => 'hold',
                'condition_results' => ['entry' => [], 'exit' => []],
                'data_status' => 'insufficient_data',
                'reason' => $exception->getMessage(),
            ];
        }

        return $this->decisionResult($baseResult, $entryResults, $exitResults, $definition['logic']);
    }

    /**
     * Avalia uma série inteira após pré-calcular os indicadores apenas uma vez.
     * O método não executa operações, não consulta fontes externas e não persiste dados.
     *
     * @param array<int, array<string, mixed>> $candles
     * @return array<int, array<string, mixed>>
     */
    public function evaluateSeries(TradingStrategyVersion $version, array $candles): array
    {
        $definition = $this->validator->validate($version->definition);
        $closedCandles = $this->indicators->closedCandles($candles);
        if ($closedCandles === []) {
            return [];
        }

        try {
            $compiledEntry = $this->compileConditions($definition['entry_conditions'], $closedCandles);
            $compiledExit = $this->compileConditions($definition['exit_conditions'], $closedCandles);
        } catch (InvalidArgumentException $exception) {
            return array_map(function (array $candle) use ($version, $exception): array {
                return $this->baseResult($version, $candle['close_time'] ?? null) + [
                    'decision' => 'hold',
                    'condition_results' => ['entry' => [], 'exit' => []],
                    'data_status' => 'insufficient_data',
                    'reason' => $exception->getMessage(),
                ];
            }, $closedCandles);
        }

        $results = [];
        foreach ($closedCandles as $candleIndex => $candle) {
            $baseResult = $this->baseResult($version, $candle['close_time'] ?? null);

            try {
                $entryResults = $this->evaluateCompiledConditions($compiledEntry, $candleIndex, $candle);
                $exitResults = $this->evaluateCompiledConditions($compiledExit, $candleIndex, $candle);
            } catch (InvalidArgumentException $exception) {
                $results[] = $baseResult + [
                    'decision' => 'hold',
                    'condition_results' => ['entry' => [], 'exit' => []],
                    'data_status' => 'insufficient_data',
                    'reason' => $exception->getMessage(),
                ];

                continue;
            }

            $results[] = $this->decisionResult($baseResult, $entryResults, $exitResults, $definition['logic']);
        }

        return $results;
    }

    /**
     * @param array<int, array<string, mixed>> $conditions
     * @param array<int, array<string, mixed>> $candles
     * @return array<int, array<string, mixed>>
     */
    private function compileConditions(array $conditions, array $candles): array
    {
        $seriesCache = [];
        $bandsCache = [];
        $compiled = [];

        foreach ($conditions as $index => $condition) {
            $operator = $condition['operator'];
            $bands = null;
            $series = $this->seriesForCached($condition, $candles, $seriesCache);
            $comparisonSeries = null;

            if (in_array($operator, ['greater_than_indicator', 'less_than_indicator'], true)) {
                $comparisonSeries = $this->seriesForCached($condition['compare_with'], $candles, $seriesCache);
            }

            if ($operator === 'close_above_upper_band' || $operator === 'close_below_lower_band') {
                $bands = $this->bandsForCached($condition, $candles, $bandsCache);
            }

            $compiled[] = [
                'index' => $index,
                'condition' => $condition,
                'operator' => $operator,
                'series' => $series,
                'comparison_series' => $comparisonSeries,
                'bands' => $bands,
            ];
        }

        return $compiled;
    }

    /**
     * @param array<int, array<string, mixed>> $compiled
     * @param array<string, mixed> $candle
     * @return array<int, array<string, mixed>>
     */
    private function evaluateCompiledConditions(array $compiled, int $candleIndex, array $candle): array
    {
        $results = [];
        foreach ($compiled as $item) {
            $results[] = $this->evaluateCompiledCondition($item, $candleIndex, $candle);
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $candle
     * @return array<string, mixed>
     */
    private function evaluateCompiledCondition(array $item, int $candleIndex, array $candle): array
    {
        $condition = $item['condition'];
        $operator = $item['operator'];
        $series = $item['series'];
        $current = $series[$candleIndex] ?? null;
        $needsPrevious = in_array($operator, ['crosses_above', 'crosses_below'], true);
        $previous = $this->previousValue($series, $candleIndex);

        if ($current === null || ($needsPrevious && $previous === null)) {
            throw new InvalidArgumentException('Dados insuficientes para avaliar a última vela fechada e, quando aplicável, seu cruzamento anterior.');
        }

        $comparison = null;
        $result = false;
        if (in_array($operator, ['greater_than_indicator', 'less_than_indicator'], true)) {
            $comparison = $item['comparison_series'][$candleIndex] ?? null;
            if ($comparison === null) {
                throw new InvalidArgumentException('Dados insuficientes para comparar os indicadores configurados.');
            }
            $result = $operator === 'greater_than_indicator' ? $current > $comparison : $current < $comparison;
        } elseif ($operator === 'close_above_upper_band' || $operator === 'close_below_lower_band') {
            $close = (float) $candle['close'];
            $comparison = $operator === 'close_above_upper_band'
                ? ($item['bands']['upper'][$candleIndex] ?? null)
                : ($item['bands']['lower'][$candleIndex] ?? null);
            if ($comparison === null) {
                throw new InvalidArgumentException('Dados insuficientes para avaliar as bandas de Bollinger configuradas.');
            }
            $result = $operator === 'close_above_upper_band' ? $close > $comparison : $close < $comparison;
            $current = $close;
        } else {
            $numericValue = (float) ($condition['value'] ?? 0);
            $result = match ($operator) {
                'greater_than' => $current > $numericValue,
                'less_than' => $current < $numericValue,
                'greater_than_or_equal' => $current >= $numericValue,
                'less_than_or_equal' => $current <= $numericValue,
                'crosses_above' => $previous <= $numericValue && $current > $numericValue,
                'crosses_below' => $previous >= $numericValue && $current < $numericValue,
                default => false,
            };
            $comparison = $numericValue;
        }

        return [
            'index' => $item['index'],
            'indicator' => $condition['indicator'],
            'operator' => $operator,
            'result' => $result,
            'indicator_value' => round((float) $current, 12),
            'previous_value' => $previous === null ? null : round((float) $previous, 12),
            'comparison_value' => $comparison === null ? null : round((float) $comparison, 12),
            'reason' => $this->reason($condition['indicator'], $operator, $current, $comparison, $result),
        ];
    }

    /** @param array<int, array<string, mixed>> $conditions @param array<int, array<string, mixed>> $candles */
    private function evaluateConditions(array $conditions, array $candles): array
    {
        return array_map(
            fn (array $condition, int $index) => $this->evaluateCondition($condition, $index, $candles),
            $conditions,
            array_keys($conditions),
        );
    }

    /** @param array<int, array<string, mixed>> $results */
    private function matches(array $results, string $logic): bool
    {
        if ($results === []) {
            return false;
        }

        return $logic === 'all'
            ? array_reduce($results, fn (bool $carry, array $condition) => $carry && $condition['result'], true)
            : array_reduce($results, fn (bool $carry, array $condition) => $carry || $condition['result'], false);
    }

    /**
     * @param array<string, mixed> $condition
     * @param array<int, array<string, mixed>> $candles
     * @return array<string, mixed>
     */
    private function evaluateCondition(array $condition, int $index, array $candles): array
    {
        $series = $this->seriesFor($condition, $candles);
        $currentIndex = array_key_last($series);
        $current = $series[$currentIndex] ?? null;
        $previous = $this->previousValue($series, $currentIndex);
        $operator = $condition['operator'];

        if ($current === null || (in_array($operator, ['crosses_above', 'crosses_below'], true) && $previous === null)) {
            throw new InvalidArgumentException('Dados insuficientes para avaliar a última vela fechada e, quando aplicável, seu cruzamento anterior.');
        }

        $value = $condition['value'] ?? null;
        $comparison = null;
        $result = false;
        if (in_array($operator, ['greater_than_indicator', 'less_than_indicator'], true)) {
            $comparisonSeries = $this->seriesFor($condition['compare_with'], $candles);
            $comparison = $comparisonSeries[$currentIndex] ?? null;
            if ($comparison === null) {
                throw new InvalidArgumentException('Dados insuficientes para comparar os indicadores configurados.');
            }
            $result = $operator === 'greater_than_indicator' ? $current > $comparison : $current < $comparison;
        } elseif ($operator === 'close_above_upper_band' || $operator === 'close_below_lower_band') {
            $bands = $this->indicators->bollinger($candles, (int) $condition['parameters']['period'], (float) $condition['parameters']['std_dev']);
            $close = (float) $candles[array_key_last($candles)]['close'];
            $comparison = $operator === 'close_above_upper_band' ? $bands['upper'][$currentIndex] : $bands['lower'][$currentIndex];
            $result = $operator === 'close_above_upper_band' ? $close > $comparison : $close < $comparison;
            $current = $close;
        } else {
            $numericValue = (float) $value;
            $result = match ($operator) {
                'greater_than' => $current > $numericValue,
                'less_than' => $current < $numericValue,
                'greater_than_or_equal' => $current >= $numericValue,
                'less_than_or_equal' => $current <= $numericValue,
                'crosses_above' => $previous <= $numericValue && $current > $numericValue,
                'crosses_below' => $previous >= $numericValue && $current < $numericValue,
                default => false,
            };
            $comparison = $numericValue;
        }

        return [
            'index' => $index,
            'indicator' => $condition['indicator'],
            'operator' => $operator,
            'result' => $result,
            'indicator_value' => round((float) $current, 12),
            'previous_value' => $previous === null ? null : round((float) $previous, 12),
            'comparison_value' => $comparison === null ? null : round((float) $comparison, 12),
            'reason' => $this->reason($condition['indicator'], $operator, $current, $comparison, $result),
        ];
    }

    /** @param array<string, mixed> $definition @param array<int, array<string, mixed>> $candles @return array<int, float|null> */
    private function seriesFor(array $definition, array $candles): array
    {
        $parameters = $definition['parameters'] ?? [];

        return match ($definition['indicator']) {
            'rsi' => $this->indicators->rsi($candles, (int) $parameters['period']),
            'sma' => $this->indicators->sma($candles, (int) $parameters['period']),
            'ema' => $this->indicators->ema($candles, (int) $parameters['period']),
            'macd' => $this->macdSeries($candles, $parameters),
            'bollinger' => $this->bollingerSeries($candles, $parameters),
            'ma_cross' => $this->crossSeries($candles, $parameters),
            default => throw new InvalidArgumentException('Indicador não suportado para avaliação.'),
        };
    }

    /** @param array<string, mixed> $definition @param array<int, array<string, mixed>> $candles @param array<string, array<int, float|null>> $cache @return array<int, float|null> */
    private function seriesForCached(array $definition, array $candles, array &$cache): array
    {
        $key = $this->indicatorCacheKey($definition);

        return $cache[$key] ??= $this->seriesFor($definition, $candles);
    }

    /** @param array<string, mixed> $condition @param array<int, array<string, mixed>> $candles @param array<string, array{middle:array<int, float|null>,upper:array<int, float|null>,lower:array<int, float|null>}> $cache @return array{middle:array<int, float|null>,upper:array<int, float|null>,lower:array<int, float|null>} */
    private function bandsForCached(array $condition, array $candles, array &$cache): array
    {
        $key = $this->indicatorCacheKey($condition);

        return $cache[$key] ??= $this->indicators->bollinger($candles, (int) $condition['parameters']['period'], (float) $condition['parameters']['std_dev']);
    }

    /** @param array<string, mixed> $definition */
    private function indicatorCacheKey(array $definition): string
    {
        return json_encode(['indicator' => $definition['indicator'] ?? null, 'parameters' => $definition['parameters'] ?? []], JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $parameters @return array<int, float|null> */
    private function macdSeries(array $candles, array $parameters): array
    {
        $macd = $this->indicators->macd($candles, (int) $parameters['fast_period'], (int) $parameters['slow_period'], (int) $parameters['signal_period']);

        return array_map(
            fn ($lineValue, $signalValue) => $signalValue === null ? null : $lineValue,
            $macd['line'],
            $macd['signal'],
        );
    }

    /** @param array<string, mixed> $parameters @return array<int, float|null> */
    private function bollingerSeries(array $candles, array $parameters): array
    {
        $bands = $this->indicators->bollinger($candles, (int) $parameters['period'], (float) $parameters['std_dev']);

        return $bands['middle'];
    }

    /** @param array<string, mixed> $parameters @return array<int, float|null> */
    private function crossSeries(array $candles, array $parameters): array
    {
        $fast = $this->indicators->ema($candles, (int) $parameters['fast_period']);
        $slow = $this->indicators->ema($candles, (int) $parameters['slow_period']);

        return array_map(fn ($fastValue, $slowValue) => $fastValue !== null && $slowValue !== null ? $fastValue - $slowValue : null, $fast, $slow);
    }

    /** @param array<int, float|null> $series */
    private function previousValue(array $series, int $currentIndex): ?float
    {
        for ($index = $currentIndex - 1; $index >= 0; $index--) {
            if ($series[$index] !== null) {
                return (float) $series[$index];
            }
        }

        return null;
    }

    /** @param array<string, mixed>|null $candleCloseTime @return array<string, mixed> */
    private function baseResult(TradingStrategyVersion $version, mixed $candleCloseTime): array
    {
        return [
            'evaluated_at' => now()->toIso8601String(),
            'candle_close_time' => $candleCloseTime,
            'strategy_version_id' => $version->id,
            'strategy_version' => $version->version,
            'definition_hash' => $version->definition_hash,
        ];
    }

    /** @param array<string, mixed> $baseResult @param array<int, array<string, mixed>> $entryResults @param array<int, array<string, mixed>> $exitResults @return array<string, mixed> */
    private function decisionResult(array $baseResult, array $entryResults, array $exitResults, string $logic): array
    {
        $entryMatches = $this->matches($entryResults, $logic);
        $exitMatches = $this->matches($exitResults, $logic);
        $decision = $exitMatches ? 'exit' : ($entryMatches ? 'entry' : 'hold');

        return $baseResult + [
            'decision' => $decision,
            'condition_results' => ['entry' => $entryResults, 'exit' => $exitResults],
            'data_status' => 'complete',
            'reason' => match ($decision) {
                'entry' => 'As condições de entrada foram atendidas pela última vela fechada.',
                'exit' => 'As condições de saída foram atendidas pela última vela fechada.',
                default => 'Nenhum conjunto de condições foi atendido pela última vela fechada.',
            },
        ];
    }

    private function reason(string $indicator, string $operator, float $current, ?float $comparison, bool $result): string
    {
        $outcome = $result ? 'atendeu' : 'não atendeu';
        $right = $comparison === null ? 'a condição configurada' : number_format($comparison, 8, '.', '');

        return sprintf('%s=%s %s %s (%s).', strtoupper($indicator), number_format($current, 8, '.', ''), $outcome, $operator, $right);
    }
}
