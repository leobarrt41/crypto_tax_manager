<?php

namespace App\Services;

use App\Models\CryptoReportingRuleVersion;
use Carbon\CarbonImmutable;
use LogicException;

class CryptoReportingRuleResolver
{
    public const LEGACY_IN1888_FORMAT = 'in1888_legacy_v1';
    public const DECRIPTO_FOREIGN_USER_FORMAT = 'decripto_foreign_user_v1';

    /**
     * Recupera a regra de obrigação acessória aplicável ao primeiro dia da competência.
     */
    public function resolve(int $year, int $month): CryptoReportingRuleVersion
    {
        $competenceDate = $this->competenceDate($year, $month);

        // Regras fiscais são poucos registros e podem ser alteradas por vigência.
        // A consulta direta evita usar um limite desatualizado durante uma revisão.
        $rule = CryptoReportingRuleVersion::query()
            ->applicableOn($competenceDate->toDateString())
            ->orderByDesc('effective_from')
            ->first();

        if (!$rule) {
            throw new LogicException(
                "Nenhuma regra de declaração de criptoativos foi configurada para a competência {$competenceDate->format('m/Y')}."
            );
        }

        return $rule;
    }

    /**
     * Cria um contrato de resposta estável para API e interface.
     */
    public function context(int $year, int $month): array
    {
        $rule = $this->resolve($year, $month);

        return [
            'code' => $rule->code,
            'obligation_name' => $rule->obligation_name,
            'regime_label' => data_get($rule->configuration, 'regime_label', $rule->obligation_name),
            'legal_reference' => data_get($rule->configuration, 'legal_reference'),
            'reporting_format' => $rule->reporting_format,
            'monthly_threshold_brl' => $rule->monthly_threshold_brl === null
                ? null
                : (float) $rule->monthly_threshold_brl,
            'threshold_comparison' => $rule->threshold_comparison,
            'reporting_scope' => $rule->reporting_scope,
            'deadline_rule' => $rule->deadline_rule,
            'legacy_export_available' => $rule->legacy_export_available,
            'export_status' => data_get($rule->configuration, 'export_status', 'pending'),
            'effective_from' => $rule->effective_from->toDateString(),
            'effective_until' => $rule->effective_until?->toDateString(),
        ];
    }

    public function isMonthlyDeclarationRequired(float $volumeBrl, CryptoReportingRuleVersion $rule): bool
    {
        if ($rule->monthly_threshold_brl === null) {
            return false;
        }

        $threshold = (float) $rule->monthly_threshold_brl;

        return match ($rule->threshold_comparison) {
            'gte' => $volumeBrl >= $threshold,
            'gt' => $volumeBrl > $threshold,
            default => throw new LogicException("Comparador de limite fiscal não suportado: {$rule->threshold_comparison}"),
        };
    }


    private function competenceDate(int $year, int $month): CarbonImmutable
    {
        if ($year < 2019 || $year > 2100 || $month < 1 || $month > 12) {
            throw new LogicException('Competência fiscal inválida. Informe um ano entre 2019 e 2100 e um mês entre 1 e 12.');
        }

        return CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'America/Sao_Paulo')->startOfDay();
    }
}
