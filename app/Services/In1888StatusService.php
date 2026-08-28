<?php

namespace App\Services;

use App\Models\CryptoReportingRuleVersion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mantém compatibilidade com os endpoints legados de IN 1888, mas calcula a
 * obrigação correta por competência por meio da regra fiscal versionada.
 */
class In1888StatusService
{
    public const COUNTRY_CODE_NACIONAL = 'BR';

    public function __construct(private CryptoReportingRuleResolver $ruleResolver)
    {
    }

    /**
     * Retorna o status da obrigação de criptoativos para uma competência.
     *
     * @return array{
     *   year: int,
     *   month: int,
     *   month_label: string,
     *   volume_brl: float,
     *   transactions_count: int,
     *   status: 'required'|'not_required'|'no_data',
     *   status_label: string,
     *   rule: array<string, mixed>
     * }
     */
    public function statusMensal(int $userId, int $year, int $month): array
    {
        $result = $this->calcularVolumeMensal($userId, $year, $month);
        $rule = $this->ruleResolver->resolve($year, $month);

        return $this->buildStatusRow($year, $month, $result['volume_brl'], $result['count'], $rule);
    }

    /**
     * Retorna 12 competências, cada uma com a regra vigente no respectivo mês.
     */
    public function statusAnual(int $userId, int $year): array
    {
        $rows = $this->calcularVolumeAnual($userId, $year);
        $meses = [];

        for ($month = 1; $month <= 12; $month++) {
            $row = $rows->firstWhere('month', $month);
            $rule = $this->ruleResolver->resolve($year, $month);

            $meses[] = $this->buildStatusRow(
                $year,
                $month,
                $row ? (float) $row->volume_brl : 0.0,
                $row ? (int) $row->count : 0,
                $rule
            );
        }

        return $meses;
    }

    public function statusMesAtual(int $userId): array
    {
        $now = Carbon::now('America/Sao_Paulo');

        return $this->statusMensal($userId, $now->year, $now->month);
    }

    /**
     * @return array{required: int, not_required: int, no_data: int}
     */
    public function resumoAnual(int $userId, int $year): array
    {
        return collect($this->statusAnual($userId, $year))
            ->countBy('status')
            ->pipe(fn ($summary) => [
                'required' => (int) ($summary['required'] ?? 0),
                'not_required' => (int) ($summary['not_required'] ?? 0),
                'no_data' => (int) ($summary['no_data'] ?? 0),
            ]);
    }

    /**
     * Expõe a regra vigente para a tela de geração, sem duplicar a resolução.
     */
    public function regraDaCompetencia(int $year, int $month): array
    {
        return $this->ruleResolver->context($year, $month);
    }

    /**
     * Considera operações do usuário em prestadora estrangeira e em carteira
     * própria. Exchanges brasileiras são excluídas do escopo do usuário.
     *
     * @return array{volume_brl: float, count: int}
     */
    private function calcularVolumeMensal(int $userId, int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1, 0, 0, 0, 'America/Sao_Paulo')->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $row = $this->baseQuery($userId, $start, $end)->first();

        return [
            'volume_brl' => $row ? (float) $row->volume_brl : 0.0,
            'count' => $row ? (int) $row->count : 0,
        ];
    }

    private function calcularVolumeAnual(int $userId, int $year)
    {
        $start = Carbon::create($year, 1, 1, 0, 0, 0, 'America/Sao_Paulo')->startOfYear();
        $end = $start->copy()->endOfYear();

        return $this->baseQuery($userId, $start, $end, groupByMonth: true)->get();
    }

    private function baseQuery(int $userId, Carbon $start, Carbon $end, bool $groupByMonth = false)
    {
        $query = DB::table('transactions as t')
            ->where('t.user_id', $userId)
            ->whereBetween('t.date', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->leftJoin('user_api_keys as uak', function ($join) {
                $join->on('uak.id', '=', 't.source_id')
                    ->where('t.source_type', '=', 'App\\Models\\UserApiKey');
            })
            ->leftJoin('exchanges as ex', 'ex.id', '=', 'uak.exchange_id')
            ->where(function ($query) {
                $query->whereNull('uak.id')
                    ->orWhere('ex.country_code', '<>', self::COUNTRY_CODE_NACIONAL)
                    ->orWhereNull('ex.country_code');
            })
            ->selectRaw('SUM(ABS(COALESCE(t.total_brl, 0))) as volume_brl, COUNT(*) as count');

        if ($groupByMonth) {
            $query->selectRaw('MONTH(t.date) as month')
                ->groupByRaw('MONTH(t.date)');
        }

        return $query;
    }

    private function buildStatusRow(
        int $year,
        int $month,
        float $volumeBrl,
        int $count,
        CryptoReportingRuleVersion $rule
    ): array {
        if ($count === 0) {
            $status = 'no_data';
            $statusLabel = 'Sem dados';
        } elseif ($this->ruleResolver->isMonthlyDeclarationRequired($volumeBrl, $rule)) {
            $status = 'required';
            $statusLabel = 'Obrigatória';
        } else {
            $status = 'not_required';
            $statusLabel = 'Não obrigatória';
        }

        return [
            'year' => $year,
            'month' => $month,
            'month_label' => $this->nomeMes($month),
            'volume_brl' => round($volumeBrl, 2),
            'transactions_count' => $count,
            'status' => $status,
            'status_label' => $statusLabel,
            'rule' => $this->ruleResolver->context($year, $month),
        ];
    }

    private function nomeMes(int $month): string
    {
        return [
            1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março',
            4 => 'Abril', 5 => 'Maio', 6 => 'Junho',
            7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro',
            10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
        ][$month] ?? "Mês {$month}";
    }
}
