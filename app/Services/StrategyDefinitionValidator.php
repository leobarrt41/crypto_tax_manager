<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class StrategyDefinitionValidator
{
    private const INDICATORS = ['rsi', 'sma', 'ema', 'macd', 'bollinger', 'ma_cross'];

    private const OPERATIONAL_KEYS = [
        'symbol', 'pair', 'exchange', 'timeframe', 'side', 'mode',
        'execution', 'order_type', 'quantity', 'quote_amount',
        'leverage', 'real_execution_enabled',
    ];

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
        $allowedKeys = ['schema_version', 'logic', 'entry_conditions', 'exit_conditions', 'risk'];
        $this->rejectOperationalKeys($definition, 'definition', $errors);

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

        $conditionGroups = [];
        foreach (['entry_conditions', 'exit_conditions'] as $group) {
            $conditions = $definition[$group] ?? null;
            if (!is_array($conditions) || !array_is_list($conditions)) {
                $errors[$group][] = 'As condições devem ser uma lista.';
                $conditions = [];
            }

            foreach ($conditions as $index => $condition) {
                if (!is_array($condition)) {
                    $errors["{$group}.{$index}"][] = 'Cada condição deve ser um objeto.';
                    continue;
                }

                $this->validateCondition($condition, "{$group}.{$index}", $errors);
            }

            $conditionGroups[$group] = array_values($conditions);
        }

        if (!$allowIncompleteDraft && count($conditionGroups['entry_conditions']) + count($conditionGroups['exit_conditions']) === 0) {
            $errors['entry_conditions'][] = 'Inclua ao menos uma condição de entrada ou saída antes de validar a estratégia.';
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
            'entry_conditions' => $conditionGroups['entry_conditions'],
            'exit_conditions' => $conditionGroups['exit_conditions'],
            'risk' => [
                'stop_loss_pct' => $risk['stop_loss_pct'] ?? null,
                'take_profit_pct' => $risk['take_profit_pct'] ?? null,
            ],
        ]);
    }

    /** @param array<string, mixed> $condition @param array<string, array<int, string>> $errors */
    private function validateCondition(array $condition, string $prefix, array &$errors): void
    {
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

        $allowedParameters = match ($indicator) {
            'rsi', 'sma', 'ema' => ['period'],
            'macd' => ['fast_period', 'slow_period', 'signal_period'],
            'bollinger' => ['period', 'std_dev'],
            'ma_cross' => ['fast_period', 'slow_period'],
        };
        foreach (array_keys($parameters) as $parameter) {
            if (!in_array($parameter, $allowedParameters, true)) {
                $errors["{$prefix}.parameters.{$parameter}"][] = 'Parâmetro não permitido para este indicador.';
            }
        }

        match ($indicator) {
            'rsi', 'sma', 'ema', 'bollinger' => $this->validatePeriod($parameters['period'] ?? null, "{$prefix}.parameters.period", $errors),
            'macd' => $this->validateMacd($parameters, $prefix, $errors),
            'ma_cross' => $this->validateCross($parameters, $prefix, $errors),
        };

        if ($indicator === 'bollinger') {
            $stdDev = $parameters['std_dev'] ?? null;
            if (!is_numeric($stdDev) || (float) $stdDev <= 0) {
                $errors["{$prefix}.parameters.std_dev"][] = 'std_dev deve ser positivo.';
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
                ], "{$prefix}.compare_with", $errors);
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

    }

    /** @param array<string, mixed> $risk @param array<string, array<int, string>> $errors */
    private function validateRisk(array $risk, array &$errors): void
    {
        $allowedRiskKeys = ['stop_loss_pct', 'take_profit_pct'];

        foreach ($risk as $key => $value) {
            if (!in_array($key, $allowedRiskKeys, true)) {
                $errors["risk.{$key}"][] = 'Configuração de risco não suportada.';
                continue;
            }

            if ($value !== null && (!is_numeric($value) || (float) $value <= 0 || (float) $value > 100)) {
                $errors["risk.{$key}"][] = 'O percentual deve ser maior que 0 e no máximo 100.';
            }
        }
    }

    /** @param array<string, mixed> $value @param array<string, array<int, string>> $errors */
    private function rejectOperationalKeys(array $value, string $path, array &$errors): void
    {
        foreach ($value as $key => $item) {
            $itemPath = "{$path}.{$key}";
            if (is_string($key) && in_array(strtolower($key), self::OPERATIONAL_KEYS, true)) {
                $errors[$itemPath][] = 'Campo operacional proibido na definição reutilizável da estratégia.';
            }

            if (is_array($item)) {
                $this->rejectOperationalKeys($item, $itemPath, $errors);
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
