<?php

namespace App\Services;

use InvalidArgumentException;

class IndicatorCalculator
{
    /**
     * @param array<int, array<string, mixed>> $candles
     * @return array<int, array<string, mixed>>
     */
    public function closedCandles(array $candles): array
    {
        $closed = array_values(array_filter($candles, fn (array $candle) => ($candle['is_closed'] ?? true) !== false));

        usort($closed, function (array $left, array $right): int {
            return strcmp((string) ($left['close_time'] ?? ''), (string) ($right['close_time'] ?? ''));
        });

        foreach ($closed as $index => $candle) {
            if (!isset($candle['close']) || !is_numeric($candle['close'])) {
                throw new InvalidArgumentException("Candle fechado {$index} não possui preço de fechamento numérico.");
            }
        }

        return $closed;
    }

    /** @return array<int, float|null> */
    public function sma(array $candles, int $period): array
    {
        $closes = $this->closes($candles);
        $this->assertSufficientData($closes, $period);
        $values = array_fill(0, count($closes), null);
        $sum = 0.0;

        foreach ($closes as $index => $close) {
            $sum += $close;
            if ($index >= $period) {
                $sum -= $closes[$index - $period];
            }
            if ($index >= $period - 1) {
                $values[$index] = $sum / $period;
            }
        }

        return $values;
    }

    /** @return array<int, float|null> */
    public function ema(array $candles, int $period): array
    {
        $closes = $this->closes($candles);
        $this->assertSufficientData($closes, $period);
        $values = array_fill(0, count($closes), null);
        $seed = array_sum(array_slice($closes, 0, $period)) / $period;
        $multiplier = 2 / ($period + 1);
        $values[$period - 1] = $seed;

        for ($index = $period; $index < count($closes); $index++) {
            $values[$index] = (($closes[$index] - $values[$index - 1]) * $multiplier) + $values[$index - 1];
        }

        return $values;
    }

    /** @return array<int, float|null> */
    public function rsi(array $candles, int $period): array
    {
        $closes = $this->closes($candles);
        $this->assertSufficientData($closes, $period + 1);
        $values = array_fill(0, count($closes), null);
        $gain = 0.0;
        $loss = 0.0;

        for ($index = 1; $index <= $period; $index++) {
            $change = $closes[$index] - $closes[$index - 1];
            $gain += max($change, 0);
            $loss += max(-$change, 0);
        }

        $averageGain = $gain / $period;
        $averageLoss = $loss / $period;
        $values[$period] = $this->rsiFromAverages($averageGain, $averageLoss);

        for ($index = $period + 1; $index < count($closes); $index++) {
            $change = $closes[$index] - $closes[$index - 1];
            $averageGain = (($averageGain * ($period - 1)) + max($change, 0)) / $period;
            $averageLoss = (($averageLoss * ($period - 1)) + max(-$change, 0)) / $period;
            $values[$index] = $this->rsiFromAverages($averageGain, $averageLoss);
        }

        return $values;
    }

    /** @return array{line: array<int, float|null>, signal: array<int, float|null>, histogram: array<int, float|null>} */
    public function macd(array $candles, int $fastPeriod, int $slowPeriod, int $signalPeriod): array
    {
        if ($fastPeriod >= $slowPeriod) {
            throw new InvalidArgumentException('fast_period deve ser menor que slow_period.');
        }

        $closedCandles = $this->closedCandles($candles);
        $fast = $this->ema($closedCandles, $fastPeriod);
        $slow = $this->ema($closedCandles, $slowPeriod);
        $line = array_fill(0, count($closedCandles), null);

        foreach ($line as $index => $_) {
            if ($fast[$index] !== null && $slow[$index] !== null) {
                $line[$index] = $fast[$index] - $slow[$index];
            }
        }

        $nonNullLine = array_values(array_filter($line, fn ($value) => $value !== null));
        $this->assertSufficientData($nonNullLine, $signalPeriod);
        $seedIndex = array_key_first(array_filter($line, fn ($value) => $value !== null));
        $signal = array_fill(0, count($closedCandles), null);
        $seed = array_sum(array_slice($nonNullLine, 0, $signalPeriod)) / $signalPeriod;
        $signalIndex = $seedIndex + $signalPeriod - 1;
        $signal[$signalIndex] = $seed;
        $multiplier = 2 / ($signalPeriod + 1);

        for ($index = $signalIndex + 1; $index < count($line); $index++) {
            if ($line[$index] === null) {
                continue;
            }
            $signal[$index] = (($line[$index] - $signal[$index - 1]) * $multiplier) + $signal[$index - 1];
        }

        $histogram = array_map(
            fn ($lineValue, $signalValue) => $lineValue !== null && $signalValue !== null ? $lineValue - $signalValue : null,
            $line,
            $signal
        );

        return compact('line', 'signal', 'histogram');
    }

    /** @return array{middle: array<int, float|null>, upper: array<int, float|null>, lower: array<int, float|null>} */
    public function bollinger(array $candles, int $period, float $standardDeviations): array
    {
        $closes = $this->closes($candles);
        $this->assertSufficientData($closes, $period);
        $middle = $this->sma($candles, $period);
        $upper = array_fill(0, count($closes), null);
        $lower = array_fill(0, count($closes), null);

        for ($index = $period - 1; $index < count($closes); $index++) {
            $window = array_slice($closes, $index - $period + 1, $period);
            $mean = $middle[$index];
            $variance = array_sum(array_map(fn ($value) => ($value - $mean) ** 2, $window)) / $period;
            $deviation = sqrt($variance);
            $upper[$index] = $mean + ($standardDeviations * $deviation);
            $lower[$index] = $mean - ($standardDeviations * $deviation);
        }

        return compact('middle', 'upper', 'lower');
    }

    /** @return array<int, float> */
    private function closes(array $candles): array
    {
        return array_map(fn (array $candle) => (float) $candle['close'], $this->closedCandles($candles));
    }

    /** @param array<int, mixed> $values */
    private function assertSufficientData(array $values, int $required): void
    {
        if (count($values) < $required) {
            throw new InvalidArgumentException("Dados insuficientes: são necessários ao menos {$required} candles fechados.");
        }
    }

    private function rsiFromAverages(float $averageGain, float $averageLoss): float
    {
        if ($averageLoss == 0.0) {
            return 100.0;
        }

        $relativeStrength = $averageGain / $averageLoss;

        return 100 - (100 / (1 + $relativeStrength));
    }
}
