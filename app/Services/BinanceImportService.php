<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserApiKey;
use App\Models\Transaction;
use App\Models\CryptoAsset;
use App\Models\MonthlyAssetSnapshot;
use App\Models\TradingPair;
use App\Jobs\VerifyZeroValueTransactionsJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

class BinanceImportService
{
    // Cache genérico para preços: ['BTC' => ['2024-10-14' => ['price_usd' => 60000, 'price_brl' => 300000]]]
    private array $priceCache = [];
    // Cache para os símbolos de negociação válidos da Binance.
    private ?array $validSymbols = null;

    protected User $user;
    protected UserApiKey $apiKey;
    protected BinanceConvertService $convertService;
    protected TransactionImportCoverageService $coverageService;
    protected string $baseUrl = 'https://api.binance.com/api/v3';
    protected string $sapiBaseUrl = 'https://api.binance.com';

    public function __construct(User $user, ?int $apiKeyId = null)
    {
        $this->user = $user;
        $this->apiKey = UserApiKey::where('user_id', $this->user->id)
            ->whereHas('exchange', function ($q) {
                return $q->where('name', 'binance');
            })
            ->when($apiKeyId !== null, fn ($query) => $query->whereKey($apiKeyId))
            ->firstOrFail();
        $this->convertService = new BinanceConvertService($this->apiKey);
        $this->coverageService = app(TransactionImportCoverageService::class);
        Log::info("[BinanceImportService] Serviço inicializado para o usuário: {$this->user->id}");
    }

    // ===================================================================
    // PONTO DE ENTRADA PRINCIPAL
    // ===================================================================

public function runSmartImport(?int $year = null): array
{
    $year ??= now('America/Sao_Paulo')->year;
    $currentYear = now('America/Sao_Paulo')->year;

    if ($year < 2009 || $year > $currentYear) {
        throw new \InvalidArgumentException('O ano selecionado para sincronização é inválido.');
    }

    Log::info('[Binance] Iniciando sincronização anual incremental.', [
        'user_id' => $this->user->id,
        'api_key_id' => $this->apiKey->id,
        'year' => $year,
    ]);

    try {
        // O catálogo é atualizado, mas os snapshots antigos não são apagados nem reconstruídos.
        $this->prepareImport();
        $importResult = $this->runAnnualIncrementalImport($year);

        VerifyZeroValueTransactionsJob::dispatch(
            $this->user->id,
            $this->apiKey->exchange_id,
            true,
        )->onQueue('default');

        return [
            'success' => true,
            'year' => $year,
            ...$importResult,
            'verification' => [
                'status' => 'scheduled',
                'message' => 'A verificação de preços pendentes foi agendada.',
            ],
        ];
    } catch (\Throwable $exception) {
        Log::error('[Binance] Falha na sincronização anual incremental.', [
            'user_id' => $this->user->id,
            'api_key_id' => $this->apiKey->id,
            'year' => $year,
            'error' => $exception->getMessage(),
        ]);

        return [
            'success' => false,
            'year' => $year,
            'message' => 'Erro durante a sincronização: ' . $exception->getMessage(),
        ];
    }
}

    // ===================================================================
    // FASE 0: PREPARAÇÃO
    // ===================================================================

    private function prepareImport(): void
    {
        Log::info("======================================================================");
        Log::info("🔧 [Fase 0: Preparação] Atualizando dados base...");
        Log::info("======================================================================");
        $this->syncExchangeCatalog();
        $this->updateCryptoAssetsFromBinance();
        Log::info("✅ [Fase 0: Preparação] Concluída sem reconstruir snapshots históricos.");
    }

    /**
     * Sincroniza somente o ano selecionado. Competências já confirmadas pela API
     * são puladas, exceto o mês em curso, que pode receber eventos atrasados.
     */
    private function runAnnualIncrementalImport(int $year): array
    {
        $now = now('America/Sao_Paulo');
        $lastMonth = $year === $now->year ? $now->month : 12;
        $result = [
            'months_processed' => 0,
            'months_skipped' => 0,
            'conversions_imported' => 0,
            'deposits_imported' => 0,
            'withdrawals_imported' => 0,
            'months' => [],
        ];

        for ($month = 1; $month <= $lastMonth; $month++) {
            $monthStart = Carbon::create($year, $month, 1, 0, 0, 0, 'America/Sao_Paulo')->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();
            $isCurrentMonth = $year === $now->year && $month === $now->month;
            $monthResult = [
                'month' => $month,
                'label' => $monthStart->format('m/Y'),
                'events' => [],
            ];

            $monthResult['events']['spot_trade'] = $this->markSpotAsCsvRequired($year, $month, $isCurrentMonth);
            $monthResult['events']['asset_dividend'] = $this->markDividendAsCsvRequired($year, $month, $isCurrentMonth);

            foreach ([
                'convert' => fn () => $this->importConversionsForMonth($monthStart, $monthEnd),
                'deposit' => fn () => $this->importDepositsForMonth($monthStart, $monthEnd),
                'withdrawal' => fn () => $this->importWithdrawalsForMonth($monthStart, $monthEnd),
            ] as $eventType => $importer) {
                if (!$isCurrentMonth && $this->coverageService->wasApiCovered($this->user, $this->apiKey->exchange_id, $year, $month, $eventType)) {
                    $result['months_skipped']++;
                    $monthResult['events'][$eventType] = 'skipped';
                    continue;
                }

                try {
                    $imported = $importer();
                    $this->coverageService->recordApiCoverage(
                        $this->user,
                        $this->apiKey->exchange_id,
                        $year,
                        $month,
                        $eventType,
                        'completed',
                        $imported,
                    );
                    $monthResult['events'][$eventType] = 'completed';
                    $result[$eventType === 'convert' ? 'conversions_imported' : $eventType . 's_imported'] += $imported;
                } catch (\Throwable $exception) {
                    $this->coverageService->recordApiCoverage(
                        $this->user,
                        $this->apiKey->exchange_id,
                        $year,
                        $month,
                        $eventType,
                        'failed',
                        0,
                        $exception->getMessage(),
                    );
                    $monthResult['events'][$eventType] = 'failed';
                }
            }

            $result['months'][] = $monthResult;
            $result['months_processed']++;
        }

        return $result;
    }

    private function markSpotAsCsvRequired(int $year, int $month, bool $isCurrentMonth): string
    {
        if (!$isCurrentMonth && $this->coverageService->wasApiCovered($this->user, $this->apiKey->exchange_id, $year, $month, 'spot_trade')) {
            return 'skipped';
        }

        // A API oficial exige um símbolo por consulta. Sem uma lista histórica
        // completa de pares não é seguro declarar o mês Spot como coberto.
        $this->coverageService->recordApiCoverage(
            $this->user,
            $this->apiKey->exchange_id,
            $year,
            $month,
            'spot_trade',
            'partial',
            0,
            'A API Spot exige consulta por par; importe o CSV de Spot para confirmar a competência.',
        );

        return 'csv_required';
    }

