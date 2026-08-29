<?php

namespace App\Services;

use App\Models\CryptoAsset;
use App\Models\Network;
use App\Models\Portfolio;
use App\Models\PortfolioSnapshot;
use App\Models\User;
use App\Models\UserApiKey;
use App\Models\Wallet;
use App\Models\WalletBalance;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sincroniza os saldos Spot atuais das chaves Binance para a estrutura de
 * carteiras consumida pelo Portfólio e importa os snapshots diários Spot que
 * a Binance ainda disponibiliza. Não altera transações fiscais.
 */
class BinancePortfolioSyncService
{
    private const STABLECOINS = ['USDT', 'USDC', 'BUSD', 'DAI', 'TUSD', 'FDUSD'];
    private const USD_QUOTES = ['USDT', 'BUSD', 'FDUSD', 'USDC'];

    public function __construct(private readonly CryptoPriceService $priceService)
    {
    }

    /**
     * @return array{keys_processed:int, wallets_updated:int, balances_updated:int, assets_with_balance:int, prices_updated:int, prices_unavailable:int, historical_snapshots_imported:int, historical_snapshots_unpriced:int}
     */
    public function sync(User $user): array
    {
        $apiKeys = UserApiKey::query()
            ->with('exchange:id,name')
            ->where('user_id', $user->id)
            ->whereHas('exchange', fn ($query) => $query->whereRaw('LOWER(name) = ?', ['binance']))
            ->get();

        $result = [
            'keys_processed' => 0,
            'wallets_updated' => 0,
            'balances_updated' => 0,
            'assets_with_balance' => 0,
            'prices_updated' => 0,
            'prices_unavailable' => 0,
            'historical_snapshots_imported' => 0,
            'historical_snapshots_unpriced' => 0,
        ];

        if ($apiKeys->isEmpty()) {
            return $result;
        }

        $tickers = $this->marketTickers();
        $ptax = $this->priceService->getUsdToBrlRate(Carbon::now('America/Sao_Paulo'));
        $network = Network::query()->firstOrCreate(
            ['slug' => 'binance-exchange'],
            ['name' => 'Binance (Exchange)', 'explorer_url' => null],
        );

        foreach ($apiKeys as $apiKey) {
            $wallet = $this->walletForApiKey($user, $apiKey, $network->id);
            $balances = $this->fetchSpotBalances($apiKey);

            // A fotografia da Binance é a fonte do saldo atual. Antes de gravar
            // os retornos, neutralizamos ativos que deixaram de aparecer na resposta.
            $wallet->balances()->update([
                'available' => 0,
                'locked' => 0,
                'retrieved_at' => now(),
            ]);

            $result['keys_processed']++;
            $result['wallets_updated']++;

            foreach ($balances as $balance) {
                $asset = strtoupper(trim((string) ($balance['asset'] ?? '')));
                $available = (float) ($balance['free'] ?? 0);
                $locked = (float) ($balance['locked'] ?? 0);

                if ($asset === '') {
                    continue;
                }

                WalletBalance::query()->updateOrCreate(
                    ['wallet_id' => $wallet->id, 'asset' => $asset],
                    [
                        'available' => $available,
                        'locked' => $locked,
                        'retrieved_at' => now(),
                    ],
                );
                $result['balances_updated']++;

                if (($available + $locked) <= 0) {
                    continue;
                }

                $result['assets_with_balance']++;
                $assetModel = CryptoAsset::query()->firstOrCreate(
                    ['symbol' => $asset],
                    ['name' => $asset],
                );

                $price = $this->currentPrice($asset, $tickers, $ptax);
                if ($price === null) {
                    $result['prices_unavailable']++;
                    continue;
                }

                $assetModel->update([
                    'current_price_usd' => $price['usd'],
                    'current_price_brl' => $price['brl'],
                    'price_change_24h' => $price['change_24h'],
                    'price_updated_at' => now(),
                    'market_data_updated_at' => now(),
                ]);
                $result['prices_updated']++;
            }
        }

        $this->importDailyAccountSnapshots($user, $apiKeys, $result);

        Log::info('[Portfólio] Saldos Binance sincronizados.', [
            'user_id' => $user->id,
            ...$result,
        ]);

        return $result;
    }

