<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Models\UserApiKey;
use Illuminate\Support\Facades\Log;
use Exception;
use Carbon\Carbon;

/**
 * BinanceConvertService (Versão Corrigida)
 *
 * Responsável por buscar o histórico completo de transações de conversão (Binance Convert)
 * de um usuário. Implementa uma estratégia de paginação por tempo para contornar
 * as limitações da API da Binance e obter o histórico de até 5 anos.
 */
class BinanceConvertService
{
    protected string $apiKey;
    protected string $secretKey;
    protected string $baseUrl = 'https://api.binance.com';

    public function __construct(UserApiKey $apiKey)
    {
        $this->apiKey = $apiKey->api_key;
        $this->secretKey = $apiKey->secret_key;
        Log::info('[BinanceConvertService] Serviço inicializado.');
    }

    public function fetchConversions(int $startTime, int $endTime): array
    {
        return $this->getConvertHistory($startTime, $endTime);
    }

    public function getConvertHistory($startTime = null, $endTime = null): array
    {
        Log::info('[BinanceConvertService] Iniciando busca de histórico com paginação por tempo.');

        $allConversions = [];
        $periodLimitDays = 89;

        $loopEndTime = $endTime ?: Carbon::now()->getTimestampMs();
        $loopStartTime = $startTime ?: Carbon::now()->subYears(5)->getTimestampMs();

        $iterationCount = 0;
        $maxIterations = 100; // Proteção contra loop infinito

        while ($loopEndTime >= $loopStartTime && $iterationCount < $maxIterations) {
            $iterationCount++;
            
            $currentWindowStart = max(
                $loopStartTime,
                Carbon::createFromTimestampMs($loopEndTime)->subDays($periodLimitDays)->getTimestampMs()
            );

            Log::info('[BinanceConvertService] Buscando lote de conversões no período:', [
                'iteration' => $iterationCount,
                'start' => date('Y-m-d H:i:s', $currentWindowStart / 1000),
                'end' => date('Y-m-d H:i:s', $loopEndTime / 1000),
            ]);

            $params = [
                'startTime' => $currentWindowStart,
                'endTime' => $loopEndTime,
                'limit' => 1000,
                'timestamp' => round(microtime(true) * 1000),
                'recvWindow' => 20000,
            ];

            $queryString = http_build_query($params);
            $signature = hash_hmac('sha256', $queryString, $this->secretKey);
            $params['signature'] = $signature;

            try {
                $response = Http::withHeaders(['X-MBX-APIKEY' => $this->apiKey])
                        ->timeout(60)
                        ->retry(5, 500, function ($exception, $request) {
                            $status = optional($exception->response)->status();
                            return $status === 429 || $status === 418;
                        })
                        ->get($this->baseUrl . '/sapi/v1/convert/tradeFlow', $params);


                if ($response->successful() && isset($response->json()['list'])) {
                    $batch = $response->json()['list'];
                    if (!empty($batch)) {
                        $allConversions = array_merge($allConversions, $batch);
                        Log::info('[BinanceConvertService] Lote recebido.', [
                            'count' => count($batch),
                            'total_acumulado' => count($allConversions)
                        ]);
                    } else {
                        Log::debug('[BinanceConvertService] Lote vazio recebido para o período. Continuando...');
                    }
                } else {
                    Log::error('[BinanceConvertService] Falha ao buscar lote. Interrompendo paginação.', [
                        'status' => $response->status(),
                        'body' => $response->body()
                    ]);
                    break;
                }
            } catch (Exception $e) {
                Log::error('[BinanceConvertService] Erro crítico na chamada HTTP. Interrompendo paginação.', [
                    'error' => $e->getMessage()
                ]);
                break;
            }

            // Se chegamos ao início do período desejado, paramos.
            if ($currentWindowStart <= $loopStartTime) {
                Log::info('[BinanceConvertService] Alcançado o início do período de busca. Finalizando paginação.');
                break;
            }

            $loopEndTime = $currentWindowStart - 1;
            usleep(500000);
        }

        if ($iterationCount >= $maxIterations) {
            Log::warning('[BinanceConvertService] Número máximo de iterações atingido. Possível loop infinito evitado.');
        }

        Log::info('[BinanceConvertService] Busca por paginação concluída.', [
            'total_registros_encontrados' => count($allConversions),
            'iteracoes_realizadas' => $iterationCount
        ]);
        
        return $this->processConvertResponse($allConversions);
    }

    private function processConvertResponse(array $data): array
    {
        $uniqueData = collect($data)->unique('quoteId')->values()->all();
        
        $processed = [];
        foreach ($uniqueData as $item) {
            if (empty($item['quoteId']) || empty($item['fromAsset']) || empty($item['toAsset'])) {
                continue;
            }

            $processed[] = [
                'quoteId' => $item['quoteId'],
                'fromAsset' => $item['fromAsset'],
                'fromAmount' => (float)($item['fromAmount'] ?? 0),
                'toAsset' => $item['toAsset'],
                'toAmount' => (float)($item['toAmount'] ?? 0),
                'createTime' => $item['createTime'] ?? null,
            ];
        }

        Log::info('[BinanceConvertService] Dados brutos processados e validados.', ['count' => count($processed)]);
        return $processed;
    }
}