    private function markDividendAsCsvRequired(int $year, int $month, bool $isCurrentMonth): string
    {
        if (!$isCurrentMonth && $this->coverageService->wasApiCovered($this->user, $this->apiKey->exchange_id, $year, $month, 'asset_dividend')) {
            return 'skipped';
        }

        // Dividendos, airdrops, staking e Simple Earn possuem classificações
        // fiscais distintas. O arquivo da Binance é a fonte de conferência.
        $this->coverageService->recordApiCoverage(
            $this->user,
            $this->apiKey->exchange_id,
            $year,
            $month,
            'asset_dividend',
            'partial',
            0,
            'Importe o CSV de dividendos, Earn, staking e recompensas para classificar os créditos corretamente.',
        );

        return 'csv_required';
    }

    private function importDepositsForMonth(Carbon $monthStart, Carbon $monthEnd): int
    {
        $records = $this->fetchCapitalHistory('/sapi/v1/capital/deposit/hisrec', $monthStart, $monthEnd);
        $imported = 0;

        foreach ($records as $record) {
            if ((int) ($record['status'] ?? -1) !== 1) {
                continue;
            }

            $this->saveDeposit($record);
            $imported++;
        }

        return $imported;
    }

    private function importWithdrawalsForMonth(Carbon $monthStart, Carbon $monthEnd): int
    {
        $records = $this->fetchCapitalHistory('/sapi/v1/capital/withdraw/history', $monthStart, $monthEnd);
        $imported = 0;

        foreach ($records as $record) {
            if ((int) ($record['status'] ?? -1) !== 6) {
                continue;
            }

            $this->saveWithdrawal($record);
            $imported++;
        }

        return $imported;
    }

    private function fetchCapitalHistory(string $endpoint, Carbon $monthStart, Carbon $monthEnd): array
    {
        $offset = 0;
        $allRecords = [];

        do {
            $response = $this->signedSapiRequest($endpoint, [
                'startTime' => $monthStart->getTimestampMs(),
                'endTime' => $monthEnd->getTimestampMs(),
                'offset' => $offset,
                'limit' => 1000,
            ]);

            if (!$response->successful()) {
                throw new Exception("Falha na consulta Binance {$endpoint}: {$response->body()}");
            }

            $records = $response->json() ?? [];
            if (!is_array($records)) {
                throw new Exception("Resposta inesperada da Binance para {$endpoint}.");
            }

            $allRecords = array_merge($allRecords, $records);
            $offset += count($records);
        } while (count($records) === 1000);

        return $allRecords;
    }

    private function saveDeposit(array $record): void
    {
        $asset = strtoupper((string) ($record['coin'] ?? ''));
        $amount = (float) ($record['amount'] ?? 0);
        $timestamp = $record['completeTime'] ?? $record['insertTime'] ?? null;

        if (!$asset || $amount <= 0 || !$timestamp) {
            return;
        }

        $date = Carbon::createFromTimestampMs((int) $timestamp);
        $assetId = CryptoAsset::firstOrCreate(['symbol' => $asset], ['name' => $asset])->id;
        $totalUsdt = $this->calculateTotalUsdt($asset, $amount, $date);

        Transaction::updateOrCreate(
            ['user_id' => $this->user->id, 'reference' => (string) ($record['id'] ?? $record['txId'])],
            [
                'source_type' => UserApiKey::class,
                'source_id' => $this->apiKey->id,
                'type' => 'deposit',
                'operation' => 'entrada',
                'to_asset' => $asset,
                'to_amount' => $amount,
                'to_crypto_asset_id' => $assetId,
                'total_usdt' => $totalUsdt,
                'total_brl' => $this->calculateTotalBrl($totalUsdt, $date),
                'txid' => $record['txId'] ?? null,
                'date' => $date,
                'source' => 'binance_deposit_api',
            ],
        );
    }

    private function saveWithdrawal(array $record): void
    {
        $asset = strtoupper((string) ($record['coin'] ?? ''));
        $amount = (float) ($record['amount'] ?? 0);
        $timestamp = $record['completeTime'] ?? $record['applyTime'] ?? null;

        if (!$asset || $amount <= 0 || !$timestamp) {
            return;
        }

        $date = is_numeric($timestamp)
            ? Carbon::createFromTimestampMs((int) $timestamp)
            : Carbon::parse($timestamp, 'America/Sao_Paulo');
        $assetId = CryptoAsset::firstOrCreate(['symbol' => $asset], ['name' => $asset])->id;
        $totalUsdt = $this->calculateTotalUsdt($asset, $amount, $date);

        Transaction::updateOrCreate(
            ['user_id' => $this->user->id, 'reference' => (string) ($record['id'] ?? $record['txId'])],
            [
                'source_type' => UserApiKey::class,
                'source_id' => $this->apiKey->id,
                'type' => 'withdrawal',
                'operation' => 'saida',
                'from_asset' => $asset,
                'from_amount' => $amount,
                'from_crypto_asset_id' => $assetId,
                'total_usdt' => $totalUsdt,
                'total_brl' => $this->calculateTotalBrl($totalUsdt, $date),
                'txid' => $record['txId'] ?? null,
                'date' => $date,
                'source' => 'binance_withdrawal_api',
            ],
        );
    }

    private function syncExchangeCatalog(): void
    {
        Log::info("📡 [Catálogo] Sincronizando pares e ativos da Binance via exchangeInfo...");

        try {
            $response = Http::timeout(30)
                ->retry(3, 500, function ($exception) {
                    $status = optional($exception->response)->status();
                    return in_array($status, [418, 429], true);
                })
                ->get('https://api.binance.com/api/v3/exchangeInfo');

            if (!$response->successful()) {
                Log::warning("⚠️ [Catálogo] Falha ao obter exchangeInfo.", ['status' => $response->status()]);
                return;
            }

            $payload = $response->json();
            $symbols = $payload['symbols'] ?? [];

            if (empty($symbols)) {
                Log::warning("⚠️ [Catálogo] exchangeInfo retornou nenhum símbolo.");
                return;
            }

            $assetsCreated = 0;
            $pairsSynced = 0;

            foreach ($symbols as $symbolInfo) {
                $base = $symbolInfo['baseAsset'] ?? null;
                $quote = $symbolInfo['quoteAsset'] ?? null;
                $symbol = $symbolInfo['symbol'] ?? null;

                if (!$base || !$quote || !$symbol) {
                    continue;
                }

                // Persistir ativos base e quote, caso ainda não existam
                if (CryptoAsset::firstOrCreate(['symbol' => $base], ['name' => $base])->wasRecentlyCreated) {
                    $assetsCreated++;
                }

                if (CryptoAsset::firstOrCreate(['symbol' => $quote], ['name' => $quote])->wasRecentlyCreated) {
                    $assetsCreated++;
                }

                TradingPair::updateOrCreate(
                    ['symbol' => $symbol],
                    [
                        'base_asset' => $base,
                        'quote_asset' => $quote,
                        'status' => $symbolInfo['status'] ?? null,
                        'is_spot_trading_allowed' => (bool)($symbolInfo['isSpotTradingAllowed'] ?? false),
                        'is_margin_trading_allowed' => (bool)($symbolInfo['isMarginTradingAllowed'] ?? false),
                        'filters' => $symbolInfo['filters'] ?? null,
                        'listed_at' => TradingPair::where('symbol', $symbol)->value('listed_at') ?? now(),
                        'delisted_at' => null,
                    ]
                );

                $pairsSynced++;
            }

            Log::info("✅ [Catálogo] Sincronização concluída.", [
                'pares_sincronizados' => $pairsSynced,
                'novos_ativos' => $assetsCreated,
            ]);
        } catch (Exception $e) {
            Log::error("❌ [Catálogo] Erro ao sincronizar exchangeInfo: " . $e->getMessage());
        }
    }

