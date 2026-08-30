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
     * @param array<int, array<string, mixed>> $candles
     * @return array<string, mixed>
     */
    public function evaluate(TradingStrategyVersion $version, array $candles): array
    {
        $definition = $this->validator->validate($version->definition);
        $closedCandles = $this->indicators->closedCandles($candles);
        $baseResult = [
            'evaluated_at' => now()->toIso8601String(),
            'candle_close_time' => $closedCandles === [] ? null : ($closedCandles[array_key_last($closedCandles)]['close_time'] ?? null),
            'strategy_version_id' => $version->id,
            'strategy_version' => $version->version,
            'definition_hash' => $version->definition_hash,
        ];

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

        $entryMatches = $this->matches($entryResults, $definition['logic']);
        $exitMatches = $this->matches($exitResults, $definition['logic']);
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
            $bands = $this->indicators->bollinger(
                $candles,
                (int) $condition['parameters']['period'],
                (float) $condition['parameters']['std_dev'],
            );
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

    /**
     * @param array<string, mixed> $definition
     * @param array<int, array<string, mixed>> $candles
     * @return array<int, float|null>
     */
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

    /** @param array<string, mixed> $parameters @return array<int, float|null> */
    private function macdSeries(array $candles, array $parameters): array
    {
        $macd = $this->indicators->macd(
            $candles,
            (int) $parameters['fast_period'],
            (int) $parameters['slow_period'],
            (int) $parameters['signal_period'],
        );

        return $macd['line'];
    }

    /** @param array<string, mixed> $parameters @return array<int, float|null> */
    private function bollingerSeries(array $candles, array $parameters): array
    {
        $bands = $this->indicators->bollinger(
            $candles,
            (int) $parameters['period'],
            (float) $parameters['std_dev'],
        );

        return $bands['middle'];
    }

    /** @param array<string, mixed> $parameters @return array<int, float|null> */
    private function crossSeries(array $candles, array $parameters): array
    {
        $fast = $this->indicators->ema($candles, (int) $parameters['fast_period']);
        $slow = $this->indicators->ema($candles, (int) $parameters['slow_period']);

        return array_map(
            fn ($fastValue, $slowValue) => $fastValue !== null && $slowValue !== null ? $fastValue - $slowValue : null,
            $fast,
            $slow,
        );
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

    private function reason(string $indicator, string $operator, float $current, ?float $comparison, bool $result): string
    {
        $outcome = $result ? 'atendeu' : 'não atendeu';
        $right = $comparison === null ? 'a condição configurada' : number_format($comparison, 8, '.', '');

        return sprintf('%s=%s %s %s (%s).', strtoupper($indicator), number_format($current, 8, '.', ''), $outcome, $operator, $right);
    }
}