    /**
     * Preenche o histórico recente com a fotografia diária oficial da conta.
     * A Binance já fornece o total consolidado em BTC; assim fazemos somente
     * uma precificação histórica por dia, sem disparar uma chamada por ativo.
     *
     * @param Collection<int, UserApiKey> $apiKeys
     * @param array<string, int> $result
     */
    private function importDailyAccountSnapshots(User $user, Collection $apiKeys, array &$result): void
    {
        $snapshotsByDate = [];

        foreach ($apiKeys as $apiKey) {
            try {
                foreach ($this->fetchDailyAccountSnapshots($apiKey) as $snapshot) {
                    $timestamp = (int) ($snapshot['updateTime'] ?? 0);
                    if ($timestamp <= 0) {
                        continue;
                    }

                    $date = Carbon::createFromTimestampMs($timestamp, 'UTC')->startOfDay();
                    $dateKey = $date->toDateString();
                    $snapshotsByDate[$dateKey] ??= [
                        'date' => $date,
                        'total_btc' => 0.0,
                        'assets' => [],
                    ];
                    $snapshotsByDate[$dateKey]['total_btc'] += (float) data_get($snapshot, 'data.totalAssetOfBtc', 0);

                    foreach ((array) data_get($snapshot, 'data.balances', []) as $balance) {
                        $symbol = strtoupper(trim((string) ($balance['asset'] ?? '')));
                        $quantity = (float) ($balance['free'] ?? 0) + (float) ($balance['locked'] ?? 0);
                        if ($symbol !== '' && $quantity > 0) {
                            $snapshotsByDate[$dateKey]['assets'][$symbol] =
                                ($snapshotsByDate[$dateKey]['assets'][$symbol] ?? 0.0) + $quantity;
                        }
                    }
                }
            } catch (\Throwable $exception) {
                // O histórico é complementar: uma restrição desse endpoint não
                // deve desfazer a sincronização bem-sucedida do saldo atual.
                Log::warning('[Portfólio] Histórico diário Binance indisponível.', [
                    'user_id' => $user->id,
                    'api_key_id' => $apiKey->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($snapshotsByDate === []) {
            return;
        }

        $portfolio = Portfolio::query()->firstOrCreate(
            ['user_id' => $user->id, 'name' => 'Portfolio Principal'],
            ['is_active' => true],
        );

        ksort($snapshotsByDate);
        foreach ($snapshotsByDate as $snapshotData) {
            $existing = PortfolioSnapshot::query()
                ->where('portfolio_id', $portfolio->id)
                ->whereDate('snapshot_date', $snapshotData['date'])
                ->first();

            // Um snapshot calculado pelo próprio sistema possui custo e P&L e é
            // mais completo; nunca o substituímos pelo resumo vindo da Binance.
            if ($existing && data_get($existing->data, 'source') !== 'binance_account_snapshot') {
                continue;
            }

            $btcPrice = $this->priceService->getOrCreatePrice('BTC', $snapshotData['date']);
            $btcPriceBrl = (float) ($btcPrice->price_brl ?? 0);
            if ($btcPriceBrl <= 0) {
                $result['historical_snapshots_unpriced']++;
                continue;
            }

            $historicalSnapshot = $existing ?? new PortfolioSnapshot();
            $historicalSnapshot->fill([
                'portfolio_id' => $portfolio->id,
                'total_value_brl' => round($snapshotData['total_btc'] * $btcPriceBrl, 2),
                'total_value_usd' => null,
                'total_pnl' => null,
                'snapshot_date' => $snapshotData['date'],
                'data' => [
                    'source' => 'binance_account_snapshot',
                    'total_asset_btc' => $snapshotData['total_btc'],
                    'assets' => collect($snapshotData['assets'])->map(
                        fn (float $quantity, string $symbol) => compact('symbol', 'quantity')
                    )->values()->all(),
                ],
            ])->save();
            $result['historical_snapshots_imported']++;
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchDailyAccountSnapshots(UserApiKey $apiKey): array
    {
        $params = [
            'type' => 'SPOT',
            'startTime' => now('UTC')->subDays(29)->startOfDay()->getTimestampMs(),
            'endTime' => now('UTC')->getTimestampMs(),
            'limit' => 30,
            'timestamp' => (int) round(microtime(true) * 1000),
            'recvWindow' => 15000,
        ];
        $params['signature'] = hash_hmac('sha256', http_build_query($params), $apiKey->secret_key);

        $response = Http::timeout(30)
            ->withHeaders(['X-MBX-APIKEY' => $apiKey->api_key])
            ->get('https://api.binance.com/sapi/v1/accountSnapshot', $params);

        if (!$response->successful()) {
            throw new RuntimeException('Não foi possível consultar snapshots Spot da Binance: HTTP ' . $response->status());
        }

        $snapshots = $response->json('snapshotVos', []);
        if (!is_array($snapshots)) {
            throw new RuntimeException('A Binance retornou um histórico de saldo inválido.');
        }

        return $snapshots;
    }

    private function walletForApiKey(User $user, UserApiKey $apiKey, int $networkId): Wallet
    {
        return Wallet::query()->firstOrCreate(
            ['address' => "exchange:binance:api-key:{$apiKey->id}"],
            [
                'user_id' => $user->id,
                'name' => "Binance Spot (chave {$apiKey->id})",
                'network_id' => $networkId,
                'description' => 'Carteira consolidada automaticamente a partir do saldo Spot da Binance.',
            ],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchSpotBalances(UserApiKey $apiKey): array
    {
        $params = [
            'timestamp' => (int) round(microtime(true) * 1000),
            'recvWindow' => 15000,
        ];
        $params['signature'] = hash_hmac('sha256', http_build_query($params), $apiKey->secret_key);

        $response = Http::timeout(20)
            ->withHeaders(['X-MBX-APIKEY' => $apiKey->api_key])
            ->get('https://api.binance.com/api/v3/account', $params);

        if (!$response->successful()) {
            throw new RuntimeException('Não foi possível consultar o saldo Spot da Binance: HTTP ' . $response->status());
        }

        $balances = $response->json('balances', []);

        if (!is_array($balances)) {
            throw new RuntimeException('A Binance retornou um formato de saldo inválido.');
        }

        return $balances;
    }

    private function marketTickers(): Collection
    {
        try {
            $response = Http::timeout(30)->get('https://api.binance.com/api/v3/ticker/24hr');
            if ($response->successful() && is_array($response->json())) {
                return collect($response->json())->keyBy('symbol');
            }
        } catch (\Throwable $exception) {
            Log::warning('[Portfólio] Não foi possível atualizar preços atuais Binance.', [
                'error' => $exception->getMessage(),
            ]);
        }

        return collect();
    }

    /**
     * @param Collection<string, array<string, mixed>> $tickers
     * @return array{usd:float, brl:float, change_24h:float|null}|null
     */
    private function currentPrice(string $symbol, Collection $tickers, ?float $ptax): ?array
    {
        if ($symbol === 'BRL') {
            return [
                'usd' => $ptax && $ptax > 0 ? round(1 / $ptax, 10) : null,
                'brl' => 1.0,
                'change_24h' => 0.0,
            ];
        }

        if (in_array($symbol, self::STABLECOINS, true)) {
            return $ptax && $ptax > 0
                ? ['usd' => 1.0, 'brl' => $ptax, 'change_24h' => 0.0]
                : null;
        }

        $tickerUsd = null;
        foreach (self::USD_QUOTES as $quote) {
            $candidate = $tickers->get("{$symbol}{$quote}");
            if ($candidate && (float) ($candidate['lastPrice'] ?? 0) > 0) {
                $tickerUsd = $candidate;
                break;
            }
        }

        $tickerBrl = $tickers->get("{$symbol}BRL");
        $priceUsd = (float) ($tickerUsd['lastPrice'] ?? 0);
        $priceBrl = (float) ($tickerBrl['lastPrice'] ?? 0);

        if ($priceBrl <= 0 && $priceUsd > 0 && $ptax && $ptax > 0) {
            $priceBrl = round($priceUsd * $ptax, 10);
        }
        if ($priceUsd <= 0 && $priceBrl > 0 && $ptax && $ptax > 0) {
            $priceUsd = round($priceBrl / $ptax, 10);
        }
        if ($priceUsd <= 0 || $priceBrl <= 0) {
            return null;
        }

        return [
            'usd' => $priceUsd,
            'brl' => $priceBrl,
            'change_24h' => isset($tickerUsd['priceChangePercent'])
                ? (float) $tickerUsd['priceChangePercent']
                : (isset($tickerBrl['priceChangePercent']) ? (float) $tickerBrl['priceChangePercent'] : null),
        ];
    }
}