    private function updateCryptoAssetsFromBinance(): void
    {
        Log::info("📡 Buscando lista completa de ativos da conta Binance...");
        try {
            $response = $this->signedRequest('/account', []);
            if ($response->failed()) {
                throw new Exception("Falha ao buscar dados da conta Binance: " . $response->body());
            }
            $data = $response->json();
            if (!isset($data['balances'])) {
                throw new Exception("Resposta inesperada da API Binance.");
            }
            $assetsFound = 0;
            foreach ($data['balances'] as $balance) {
                CryptoAsset::firstOrCreate(['symbol' => $balance['asset']], ['name' => $balance['asset']]);
                $assetsFound++;
            }
            Log::info("✅ Total de ativos encontrados e atualizados: {$assetsFound}");
        } catch (Exception $e) {
            Log::error("❌ Exceção ao buscar dados da conta: " . $e->getMessage());
            throw $e;
        }
    }

    private function clearOldSnapshots(): void
    {
        $deleted = MonthlyAssetSnapshot::where('user_id', $this->user->id)
            ->where('exchange_id', $this->apiKey->exchange_id)
            ->delete();
        Log::info("🗑️ Snapshots antigos removidos: {$deleted}");
    }

    // ===================================================================
    // FASE 1: DESCOBERTA INTELIGENTE
    // ===================================================================

    private function ensureSnapshotsExist(): void
    {
        if (MonthlyAssetSnapshot::where('user_id', $this->user->id)->where('exchange_id', $this->apiKey->exchange_id)->exists()) {
            Log::info("✅ [Fase 1: Mapa] Snapshots mensais já existem. Pulando para a importação guiada.");
            return;
        }
        Log::info("🗺️ [Fase 1: Mapa] Nenhum snapshot encontrado. Iniciando descoberta inteligente...");
        $this->generateSnapshotsFromCryptoAssets();
    }

   
private function generateSnapshotsFromCryptoAssets(): void
{
    Log::info("======================================================================");
    Log::info("🔍 [Fase 1: Descoberta Inteligente] Iniciando varredura completa...");
    Log::info("======================================================================");

    // Definir período de busca: últimos 5 anos
    $startDate = Carbon::now()->subYears(5)->startOfMonth();
    $endDate = Carbon::now()->endOfMonth();
    $startTime = $startDate->getTimestampMs();
    $endTime = $endDate->getTimestampMs();

    Log::info("📅 Período de busca: {$startDate->format('Y-m-d')} até {$endDate->format('Y-m-d')}");
    Log::info("======================================================================");

    $monthlyAssets = [];
    
    // Descobrir ativos via catálogo de pares prioritários
    $assetsFromPairs = $this->discoverAssetsFromTradingPairs();
    Log::info("✅ Ativos encontrados via Catálogo de Pares: " . count($assetsFromPairs));
    
    // Descobrir ativos via conversões
    $assetsFromConversions = $this->discoverConversionsByInterval($monthlyAssets);
    Log::info("✅ Ativos encontrados via Conversões: " . count($assetsFromConversions));

    // Obter ativos da conta
    $assetsFromAccount = CryptoAsset::pluck('symbol')->toArray();
    Log::info("✅ Ativos encontrados na Conta (saldo >= 0): " . count($assetsFromAccount));

    // Combinar e remover duplicatas
    $masterAssetList = array_values(array_unique(array_merge(
        $assetsFromPairs,
        $assetsFromConversions,
        $assetsFromAccount
    )));
    Log::info("📊 Total de ativos únicos para investigar: " . count($masterAssetList));
    
    if (empty($masterAssetList)) {
        Log::warning("⚠️ Nenhum ativo encontrado para processar.");
        return;
    }

    Log::info("======================================================================");
    Log::info("🎯 Iniciando busca por Trades Spot (Modo Robusto)...");
    Log::info("======================================================================");

    $processedAssets = 0;
    $totalAssets = count($masterAssetList);
    $assetsWithTrades = 0;
    $totalTradesFound = 0;

    foreach ($masterAssetList as $asset) {
        $processedAssets++;
        $progress = round(($processedAssets / $totalAssets) * 100);
        Log::info("🔍 [{$processedAssets}/{$totalAssets}] ({$progress}%) Processando {$asset}...");

        // Construir pares de negociação para o ativo
        $pairs = $this->buildPairsForAsset($asset);
        
        if (empty($pairs)) {
            Log::info("   -> Nenhum par de negociação relevante encontrado para {$asset}. Pulando.");
            continue;
        }
        
        Log::info("   -> Pares para testar: " . implode(', ', $pairs));

        $assetHasTrades = false;

        foreach ($pairs as $pair) {
            try {
                // CORREÇÃO: Passar período de 5 anos em vez de 0, 0
                $trades = $this->fetchMyTrades($pair, $startTime, $endTime);
                
                if (!empty($trades)) {
                    Log::info("   🎉 SUCESSO! {$pair}: " . count($trades) . " trades encontrados.");
                    
                    $assetHasTrades = true;
                    $totalTradesFound += count($trades);
                    
                    $baseAsset = $this->getAssetFromSymbol($pair, 'base');
                    $quoteAsset = $this->getAssetFromSymbol($pair, 'quote');
                    
                    // Organizar trades por mês
                    foreach ($trades as $trade) {
                        $date = Carbon::createFromTimestampMs($trade['time']);
                        $monthKey = $date->format('Y-m');
                        
                        // Inicializar array do mês se não existir
                        if (!isset($monthlyAssets[$monthKey])) {
                            $monthlyAssets[$monthKey] = [];
                        }
                        
                        // Adicionar base asset
                        if ($baseAsset && !in_array($baseAsset, $monthlyAssets[$monthKey])) {
                            $monthlyAssets[$monthKey][] = $baseAsset;
                        }
                        
                        // Adicionar quote asset
                        if ($quoteAsset && !in_array($quoteAsset, $monthlyAssets[$monthKey])) {
                            $monthlyAssets[$monthKey][] = $quoteAsset;
                        }
                    }
                    
                    // Encontrou trades para este ativo, não precisa testar outros pares
                    break;
                }
                
            } catch (Exception $e) {
                Log::warning("   ⚠️ Erro ao buscar trades de {$pair}: " . $e->getMessage());
                continue;
            }
        }

        if ($assetHasTrades) {
            $assetsWithTrades++;
        }
    }

    Log::info("======================================================================");
    Log::info("📊 Estatísticas da Descoberta:");
    Log::info("   - Ativos processados: {$processedAssets}");
    Log::info("   - Ativos com trades: {$assetsWithTrades}");
    Log::info("   - Total de trades encontrados: {$totalTradesFound}");
    Log::info("======================================================================");
    Log::info("💾 Salvando snapshots mensais no banco de dados...");
    
    if (empty($monthlyAssets)) {
        Log::warning("⚠️ Nenhum mês com transações foi encontrado após a varredura completa.");
        Log::warning("   Isso pode indicar que não há trades nos últimos 5 anos ou há um problema na API.");
        return;
    }

    // Ordenar meses cronologicamente
    ksort($monthlyAssets);
    
    $snapshotsSaved = 0;
    
    foreach ($monthlyAssets as $monthKey => $assets) {
        [$year, $month] = explode('-', $monthKey);
        $uniqueAssets = array_values(array_unique($assets));
       
        

        MonthlyAssetSnapshot::updateOrCreate(
            [
                'user_id' => $this->user->id,
                'exchange_id' => $this->apiKey->exchange_id,
                'year' => (int)$year,
                'month' => (int)$month
            ],
            [
                'assets' => $uniqueAssets
            ]
        );
        
        $snapshotsSaved++;
        Log::info("   -> Snapshot salvo para {$monthKey}: " . count($uniqueAssets) . " ativos.");
    }
    
    Log::info("======================================================================");
    Log::info("🎉 [Fase 1: Descoberta Inteligente] Concluída com sucesso!");
    Log::info("   - Total de snapshots criados: {$snapshotsSaved}");
    Log::info("   - Período coberto: " . array_key_first($monthlyAssets) . " até " . array_key_last($monthlyAssets));
    Log::info("======================================================================");
}

