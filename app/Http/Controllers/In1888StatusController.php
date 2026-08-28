<?php

namespace App\Http\Controllers;

use App\Services\In1888StatusService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * In1888StatusController
 *
 * Endpoints para consulta de obrigatoriedade mensal da IN 1888.
 * Toda lógica de negócio está centralizada no In1888StatusService.
 */
class In1888StatusController extends Controller
{
    public function __construct(private In1888StatusService $service)
    {
    }

    // ─── Endpoints JSON ──────────────────────────────────────────────────────

    /**
     * Status do mês atual (usado pelo Dashboard).
     * GET /api/in1888-status/current
     */
    public function current(): \Illuminate\Http\JsonResponse
    {
        $status = $this->service->statusMesAtual(Auth::id());

        return response()->json($status);
    }

    /**
     * Status de todos os 12 meses de um ano.
     * GET /api/in1888-status/annual?year=2024
     */
    public function annual(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'year' => 'required|integer|min:2009|max:2099',
        ]);

        $userId = Auth::id();
        $year   = (int) $request->year;

        $meses  = $this->service->statusAnual($userId, $year);
        $resumo = $this->service->resumoAnual($userId, $year);

        return response()->json([
            'year'    => $year,
            'months'  => $meses,
            'summary' => $resumo,
        ]);
    }

    /**
     * Status de um mês específico.
     * GET /api/in1888-status/monthly?year=2024&month=3
     */
    public function monthly(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'year'  => 'required|integer|min:2009|max:2099',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $status = $this->service->statusMensal(
            Auth::id(),
            (int) $request->year,
            (int) $request->month
        );

        return response()->json($status);
    }

    /**
     * Exporta o status anual em CSV.
     * GET /api/in1888-status/export-csv?year=2024
     */
    public function exportCsv(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $request->validate([
            'year' => 'required|integer|min:2009|max:2099',
        ]);

        $userId   = Auth::id();
        $year     = (int) $request->year;
        $meses    = $this->service->statusAnual($userId, $year);
        $filename = "obrigatoriedade_criptoativos_{$year}.csv";

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($meses, $year) {
            $handle = fopen('php://output', 'w');

            // BOM UTF-8 para Excel
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, [
                'Ano',
                'Mês',
                'Volume (R$)',
                'Qtd. Transações',
                'Obrigação',
                'Limite (R$)',
                'Status',
            ], ';');

            foreach ($meses as $row) {
                fputcsv($handle, [
                    $year,
                    $row['month_label'],
                    number_format($row['volume_brl'], 2, ',', '.'),
                    $row['transactions_count'],
                    data_get($row, 'rule.obligation_name', '—'),
                    data_get($row, 'rule.monthly_threshold_brl') === null
                        ? '—'
                        : number_format((float) data_get($row, 'rule.monthly_threshold_brl'), 2, ',', '.'),
                    $row['status_label'],
                ], ';');
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
}
