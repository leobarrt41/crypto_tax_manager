<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class StrategyDefinitionValidator
{
    private const INDICATORS = ['rsi', 'sma', 'ema', 'macd', 'bollinger', 'moving_average_cross'];

    private const OPERATORS = [
        'greater_than',
        'less_than',
        'greater_than_or_equal',
        'less_than_or_equal',
        'crosses_above',
        'crosses_below',
        'greater_than_indicator',
        'less_than_indicator',
        'close_above_upper_band',
        'close_below_lower_band',
    ];

    /**
     * Valida e normaliza a definição serializável de uma estratégia.
     *
     * @return array<string, mixed>
     */
    public function validate(array $definition, bool $allowIncompleteDraft = false): array
    {
        $errors = [];
        $allowedKeys = ['schema_version', 'logic', 'conditions', 'risk'];
        $forbiddenKeys = ['symbol', 'exchange', 'timeframe', 'side', 'mode'];

        foreach ($forbiddenKeys as $key) {
            if (array_key_exists($key, $definition)) {
                $errors[$key][] = 'Este campo pertence a Backtests ou Operações e não pode compor uma estratégia.';
            }
        }

        foreach (array_keys($definition) as $key) {
            if (!in_array($key, $allowedKeys, true)) {
                $errors[$key][] = 'Campo não reconhecido na definição da estratégia.';
            }
        }

        if (($definition['schema_version'] ?? null) !== 1) {
            $errors['schema_version'][] = 'A definição deve usar schema_version igual a 1.';
        }

        if (!in_array($definition['logic'] ?? null, ['all', 'any'], true)) {
            $errors['logic'][] = 'A lógica deve ser all ou any.';
        }

        $conditions = $definition['conditions'] ?? null;
        if (!is_array($conditions)) {
            $errors['conditions'][] = 'As condições devem ser uma lista.';
            $conditions = [];
        }

        if (!$allowIncompleteDraft && count($conditions) === 0) {
            $errors['conditions'][] = 'Inclua ao menos uma condição antes de validar a estratégia.';
        }

        foreach ($conditions as $index => $condition) {
            if (!is_array($condition)) {
                $errors["conditions.{$index}"][] = 'Cada condição deve ser um objeto.';
                continue;
            }

            $this->validateCondition($condition, $index, $errors);
        }

        $risk = $definition['risk'] ?? [];
        if (!is_array($risk)) {
            $errors['risk'][] = 'As configurações de risco devem ser um objeto.';
            $risk = [];
        }

        $this->validateRisk($risk, $errors);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->canonicalize([
            'schema_version' => 1,
            'logic' => $definition['logic'],
            'conditions' => array_values($conditions),
            'risk' => $risk,
        ]);
    }

    /** @param array<string, mixed> $condition @param array<string, array<int, string>> $errors */
    private function validateCondition(array $condition, int $index, array &$errors): void
    {
        $prefix = "conditions.{$index}";
        $indicator = $condition['indicator'] ?? null;
        $operator = $condition['operator'] ?? null;

        if (!in_array($indicator, self::INDICATORS, true)) {
            $errors["{$prefix}.indicator"][] = 'Indicador não suportado.';
            return;
        }

        if (!in_array($operator, self::OPERATORS, true)) {
            $errors["{$prefix}.operator"][] = 'Operador não suportado.';
        }

        $parameters = $condition['parameters'] ?? [];
        if (!is_array($parameters)) {
            $errors["{$prefix}.parameters"][] = 'Os parâmetros devem ser um objeto.';
            return;
        }

        match ($indicator) {
            'rsi', 'sma', 'ema', 'bollinger' => $this->validatePeriod($parameters['period'] ?? null, "{$prefix}.parameters.period", $errors),
            'macd' => $this->validateMacd($parameters, $prefix, $errors),
            'moving_average_cross' => $this->validateCross($parameters, $prefix, $errors),
        };

        if ($indicator === 'bollinger') {
            $stdDev = $parameters['std_dev'] ?? null;
            if (!is_numeric($stdDev) || (float) $stdDev <= 0 || (float) $stdDev > 10) {
                $errors["{$prefix}.parameters.std_dev"][] = 'std_dev deve estar entre 0 e 10.';
            }
        }

        if (in_array($operator, ['greater_than', 'less_than', 'greater_than_or_equal', 'less_than_or_equal', 'crosses_above', 'crosses_below'], true)
            && !is_numeric($condition['value'] ?? null)) {
            $errors["{$prefix}.value"][] = 'Este operador exige um valor numérico.';
        }

        if ($indicator === 'rsi' && is_numeric($condition['value'] ?? null)
            && ((float) $condition['value'] < 0 || (float) $condition['value'] > 100)) {
            $errors["{$prefix}.value"][] = 'O limiar do RSI deve estar entre 0 e 100.';
        }

        if (in_array($operator, ['greater_than_indicator', 'less_than_indicator'], true)) {
            if (!is_array($condition['compare_with'] ?? null)) {
                $errors["{$prefix}.compare_with"][] = 'Este operador exige outro indicador para comparação.';
            } else {
                $this->validateCondition([
                    'indicator' => $condition['compare_with']['indicator'] ?? null,
                    'parameters' => $condition['compare_with']['parameters'] ?? [],
                    'operator' => 'greater_than',
                    'value' => 0,
                ], $index, $errors);
            }
        }
    }

    /** @param array<string, array<int, string>> $errors */
    private function validatePeriod(mixed $period, string $key, array &$errors): void
    {
        if (filter_var($period, FILTER_VALIDATE_INT) === false || (int) $period < 2 || (int) $period > 500) {
            $errors[$key][] = 'O período deve ser um inteiro entre 2 e 500.';
        }
    }

    /** @param array<string, mixed> $parameters @param array<string, array<int, string>> $errors */
    private function validateMacd(array $parameters, string $prefix, array &$errors): void
    {
        foreach (['fast_period', 'slow_period', 'signal_period'] as $key) {
            $this->validatePeriod($parameters[$key] ?? null, "{$prefix}.parameters.{$key}", $errors);
        }

        if (isset($parameters['fast_period'], $parameters['slow_period'])
            && (int) $parameters['fast_period'] >= (int) $parameters['slow_period']) {
            $errors["{$prefix}.parameters.fast_period"][] = 'fast_period deve ser menor que slow_period.';
        }
    }

    /** @param array<string, mixed> $parameters @param array<string, array<int, string>> $errors */
    private function validateCross(array $parameters, string $prefix, array &$errors): void
    {
        foreach (['fast_period', 'slow_period'] as $key) {
            $this->validatePeriod($parameters[$key] ?? null, "{$prefix}.parameters.{$key}", $errors);
        }

        if (isset($parameters['fast_period'], $parameters['slow_period'])
            && (int) $parameters['fast_period'] >= (int) $parameters['slow_period']) {
            $errors["{$prefix}.parameters.fast_period"][] = 'fast_period deve ser menor que slow_period.';
        }

        if (!in_array($parameters['average_type'] ?? 'ema', ['sma', 'ema'], true)) {
            $errors["{$prefix}.parameters.average_type"][] = 'average_type deve ser sma ou ema.';
        }
    }

    /** @param array<string, mixed> $risk @param array<string, array<int, string>> $errors */
    private function validateRisk(array $risk, array &$errors): void
    {
        $allowedRiskKeys = ['stop_loss_pct', 'take_profit_pct', 'trailing_stop_pct'];

        foreach ($risk as $key => $value) {
            if (!in_array($key, $allowedRiskKeys, true)) {
                $errors["risk.{$key}"][] = 'Configuração de risco não suportada.';
                continue;
            }

            if (!is_numeric($value) || (float) $value <= 0 || (float) $value > 100) {
                $errors["risk.{$key}"][] = 'O percentual deve ser maior que 0 e no máximo 100.';
            }
        }
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    private function canonicalize(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item) => is_array($item) ? $this->canonicalize($item) : $item,
                $value,
            );
        }

        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }
}