    private function discoverAssetsFromTradingPairs(): array
    {
        $priorityQuotes = [
            'USDT', 'USDC', 'FDUSD', 'BUSD', 'TUSD', 'BRL', 'BTC', 'ETH', 'BNB'
        ];

        $pairs = TradingPair::query()
            ->whereIn('quote_asset', $priorityQuotes)
            ->orWhereIn('base_asset', $priorityQuotes)
            ->get(['base_asset', 'quote_asset']);

        if ($pairs->isEmpty()) {
            return [];
        }

        $assets = [];
        foreach ($pairs as $pair) {
            if (!empty($pair->base_asset)) {
                $assets[] = $pair->base_asset;
            }
            if (!empty($pair->quote_asset)) {
                $assets[] = $pair->quote_asset;
            }
        }

        return array_values(array_unique($assets));
    }

    private function discoverConversionsByInterval(array &$monthlyAssets): array
    {
        $startDate = Carbon::now()->subYears(5)->startOfMonth();
        $endDate = Carbon::now()->endOfMonth();
        $currentStart = $startDate->copy();
        $monthsProcessed = 0;
        $totalConversionsFound = 0;
        $allFoundAssets = [];

        while ($currentStart->lessThan($endDate)) {
            $currentEnd = $currentStart->copy()->endOfMonth();
            if ($currentEnd->greaterThan($endDate)) {
                $currentEnd = $endDate->copy();
            }

            $monthKey = $currentStart->format('Y-m');
            $monthsProcessed++;

            try {
                $conversions = $this->convertService->fetchConversions(
                    $currentStart->getTimestampMs(),
                    $currentEnd->getTimestampMs()
                );

                if (!empty($conversions)) {
                    Log::info("   ✅ {$monthKey}: " . count($conversions) . " conversões encontradas");
                    $totalConversionsFound += count($conversions);

                    foreach ($conversions as $conversion) {
                        $fromAsset = $conversion['fromAsset'] ?? null;
                        $toAsset = $conversion['toAsset'] ?? null;

                        if (!isset($monthlyAssets[$monthKey])) {
                            $monthlyAssets[$monthKey] = [];
                        }

                        if ($fromAsset) {
                            $allFoundAssets[] = $fromAsset;
                            if (!in_array($fromAsset, $monthlyAssets[$monthKey])) {
                                $monthlyAssets[$monthKey][] = $fromAsset;
                            }
                        }

                        if ($toAsset) {
                            $allFoundAssets[] = $toAsset;
                            if (!in_array($toAsset, $monthlyAssets[$monthKey])) {
                                $monthlyAssets[$monthKey][] = $toAsset;
                            }
                        }
                    }
                }

            } catch (Exception $e) {
                Log::debug("   ⚠️ {$monthKey}: Erro ao buscar conversões - " . $e->getMessage());
            }

            $currentStart = $currentStart->copy()->addMonth()->startOfMonth();
            usleep(250000); // reduzir velocidade para evitar rate limit da Binance
        }

        Log::info("📊 Conversões: {$monthsProcessed} meses processados, {$totalConversionsFound} conversões encontradas");

        if (!empty($monthlyAssets)) {
            $monthsWithConversions = count($monthlyAssets);
            Log::info("📊 Total de meses com conversões: {$monthsWithConversions}");
        }

        return array_unique($allFoundAssets);
    }

    // ===================================================================
    // FASE 2: IMPORTAÇÃO GUIADA
    // ===================================================================

