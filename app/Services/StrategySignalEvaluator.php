<?php

namespace App\Services;

use App\Models\TradingStrategyVersion;
use Illuminate\Validation\ValidationException;
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
    public function evaluate(TradingStrategyVersion $version, array $candles, string $decisionOnMatch = 'buy_signal'): array
    {
        if (!in_array($decisionOnMatch, ['buy_signal', 'sell_signal'], true)) {
            throw ValidationException::withMessages([
                'decision_on_match' => 'A decisão deve ser buy_signal ou sell_signal.',
            ]);
        }

        $definition = $this->validator->validate($version->definition);
        $closedCandles = $this->indicators->closedCandles($candles);
        $baseResult = [
            'evaluated_at' => now()->toIso8601String(),
            'candle_close_time' => $closedCandles === [] ? null : ($closedCandles[array_key_last($closedCandles)]['close_time'] ?? null),
            'strategy_version_id' => $version->id,
            'definition_hash' => $version->definition_hash,
        ];

        try {
            $conditionResults = array_map(
                fn (array $condition, int $index) => $this->evaluateCondition($condition, $index, $closedCandles),
                $definition['conditions'],
                array_keys($definition['conditions']),
            );
        } catch (InvalidArgumentException $exception) {
            return $baseResult + [
                'decision' => 'no_signal',
                'conditions' => [],
                'data_status' => 'insufficient_data',
                'reason' => $exception->getMessage(),
            ];
        }

        $matches = $definition['logic'] === 'all'
            ? array_reduce($conditionResults, fn (bool $carry, array $condition) => $carry && $condition['result'], true)
            : array_reduce($conditionResults, fn (bool $carry, array $condition) => $carry || $condition['result'], false);

        return $baseResult + [
            'decision' => $matches ? $decisionOnMatch : 'no_signal',
            'conditions' => $conditionResults,
            'data_status' => 'complete',
            'reason' => $matches
                ? 'Todas as condições aplicáveis foram atendidas pela última vela fechada.'
                : 'A lógica da estratégia não foi atendida pela última vela fechada.',
        ];
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
            'moving_average_cross' => $this->crossSeries($candles, $parameters),
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

        return $macd[$parameters['component'] ?? 'line'] ?? $macd['line'];
    }

    /** @param array<string, mixed> $parameters @return array<int, float|null> */
    private function bollingerSeries(array $candles, array $parameters): array
    {
        $bands = $this->indicators->bollinger(
            $candles,
            (int) $parameters['period'],
            (float) $parameters['std_dev'],
        );

        return $bands[$parameters['component'] ?? 'middle'] ?? $bands['middle'];
    }

    /** @param array<string, mixed> $parameters @return array<int, float|null> */
    private function crossSeries(array $candles, array $parameters): array
    {
        $average = $parameters['average_type'] ?? 'ema';
        $fast = $this->indicators->{$average}($candles, (int) $parameters['fast_period']);
        $slow = $this->indicators->{$average}($candles, (int) $parameters['slow_period']);

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
