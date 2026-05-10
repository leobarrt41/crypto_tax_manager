<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Exchange;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * In1888StatusService
 *
 * Calcula a obrigatoriedade mensal da IN 1888 por usuário.
 *
 * Regras legais implementadas:
 * ─────────────────────────────────────────────────────────────────────────────
 * A IN 1888 obriga a declaração mensal quando o volume de movimentações em
 * exchanges ESTRANGEIRAS ou carteiras próprias supera R$ 30.000 no mês.
 *
 * Exchanges NACIONAIS (country_code = 'BR') estão ISENTAS da IN 1888 pois a
 * própria corretora é responsável pelo reporte à Receita Federal.
 *
 * Portanto, apenas transações cujo source seja uma exchange estrangeira
 * (ou carteira/wallet) entram no cálculo do volume mensal.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class In1888StatusService
{
    /** Limite mensal em BRL para obrigatoriedade da IN 1888 */
    public const LIMITE_BRL = 30_000.00;

    /** country_code das exchanges consideradas nacionais */
    public const COUNTRY_CODE_NACIONAL = 'BR';

    // ─── API pública ─────────────────────────────────────────────────────────

    /**
     * Retorna o status IN 1888 do mês/ano informado para o usuário.
     *
     * @return array{
     *   year: int,
     *   month: int,
     *   month_label: string,
     *   volume_brl: float,
     *   transactions_count: int,
     *   status: 'required'|'not_required'|'no_data',
     *   status_label: string,
     * }
     */
    public function statusMensal(int $userId, int $year, int $month): array
    {
        $result = $this->calcularVolumeMensal($userId, $year, $month);

        return $this->buildStatusRow($year, $month, $result['volume_brl'], $result['count']);
    }

    /**
     * Retorna o status IN 1888 para todos os 12 meses de um ano.
     *
     * @return array Array com 12 elementos, um por mês.
     */
    public function statusAnual(int $userId, int $year): array
    {
        // Busca todos os meses do ano de uma vez (query única)
        $rows = $this->calcularVolumeAnual($userId, $year);

        $meses = [];
        for ($m = 1; $m <= 12; $m++) {
            $row = $rows->firstWhere('month', $m);
            $meses[] = $this->buildStatusRow(
                $year,
                $m,
                $row ? (float) $row->volume_brl : 0.0,
                $row ? (int)   $row->count       : 0
            );
        }

        return $meses;
    }

    /**
     * Retorna o status do mês atual — usado pelo Dashboard.
     */
    public function statusMesAtual(int $userId): array
    {
        $now = Carbon::now('America/Sao_Paulo');
        return $this->statusMensal($userId, $now->year, $now->month);
    }

    /**
     * Retorna o resumo anual (contagem por status).
     *
     * @return array{required: int, not_required: int, no_data: int}
     */
    public function resumoAnual(int $userId, int $year): array
    {
        $meses = $this->statusAnual($userId, $year);

        return [
            'required'     => collect($meses)->where('status', 'required')->count(),
            'not_required' => collect($meses)->where('status', 'not_required')->count(),
            'no_data'      => collect($meses)->where('status', 'no_data')->count(),
        ];
    }

    // ─── Lógica interna ──────────────────────────────────────────────────────

    /**
     * Calcula o volume mensal de movimentações em exchanges ESTRANGEIRAS
     * e carteiras (source_type = Wallet) para o usuário.
     *
     * Exchanges nacionais (country_code = 'BR') são excluídas — a obrigação
     * de reporte é da própria corretora nacional.
     *
     * @return array{volume_brl: float, count: int}
     */
    private function calcularVolumeMensal(int $userId, int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1, 0, 0, 0, 'America/Sao_Paulo')->startOfMonth();
        $end   = $start->copy()->endOfMonth();

        $query = $this->baseQuery($userId, $start, $end);
        $row   = $query->first();

        return [
            'volume_brl' => $row ? (float) $row->volume_brl : 0.0,
            'count'      => $row ? (int)   $row->count       : 0,
        ];
    }

    /**
     * Calcula o volume de todos os meses de um ano em uma única query.
     */
    private function calcularVolumeAnual(int $userId, int $year)
    {
        $start = Carbon::create($year, 1, 1, 0, 0, 0, 'America/Sao_Paulo')->startOfYear();
        $end   = $start->copy()->endOfYear();

        return $this->baseQuery($userId, $start, $end, groupByMonth: true)->get();
    }

    /**
     * Query base: transações de exchanges estrangeiras + carteiras.
     *
     * A exclusão de exchanges nacionais é feita via LEFT JOIN na tabela
     * user_api_keys → exchanges, filtrando country_code <> 'BR'.
     *
     * Para source_type = Wallet (carteiras próprias), sempre inclui.
     */
    private function baseQuery(int $userId, Carbon $start, Carbon $end, bool $groupByMonth = false)
    {
        $query = DB::table('transactions as t')
            ->where('t.user_id', $userId)
            ->whereBetween('t.date', [$start->toDateTimeString(), $end->toDateTimeString()])
            // Junta com user_api_keys para obter o exchange_id (apenas quando source = UserApiKey)
            ->leftJoin('user_api_keys as uak', function ($join) {
                $join->on('uak.id', '=', 't.source_id')
                     ->where('t.source_type', '=', 'App\\Models\\UserApiKey');
            })
            // Junta com exchanges para verificar o country_code
            ->leftJoin('exchanges as ex', 'ex.id', '=', 'uak.exchange_id')
            // Exclui exchanges nacionais (BR). Inclui wallets (uak.id IS NULL) e exchanges estrangeiras.
            ->where(function ($q) {
                $q->whereNull('uak.id')                                       // é carteira (wallet)
                  ->orWhere('ex.country_code', '<>', self::COUNTRY_CODE_NACIONAL) // é exchange estrangeira
                  ->orWhereNull('ex.country_code');                           // exchange sem country_code cadastrado
            })
            ->selectRaw('SUM(ABS(COALESCE(t.total_brl, 0))) as volume_brl, COUNT(*) as count');

        if ($groupByMonth) {
            $query->selectRaw('MONTH(t.date) as month')
                  ->groupByRaw('MONTH(t.date)');
        }

        return $query;
    }

    /**
     * Monta o array de status para um mês.
     */
    private function buildStatusRow(int $year, int $month, float $volumeBrl, int $count): array
    {
        if ($count === 0) {
            $status      = 'no_data';
            $statusLabel = 'Sem dados';
        } elseif ($volumeBrl > self::LIMITE_BRL) {
            $status      = 'required';
            $statusLabel = 'Obrigatória';
        } else {
            $status      = 'not_required';
            $statusLabel = 'Não obrigatória';
        }

        return [
            'year'               => $year,
            'month'              => $month,
            'month_label'        => $this->nomeMes($month),
            'volume_brl'         => round($volumeBrl, 2),
            'transactions_count' => $count,
            'status'             => $status,
            'status_label'       => $statusLabel,
        ];
    }

    private function nomeMes(int $month): string
    {
        return [
            1 => 'Janeiro',   2 => 'Fevereiro', 3 => 'Março',
            4 => 'Abril',     5 => 'Maio',      6 => 'Junho',
            7 => 'Julho',     8 => 'Agosto',    9 => 'Setembro',
            10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
        ][$month] ?? "Mês {$month}";
    }
}
