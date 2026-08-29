<?php

namespace App\Services;

use App\Models\CryptoAsset;
use App\Models\Network;
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
 * carteiras consumida pelo Portfólio. Não reconstrói histórico nem altera
 * transações fiscais.
 */
class BinancePortfolioSyncService
{
    private const STABLECOINS = ['USDT', 'USDC', 'BUSD', 'DAI', 'TUSD', 'FDUSD'];
    private const USD_QUOTES = ['USDT', 'BUSD', 'FDUSD', 'USDC'];

    public function __construct(private readonly CryptoPriceService $priceService)
    {
    }

    /**
     * @return array{keys_processed:int, wallets_updated:int, balances_updated:int, assets_with_balance:int, prices_updated:int, prices_unavailable:int}
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

        Log::info('[Portfólio] Saldos Binance sincronizados.', [
            'user_id' => $user->id,
            ...$result,
        ]);

        return $result;
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