    private function runGuidedImport(): array
{
    Log::info("======================================================================");
    Log::info("🚚 [Fase 2: Importação Guiada] Iniciando...");
    Log::info("======================================================================");

    $snapshots = MonthlyAssetSnapshot::where('user_id', $this->user->id)
        ->where('exchange_id', $this->apiKey->exchange_id)
        ->orderBy('year')
        ->orderBy('month')
        ->get();

    if ($snapshots->isEmpty()) {
        return [
            'success' => false, 
            'message' => 'Nenhum snapshot encontrado para guiar a importação.'
        ];
    }

    $totalSnapshots = $snapshots->count();
    $processedSnapshots = 0;
    $failedSnapshots = 0;
    $totalTradesImported = 0;
    $totalConversionsImported = 0;

    foreach ($snapshots as $snapshot) {
        $processedSnapshots++;
        $progress = round(($processedSnapshots / $totalSnapshots) * 100);
        $monthStart = Carbon::create($snapshot->year, $snapshot->month, 1)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $monthKey = $monthStart->format('Y-m');

        Log::info("📅 [Mês {$processedSnapshots}/{$totalSnapshots}] Processando {$monthKey} ({$progress}%)...");

        try {
            $assets = $snapshot->assets ?? [];
            
            if (empty($assets)) {
                Log::info("   -> Nenhum ativo para processar neste mês. Pulando.");
                continue;
            }

            // Importar trades spot
            $tradesImported = $this->importSpotTradesForMonth($assets, $monthStart, $monthEnd);
            $totalTradesImported += $tradesImported;

            // Importar conversões
            $conversionsImported = $this->importConversionsForMonth($monthStart, $monthEnd);
            $totalConversionsImported += $conversionsImported;

            Log::info("   ✅ {$monthKey}: {$tradesImported} trades + {$conversionsImported} conversões");

        } catch (Exception $e) {
            // Erro ao processar mês inteiro - registrar e continuar
            $failedSnapshots++;
            Log::error("   ❌ Falha ao processar mês {$monthKey}: " . $e->getMessage());
            Log::debug("   -> Detalhes do erro:", [
                'month' => $monthKey,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    Log::info("======================================================================");
    Log::info("🎉 [Fase 2: Importação Guiada] Concluída!");
    Log::info("======================================================================");
    Log::info("📊 Estatísticas:");
    Log::info("   - Meses processados: {$processedSnapshots}");
    
    if ($failedSnapshots > 0) {
        Log::warning("   - Meses com falhas: {$failedSnapshots}");
    }
    
    Log::info("   - Trades importados: {$totalTradesImported}");
    Log::info("   - Conversões importadas: {$totalConversionsImported}");
    
    return [
        'success' => true,
        'trades_imported' => $totalTradesImported,
        'conversions_imported' => $totalConversionsImported,
        'months_processed' => $processedSnapshots,
        'months_failed' => $failedSnapshots
    ];
}

   private function importSpotTradesForMonth(array $assets, Carbon $monthStart, Carbon $monthEnd): int
{
    $symbols = $this->buildSymbolsFromAssets($assets);
    if (empty($symbols)) {
        Log::info("   -> Nenhum símbolo construído para os ativos deste mês.");
        return 0;
    }

    $imported = 0;
    $failed = 0;
    
    Log::info("   -> Símbolos a processar: " . count($symbols));
    
    foreach ($symbols as $symbol) {
        try {
            // Buscar trades do símbolo
            $trades = $this->fetchMyTrades($symbol, $monthStart->getTimestampMs(), $monthEnd->getTimestampMs());
            
            if (empty($trades)) {
                Log::debug("   -> {$symbol}: Nenhum trade encontrado neste período.");
                continue;
            }
            
            Log::info("   -> {$symbol}: " . count($trades) . " trades encontrados. Processando...");
            
            foreach ($trades as $trade) {
                try {
                    // Filtro para garantir que o trade está dentro do mês
                    if ($trade['time'] >= $monthStart->getTimestampMs() && 
                        $trade['time'] <= $monthEnd->getTimestampMs()) {
                        
                        $this->saveSpotTrade($trade);
                        $imported++;
                    }
                } catch (Exception $e) {
                    // Erro ao salvar trade individual - registrar e continuar
                    $failed++;
                    $tradeId = $trade['id'] ?? 'N/A';
                    $tradeSymbol = $trade['symbol'] ?? $symbol;
                    
                    Log::warning("   ⚠️ Falha ao salvar trade {$tradeId} ({$tradeSymbol}): " . $e->getMessage());
                    
                    // Log detalhado apenas em modo debug
                    Log::debug("   -> Detalhes do erro:", [
                        'trade_id' => $tradeId,
                        'symbol' => $tradeSymbol,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            Log::info("✅ Total de trades importados para {$monthStart->format('Y-m')}: {$imported}");
            
        } catch (Exception $e) {
            // Erro ao buscar trades do símbolo - registrar e continuar com próximo símbolo
            Log::warning("   ⚠️ Falha ao processar símbolo {$symbol}: " . $e->getMessage());
            Log::debug("   -> Detalhes do erro:", [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    // Log resumido
    if ($failed > 0) {
        Log::warning("   ⚠️ Resumo: {$imported} trades importados, {$failed} falharam");
    } else {
        Log::info("   ✅ Total de trades importados: {$imported}");
    }
    
    return $imported;
}

    private function importConversionsForMonth(Carbon $monthStart, Carbon $monthEnd): int
    {
        $conversions = $this->convertService->getConvertHistory(
            $monthStart->getTimestampMs(),
            $monthEnd->getTimestampMs(),
        );

        foreach ($conversions as $conversion) {
            $this->saveConversion($conversion);
        }

        return count($conversions);
    }

    private function buildSymbolsFromAssets(array $assets): array
    {
        $validSymbols = $this->getValidSymbols();
        $generatedSymbols = [];
        $quoteAssets = ['USDT', 'BUSD', 'TUSD', 'FDUSD', 'USDC', 'BRL', 'BTC', 'ETH', 'BNB'];

        foreach ($assets as $base) {
            foreach ($quoteAssets as $quote) {
                if ($base !== $quote) {
                    $pair1 = $base . $quote;
                    if (in_array($pair1, $validSymbols)) $generatedSymbols[] = $pair1;

                    $pair2 = $quote . $base;
                    if (in_array($pair2, $validSymbols)) $generatedSymbols[] = $pair2;
                }
            }
        }
        return array_unique($generatedSymbols);
    }

    // ===================================================================
    // MÉTODOS DE BUSCA NA API E AUXILIARES
    // ===================================================================

 private function getValidSymbols(): array
{
    // Se já temos em cache, retornar
    if ($this->validSymbols !== null) {
        return $this->validSymbols;
    }
    
    Log::info("   -> [getValidSymbols] Buscando lista de símbolos válidos da Binance...");
    
    try {
        // Buscar informações de exchange da API pública
        $response = Http::timeout(15)->get('https://api.binance.com/api/v3/exchangeInfo');
        
        if ($response->successful()) {
            $data = $response->json();
            $symbols = [];
            
            // Extrair apenas símbolos que estão com status TRADING
            foreach ($data['symbols'] ?? [] as $symbolInfo) {
                if (($symbolInfo['status'] ?? '') === 'TRADING') {
                    $symbols[] = $symbolInfo['symbol'];
                }
            }
            
            $this->validSymbols = $symbols;
            Log::info("   -> [getValidSymbols] Total de símbolos válidos: " . count($symbols));
            
            return $symbols;
        } else {
            Log::error("   -> [getValidSymbols] Falha ao buscar símbolos da API", [
                'status' => $response->status()
            ]);
        }
    } catch (Exception $e) {
        Log::error("   -> [getValidSymbols] Exceção ao buscar símbolos", [
            'error' => $e->getMessage()
        ]);
    }
    
    // Se falhou, retornar array vazio
    $this->validSymbols = [];
    return [];
}
    private function buildPairsForAsset(string $asset): array
{
    // Moedas de cotação prioritárias para testar
    $mandatoryQuotes = [
        'USDT',   // Mais comum
        'FDUSD',  // Stablecoin alternativa
        'BUSD',   // Stablecoin da Binance (descontinuada mas ainda tem histórico)
        'TUSD',   // Stablecoin alternativa
        'USDC',   // Stablecoin alternativa
        'BRL',    // Para usuários brasileiros
        'BTC',    // Par com Bitcoin
        'ETH',    // Par com Ethereum
        'BNB',    // Par com Binance Coin
    ];
    
    $validSymbols = $this->getValidSymbols();
    $foundPairs = [];
    
    foreach ($mandatoryQuotes as $quote) {
        // Não tentar criar par do ativo consigo mesmo
        if ($asset === $quote) {
            continue;
        }
        
        // Tentar par na ordem: ATIVO + QUOTE (ex: BTCUSDT)
        $pair1 = $asset . $quote;
        if (in_array($pair1, $validSymbols)) {
            $foundPairs[] = $pair1;
        }
        
        // Tentar par na ordem inversa: QUOTE + ATIVO (ex: USDTBTC)
        $pair2 = $quote . $asset;
        if (in_array($pair2, $validSymbols)) {
            $foundPairs[] = $pair2;
        }
    }
    
    // Remover duplicatas e retornar
    return array_unique($foundPairs);
}

   private function fetchMyTrades(string $symbol, int $startTime, int $endTime): array
{
    Log::info("   -> [fetchMyTrades] Iniciando busca por ID para o símbolo: {$symbol}");
    Log::info("   -> [fetchMyTrades] Período: " . 
        Carbon::createFromTimestampMs($startTime)->format('Y-m-d') . " até " . 
        Carbon::createFromTimestampMs($endTime)->format('Y-m-d'));
    
    $allTrades = [];
    $lastId = 0;
    $loopCount = 0;
    $tradesBeforeRange = 0;
    $tradesAfterRange = 0;
    $consecutiveEmptyBatches = 0;

    while (true) {
        $loopCount++;
        
        // Proteção contra loops infinitos
        if ($loopCount > 1000) {
            Log::warning("   -> [fetchMyTrades] Limite de 1000 iterações atingido. Parando por segurança.");
            break;
        }
        
        try {
            $params = ['symbol' => $symbol, 'fromId' => $lastId, 'limit' => 1000];
            $response = $this->signedRequest('/myTrades', $params);

            if ($response->successful()) {
                $trades = $response->json();

          Log::debug("   -> [fetchMyTrades] Lote recebido para {$symbol}: "
             . count($trades) . " trades brutos (lastId {$lastId})");

                
                // Se não há mais trades, chegamos ao fim
                if (empty($trades)) {
                    Log::info("   -> [fetchMyTrades] Nenhum trade retornado. Fim do histórico.");
                    break;
                }

                // Filtrar trades dentro do intervalo de tempo
                $filteredTrades = [];
                $batchTradesAfter = 0;
                $batchTradesBefore = 0;
                
                foreach ($trades as $trade) {
                    $tradeTime = $trade['time'];
                    
                    // Se o trade é anterior ao início do período
                    if ($startTime > 0 && $tradeTime < $startTime) {
                        $tradesBeforeRange++;
                        $batchTradesBefore++;
                        continue;
                    }
                    
                    // Se o trade é posterior ao fim do período
                    if ($endTime > 0 && $tradeTime > $endTime) {
                        $tradesAfterRange++;
                        $batchTradesAfter++;
                        continue;
                    }
                    
                    // Trade está dentro do período
                    $filteredTrades[] = $trade;
                }

                // Adicionar trades filtrados ao resultado
                $allTrades = array_merge($allTrades, $filteredTrades);
                
                // Atualizar lastId para próxima iteração
                $lastTrade = end($trades);
                $lastId = $lastTrade['id'] + 1;

                // Log de progresso a cada 10 iterações
                if ($loopCount % 10 === 0) {
                    Log::info("   -> [fetchMyTrades] Iteração {$loopCount}: " . 
                        count($allTrades) . " trades coletados até agora");
                }

                // LÓGICA CORRIGIDA DE PARADA:
                
                // 1. Se todos os trades deste lote estão DEPOIS do período
                //    E já coletamos alguns trades válidos
                //    Então provavelmente já passamos do período desejado
                if ($batchTradesAfter > 0 && 
                    $batchTradesAfter === count($trades) && 
                    count($allTrades) > 0) {
                    Log::info("   -> [fetchMyTrades] Todos os trades do lote estão após o período e já temos trades coletados. Parando.");
                    break;
                }
                
                // 2. Se não há trades filtrados neste lote
                if (count($filteredTrades) === 0) {
                    $consecutiveEmptyBatches++;
                    
                    // Se tivemos 3 lotes consecutivos sem trades válidos
                    // E já coletamos alguns trades
                    // Provavelmente já passamos do período
                    if ($consecutiveEmptyBatches >= 3 && count($allTrades) > 0) {
                        Log::info("   -> [fetchMyTrades] 3 lotes consecutivos sem trades válidos. Parando.");
                        break;
                    }
                } else {
                    // Reset do contador se encontramos trades válidos
                    $consecutiveEmptyBatches = 0;
                }

                // 3. Se o lote retornou menos de 1000 trades, chegamos ao fim do histórico
                if (count($trades) < 1000) {
                    Log::info("   -> [fetchMyTrades] Lote com menos de 1000 trades. Fim do histórico.");
                    break;
                }
                
            } else {
                $error = $response->json() ?? ['code' => 'N/A', 'msg' => $response->body()];
                
                if (isset($error['code']) && $error['code'] == -1121) {
                    Log::info("   -> [fetchMyTrades] Símbolo {$symbol} inválido. Fim da busca.");
                } else {
                    Log::error("   -> [fetchMyTrades] Falha na API para {$symbol}. Parando.", [
                        'status' => $response->status(), 
                        'error' => $error
                    ]);
                }
                break;
            }
            
        } catch (Exception $e) {
            Log::error("   -> [fetchMyTrades] Exceção para {$symbol}. Parando.", [
                'error' => $e->getMessage()
            ]);
            break;
        }
        
        // Rate limiting: aguardar 250ms entre requisições
        usleep(250000);
    }
    
    // Log final
    Log::info("   -> [fetchMyTrades] Busca para {$symbol} concluída:");
    Log::info("      - Total de iterações: {$loopCount}");
    Log::info("      - Trades no período: " . count($allTrades));
    Log::info("      - Trades antes do período: {$tradesBeforeRange}");
    Log::info("      - Trades depois do período: {$tradesAfterRange}");
    
    return $allTrades;
}

    private function signedRequest(string $endpoint, array $params = []): \Illuminate\Http\Client\Response
    {
        return $this->signedGet($this->baseUrl . $endpoint, $params);
    }

    private function signedSapiRequest(string $endpoint, array $params = []): \Illuminate\Http\Client\Response
    {
        return $this->signedGet($this->sapiBaseUrl . $endpoint, $params);
    }

    private function signedGet(string $requestUrl, array $params = []): \Illuminate\Http\Client\Response
    {
        if (!isset($params['timestamp'])) {
            $params['timestamp'] = (int) (microtime(true) * 1000);
        }

        if (!isset($params['recvWindow'])) {
            $params['recvWindow'] = 60000;
        }

        $queryString = http_build_query($params, '', '&');
        $params['signature'] = hash_hmac('sha256', $queryString, $this->apiKey->secret_key);

        return Http::withHeaders([
            'X-MBX-APIKEY' => $this->apiKey->api_key,
        ])->get($requestUrl, $params);
    }

    // ===================================================================
    // MÉTODOS DE SALVAMENTO E CÁLCULO DE PREÇO
    // ===================================================================

    private function saveSpotTrade(array $trade): void
    {
        $symbol = $trade['symbol'];
        $baseAsset = $this->getAssetFromSymbol($symbol, 'base');
        $quoteAsset = $this->getAssetFromSymbol($symbol, 'quote');

        if (!$baseAsset || !$quoteAsset) {
            Log::warning("⚠️ Não foi possível extrair ativos do símbolo: {$symbol}");
            return;
        }

        $date = Carbon::createFromTimestampMs($trade['time']);
        $isBuyer = $trade['isBuyer'];

        if ($symbol === 'USDTBRL') {
            $this->priceCache['USDT'][$date->toDateString()] = ['price_usd' => 1.0, 'price_brl' => (float)$trade['price']];
            Log::info("   -> [Cache de Preço] Preço do USDT/BRL para {$date->toDateString()} cacheado diretamente do trade: " . $trade['price']);
        }

        $fromAsset = $isBuyer ? $quoteAsset : $baseAsset;
        $toAsset = $isBuyer ? $baseAsset : $quoteAsset;
        $fromAmount = $isBuyer ? (float)$trade['quoteQty'] : (float)$trade['qty'];
        $toAmount = $isBuyer ? (float)$trade['qty'] : (float)$trade['quoteQty'];

        $fromAssetId = CryptoAsset::firstOrCreate(['symbol' => $fromAsset], ['name' => $fromAsset])->id;
        $toAssetId = CryptoAsset::firstOrCreate(['symbol' => $toAsset], ['name' => $toAsset])->id;

        $totalUsdt = 0.0;
        $totalBrl = 0.0;
        $stablecoins = ['USDT', 'BUSD', 'TUSD', 'FDUSD', 'USDC'];

        if (in_array($quoteAsset, $stablecoins)) {
            $totalUsdt = (float)$trade['quoteQty'];
            $totalBrl = $this->calculateTotalBrl($totalUsdt, $date);
        } elseif ($quoteAsset === 'BRL') {
            $totalBrl = (float)$trade['quoteQty'];
            if (in_array($baseAsset, $stablecoins)) {
                $totalUsdt = (float)$trade['qty'];
            } else {
                $totalUsdt = $this->calculateTotalUsdt($baseAsset, (float)$trade['qty'], $date);
            }
        } else {
            $quotePrices = $this->getHistoricalPrice($quoteAsset, $date);
            if ($quotePrices['price_usd'] > 0) {
                $totalUsdt = (float)$trade['quoteQty'] * $quotePrices['price_usd'];
                $totalBrl = $this->calculateTotalBrl($totalUsdt, $date);
            } else {
                $assetPrices = $this->getHistoricalPrice($toAsset, $date);
                if ($assetPrices['price_usd'] > 0) {
                    $totalUsdt = $assetPrices['price_usd'] * $toAmount;
                    $totalBrl = $this->calculateTotalBrl($totalUsdt, $date);
                } else {
                    Log::warning("   -> [saveSpotTrade] Não foi possível obter preço para {$baseAsset}/{$quoteAsset} em {$date->toDateString()}. Valores permanecerão como 0.");
                }
            }
        }

        Transaction::updateOrCreate(
            ['user_id' => $this->user->id, 'reference' => $trade['id']],
            [
                'source_type' => 'App\\Models\\UserApiKey',
                'source_id' => $this->apiKey->id,
                'type' => 'trade',
                'from_asset' => $fromAsset,
                'from_amount' => $fromAmount,
                'from_crypto_asset_id' => $fromAssetId,
                'to_asset' => $toAsset,
                'to_amount' => $toAmount,
                'to_crypto_asset_id' => $toAssetId,
                'price' => (float)$trade['price'],
                'total_usdt' => $totalUsdt,
                'total_brl' => $totalBrl,
                'date' => $date,
                'source' => 'binance_spot_api'
            ]
        );
    }

    private function saveConversion(array $conv): void
    {
        $date = Carbon::createFromTimestampMs($conv['createTime']);
        $fromAssetSymbol = $conv['fromAsset'];
        $toAssetSymbol = $conv['toAsset'];

        $fromAssetId = CryptoAsset::firstOrCreate(['symbol' => $fromAssetSymbol], ['name' => $fromAssetSymbol])->id;
        $toAssetId = CryptoAsset::firstOrCreate(['symbol' => $toAssetSymbol], ['name' => $toAssetSymbol])->id;

        $totalUsdt = $this->calculateTotalUsdt($toAssetSymbol, (float)$conv['toAmount'], $date);
        $totalBrl = $this->calculateTotalBrl($totalUsdt, $date);

        Transaction::updateOrCreate(
            ['user_id' => $this->user->id, 'reference' => $conv['quoteId']],
            [
                'source_type' => 'App\\Models\\UserApiKey',
                'source_id' => $this->apiKey->id,
                'type' => 'convert',
                'from_asset' => $fromAssetSymbol,
                'from_amount' => (float)$conv['fromAmount'],
                'from_crypto_asset_id' => $fromAssetId,
                'to_asset' => $toAssetSymbol,
                'to_amount' => (float)$conv['toAmount'],
                'to_crypto_asset_id' => $toAssetId,
                'price' => (float)$conv['toAmount'] > 0 ? (float)$conv['fromAmount'] / (float)$conv['toAmount'] : 0,
                'total_usdt' => $totalUsdt,
                'total_brl' => $totalBrl,
                'date' => $date,
                'source' => 'binance_convert_api'
            ]
        );
    }

    private function getHistoricalPrice(string $asset, Carbon $date): array
    {
        $dateKey = $date->toDateString();
        if (isset($this->priceCache[$asset][$dateKey])) {
            return $this->priceCache[$asset][$dateKey];
        }

        $prices = $this->getHistoricalPriceFromBinance($asset, $date);
        $this->priceCache[$asset][$dateKey] = $prices;

        return $prices;
    }

    private function calculateTotalUsdt(string $asset, float $amount, Carbon $date): float
    {
        if (in_array($asset, ['USDT', 'BUSD', 'TUSD', 'FDUSD', 'USDC'])) {
            return $amount;
        }
        $prices = $this->getHistoricalPrice($asset, $date);
        return $amount * $prices['price_usd'];
    }

    private function calculateTotalBrl(float $totalUsdt, Carbon $date): float
    {
        if ($totalUsdt == 0) return 0;
        $usdtPrices = $this->getHistoricalPrice('USDT', $date);
        if ($usdtPrices['price_brl'] == 0) {
            Log::error("   -> [Preço BRL] Impossível converter para BRL pois a cotação do dólar para {$date->toDateString()} não foi encontrada.");
            return 0;
        }
        return $totalUsdt * $usdtPrices['price_brl'];
    }

    private function getHistoricalPriceFromBinance(string $asset, Carbon $date): array
    {
        $prices = ['price_usd' => 0.0, 'price_brl' => 0.0];
        $originalAsset = strtoupper($asset);
        $normalizedAsset = $this->normalizeAssetSymbol($originalAsset);

        if ($normalizedAsset === '') {
            Log::warning("   -> [Preço Histórico] Símbolo inválido recebido para cálculo: {$originalAsset}");
            return $prices;
        }

        if ($normalizedAsset !== $originalAsset) {
            Log::debug("   -> [Preço Histórico] Normalizando símbolo {$originalAsset} -> {$normalizedAsset}");
        }

        $stableQuotes = ['USDT', 'BUSD', 'FDUSD', 'USDC', 'TUSD'];

        if (in_array($normalizedAsset, $stableQuotes, true)) {
            $prices['price_usd'] = 1.0;
        } elseif ($normalizedAsset === 'BRL') {
            $usdtBrl = $this->fetchKlinePrice('USDTBRL', $date) ?? $this->getCurrentPriceFromBinance('USDTBRL');
            if (!empty($usdtBrl) && $usdtBrl > 0) {
                $prices['price_brl'] = 1.0;
                $prices['price_usd'] = 1 / $usdtBrl;
            }
        } else {
            $directQuotes = [
                $normalizedAsset . 'USDT',
                $normalizedAsset . 'BUSD',
                $normalizedAsset . 'FDUSD',
                $normalizedAsset . 'USDC',
                $normalizedAsset . 'TUSD',
            ];

            foreach ($directQuotes as $symbol) {
                $price = $this->fetchKlinePrice($symbol, $date);
                if (!empty($price)) {
                    $prices['price_usd'] = (float)$price;
                    break;
                }
            }

            if ($prices['price_usd'] === 0.0) {
                $prices['price_usd'] = $this->getPriceViaBridge($normalizedAsset, 'USDT', $date, $originalAsset) ?? 0.0;
            }

            if ($prices['price_usd'] === 0.0) {
                $currentPrice = $this->getCurrentPriceFromBinance($normalizedAsset . 'USDT');
                if (!empty($currentPrice)) {
                    $prices['price_usd'] = (float)$currentPrice;
                    Log::notice("   -> [Preço Atual] Usando preço atual de {$originalAsset} via ticker/price como fallback.");
                }
            }
        }

        if ($prices['price_usd'] > 0) {
            $usdtBrl = $this->fetchKlinePrice('USDTBRL', $date) ?? $this->getCurrentPriceFromBinance('USDTBRL');
            if (!empty($usdtBrl)) {
                $prices['price_brl'] = $prices['price_usd'] * (float)$usdtBrl;
            }
        }

        if ($prices['price_usd'] === 0.0) {
            Log::warning("   -> [Preço Histórico] Não foi possível obter preço de {$originalAsset} para {$date->toDateString()} na Binance.");
        }

        return $prices;
    }

    private function fetchKlinePrice(string $symbol, Carbon $date): ?float
    {
        $symbol = strtoupper($symbol);
        $startTime = $date->copy()->startOfDay()->timestamp * 1000;
        $endTime = $date->copy()->endOfDay()->timestamp * 1000;

        try {
            $response = Http::retry(3, 200)->get("{$this->baseUrl}/klines", [
                'symbol' => $symbol,
                'interval' => '1d',
                'startTime' => $startTime,
                'endTime' => $endTime,
                'limit' => 1,
            ]);

            if ($response->failed()) {
                Log::debug("   -> [fetchKlinePrice] Falha ao buscar klines para {$symbol}.", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            if (empty($data)) {
                return null;
            }

            $kline = $data[0];
            $high = isset($kline[2]) ? (float)$kline[2] : null;
            $low = isset($kline[3]) ? (float)$kline[3] : null;

            if ($high !== null && $low !== null) {
                return ($high + $low) / 2;
            }
        } catch (Exception $e) {
            Log::debug("   -> [fetchKlinePrice] Exceção ao buscar klines para {$symbol}: {$e->getMessage()}");
        }

        return null;
    }

    private function getPriceViaBridge(string $asset, string $targetQuote, Carbon $date, ?string $originalAsset = null): ?float
    {
        $originalAsset = $originalAsset ?? $asset;
        $asset = $this->normalizeAssetSymbol($asset);
        $targetQuote = $this->normalizeAssetSymbol($targetQuote);

        if ($asset === '' || $targetQuote === '') {
            return null;
        }

        $bridges = ['BTC', 'ETH', 'BNB', 'USDT', 'BUSD', 'FDUSD', 'USDC'];

        foreach ($bridges as $bridge) {
            if ($bridge === $asset || $bridge === $targetQuote) {
                continue;
            }

            $basePair = $asset . $bridge;
            $inverseBasePair = $bridge . $asset;
            $bridgePair = $bridge . $targetQuote;
            $inverseBridgePair = $targetQuote . $bridge;

            $basePrice = $this->fetchKlinePrice($basePair, $date);
            if (empty($basePrice)) {
                $baseInverse = $this->fetchKlinePrice($inverseBasePair, $date);
                if (!empty($baseInverse) && $baseInverse > 0) {
                    $basePrice = 1 / $baseInverse;
                }
            }

            if (empty($basePrice)) {
                continue;
            }

            $bridgePrice = $this->fetchKlinePrice($bridgePair, $date);
            if (empty($bridgePrice)) {
                $bridgeInverse = $this->fetchKlinePrice($inverseBridgePair, $date);
                if (!empty($bridgeInverse) && $bridgeInverse > 0) {
                    $bridgePrice = 1 / $bridgeInverse;
                }
            }

            if (empty($bridgePrice)) {
                $currentBridge = $this->getCurrentPriceFromBinance($bridgePair);
                if (empty($currentBridge)) {
                    $currentInverse = $this->getCurrentPriceFromBinance($inverseBridgePair);
                    if (!empty($currentInverse) && $currentInverse > 0) {
                        $currentBridge = 1 / $currentInverse;
                    }
                }

                $bridgePrice = $currentBridge;
            }

            if (!empty($bridgePrice)) {
                Log::info("   -> [Bridge] {$originalAsset} via {$bridge} -> {$targetQuote} em {$date->toDateString()}.");
                return (float)$basePrice * (float)$bridgePrice;
            }
        }

        return null;
    }

    private function getCurrentPriceFromBinance(string $symbol): ?float
    {
        $symbol = strtoupper($symbol);

        try {
            $response = Http::retry(3, 200)->get("{$this->baseUrl}/ticker/price", [
                'symbol' => $symbol,
            ]);

            if ($response->failed()) {
                Log::debug("   -> [getCurrentPriceFromBinance] Falha ao buscar ticker para {$symbol}.", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $price = $response->json('price');
            return $price !== null ? (float)$price : null;
        } catch (Exception $e) {
            Log::debug("   -> [getCurrentPriceFromBinance] Exceção ao buscar ticker para {$symbol}: {$e->getMessage()}");
        }

        return null;
    }

    private function getAssetFromSymbol(string $symbol, string $part): ?string
    {
        // Lista de moedas de cotação conhecidas, ordenadas por tamanho (maiores primeiro)
        // Importante: BTC, ETH, BNB devem vir antes de outras para evitar conflitos
        $knownQuotes = [
            'USDT',   // Tether
            'BUSD',   // Binance USD
            'TUSD',   // TrueUSD
            'FDUSD',  // First Digital USD
            'USDC',   // USD Coin
            'BRL',    // Real Brasileiro
            'EUR',    // Euro
            'GBP',    // Libra Esterlina
            'BTC',    // Bitcoin
            'ETH',    // Ethereum
            'BNB',    // Binance Coin
            'TRY',    // Lira Turca
            'RUB',    // Rublo Russo
            'UAH',    // Hryvnia Ucraniana
            'NGN',    // Naira Nigeriana
            'VAI',    // VAI Stablecoin
            'BIDR',   // Binance IDR
        ];
        
        // Tentar encontrar a moeda de cotação no final do símbolo
        foreach ($knownQuotes as $quote) {
            if (str_ends_with($symbol, $quote)) {
                // Extrair o ativo base removendo a cotação do final
                $base = substr($symbol, 0, -strlen($quote));
                
                // Validar que o base não está vazio
                if (empty($base)) {
                    continue;
                }
                
                // Retornar o que foi solicitado
                return $part === 'base' ? $base : $quote;
            }
        }
        
        // Se não conseguiu identificar, retornar null
        Log::warning("   -> [getAssetFromSymbol] Não foi possível extrair ativos do símbolo: {$symbol}");
        return null;
    }

    private function normalizeAssetSymbol(string $symbol): string
    {
        // Remove caracteres que não sejam letras maiúsculas ou números.
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($symbol));
    }
}
