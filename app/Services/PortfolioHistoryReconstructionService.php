<?php

namespace App\Services;

use App\Models\Network;
use App\Models\Portfolio;
use App\Models\PortfolioSnapshot;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserApiKey;
use App\Models\Wallet;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Reconstrói a evolução de cada carteira sem alterar transações ou cálculos FIFO.
 * A posição atual é revertida cronologicamente a partir das transações já gravadas.
 */
class PortfolioHistoryReconstructionService
{
    private const TIMEZONE = 'America/Sao_Paulo';

    private const ENTRY_TYPES = [
        'buy', 'fiat_buy', 'deposit', 'receive', 'earn', 'reward', 'airdrop', 'mining', 'staking',
    ];

    private const EXIT_TYPES = [
        'sell', 'fiat_sell', 'withdrawal', 'withdraw', 'send', 'fee',
    ];

    private const CONVERSION_TYPES = ['trade', 'convert', 'swap'];

    public function __construct(private readonly CryptoPriceService $priceService)
    {
    }

    /**
     * @return array{wallets_processed:int, snapshots_written:int, partial_snapshots:int, unassigned_transactions:int, unsupported_transactions:int}
     */
    public function reconstruct(User $user, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $to = ($to ?? now(self::TIMEZONE))->copy()->timezone(self::TIMEZONE)->endOfDay();
        $from = ($from ?? $to->copy()->subYear()->addDay())->copy()->timezone(self::TIMEZONE)->startOfDay();
        $portfolio = Portfolio::query()->firstOrCreate(
            ['user_id' => $user->id, 'name' => 'Portfolio Principal'],
            ['is_active' => true],
        );

        $wallets = $user->wallets()->with('balances')->get();
        $walletByApiKey = $wallets
            ->filter(fn (Wallet $wallet) => str_starts_with((string) $wallet->address, 'exchange:binance:api-key:'))
            ->mapWithKeys(function (Wallet $wallet) {
                $apiKeyId = (int) str_replace('exchange:binance:api-key:', '', (string) $wallet->address);

                return $apiKeyId > 0 ? [$apiKeyId => $wallet] : [];
            });

        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$from, $to])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        $walletTransactions = $wallets->mapWithKeys(fn (Wallet $wallet) => [$wallet->id => collect()]);
        $unassigned = collect();
        foreach ($transactions as $transaction) {
            $wallet = $this->walletForTransaction($transaction, $wallets, $walletByApiKey);
            if ($wallet === null) {
                $unassigned->push($transaction);
                continue;
            }

            $walletTransactions->put(
                $wallet->id,
                $walletTransactions->get($wallet->id)->push($transaction),
            );
        }

        $unidentifiedWalletId = null;
        if ($unassigned->isNotEmpty()) {
            $unidentifiedWallet = $this->unidentifiedWallet($user)->load('balances');
            $wallets->push($unidentifiedWallet);
            $walletTransactions->put($unidentifiedWallet->id, $unassigned);
            $unidentifiedWalletId = $unidentifiedWallet->id;
        }
        $unassignedTransactions = $unassigned->count();

        $result = [
            'wallets_processed' => 0,
            'snapshots_written' => 0,
            'partial_snapshots' => 0,
            'unassigned_transactions' => $unassignedTransactions,
            'unsupported_transactions' => 0,
        ];
        $priceCache = [];

        foreach ($wallets as $wallet) {
            $walletResult = $this->reconstructWallet(
                $portfolio,
                $wallet,
                $walletTransactions->get($wallet->id, collect()),
                $from,
                $to,
                $priceCache,
                $wallet->id === $unidentifiedWalletId ? $unassignedTransactions : 0,
            );

            $result['wallets_processed']++;
            $result['snapshots_written'] += $walletResult['snapshots_written'];
            $result['partial_snapshots'] += $walletResult['partial_snapshots'];
            $result['unsupported_transactions'] += $walletResult['unsupported_transactions'];
        }

        $result['snapshots_written'] += $this->consolidateSnapshots($portfolio, $from, $to);

        Log::info('[Portfólio] Reconstrução histórica concluída.', [
            'user_id' => $user->id,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            ...$result,
        ]);

        return $result;
    }

    /**
     * @param Collection<int, Wallet> $wallets
     * @param Collection<int, Wallet> $walletByApiKey
     */
    private function walletForTransaction(Transaction $transaction, Collection $wallets, Collection $walletByApiKey): ?Wallet
    {
        if ($transaction->source_type === Wallet::class) {
            return $wallets->firstWhere('id', (int) $transaction->source_id);
        }

        if ($transaction->source_type === UserApiKey::class) {
            return $walletByApiKey->get((int) $transaction->source_id);
        }

        return null;
    }

    /**
     * @param Collection<int, Transaction> $transactions
     * @param array<string, object> $priceCache
     * @return array{snapshots_written:int, partial_snapshots:int, unsupported_transactions:int}
     */
    private function reconstructWallet(
        Portfolio $portfolio,
        Wallet $wallet,
        Collection $transactions,
        Carbon $from,
        Carbon $to,
        array &$priceCache,
        int $unassignedTransactions,
    ): array {
        $balances = $wallet->balances
            ->mapWithKeys(fn ($balance) => [
                strtoupper((string) $balance->asset) => (float) $balance->available + (float) $balance->locked,
            ])
            ->filter(fn (float $quantity) => abs($quantity) > 1e-12)
            ->all();
        $transactionsByDay = $transactions->groupBy(
            fn (Transaction $transaction) => $transaction->date->copy()->timezone(self::TIMEZONE)->toDateString(),
        );
        $unsupportedTransactions = 0;
        $snapshotsWritten = 0;
        $partialSnapshots = 0;

        $days = collect(CarbonPeriod::create($from->copy(), $to->copy())->toArray())->reverse()->values();
        foreach ($days as $day) {
            // A precisão em segundos evita que microsegundos de endOfDay impeçam
            // updateOrCreate de reencontrar o mesmo snapshot diário no PostgreSQL.
            $date = Carbon::instance($day)->timezone(self::TIMEZONE)->endOfDay()->microsecond(0);
            $dateKey = $date->toDateString();

            // Antes de desfazer as operações da competência, o estado representa o
            // fechamento daquele dia (ou a melhor fotografia disponível para ele).
            $snapshot = $this->snapshotData($balances, $date, $priceCache);
            $status = $snapshot['unpriced_assets'] === [] && $snapshot['negative_assets'] === [] && $unassignedTransactions === 0
                ? 'complete'
                : 'partial';

            $this->persistReconstructedSnapshot($portfolio, $wallet, $date, $snapshot, $status, $unassignedTransactions);
            $snapshotsWritten++;
            if ($status !== 'complete') {
                $partialSnapshots++;
            }

            foreach ($transactionsByDay->get($dateKey, collect()) as $transaction) {
                if (!$this->reverseTransaction($balances, $transaction)) {
                    $unsupportedTransactions++;
                }
            }
        }

        return [
            'snapshots_written' => $snapshotsWritten,
            'partial_snapshots' => $partialSnapshots,
            'unsupported_transactions' => $unsupportedTransactions,
        ];
    }

    /**
     * Reverte uma operação para restaurar os saldos imediatamente anteriores a ela.
     *
     * @param array<string, float> $balances
     */
    private function reverseTransaction(array &$balances, Transaction $transaction): bool
    {
        $type = strtolower(trim((string) $transaction->type));
        $fromAsset = $this->symbol($transaction->from_asset);
        $toAsset = $this->symbol($transaction->to_asset);
        $fromAmount = (float) ($transaction->from_amount ?? 0);
        $toAmount = (float) ($transaction->to_amount ?? 0);

        if (in_array($type, self::ENTRY_TYPES, true)) {
            $receivedAmount = $toAmount > 0 ? $toAmount : $fromAmount;
            $this->adjust($balances, $toAsset ?: $fromAsset, -$receivedAmount);
            // Apenas compras possuem, em regra, os dois lados econômicos registrados.
            if (in_array($type, ['buy', 'fiat_buy'], true) && $fromAsset !== null && $fromAmount > 0) {
                $this->adjust($balances, $fromAsset, $fromAmount);
            }
        } elseif (in_array($type, self::EXIT_TYPES, true)) {
            $sentAmount = $fromAmount > 0 ? $fromAmount : $toAmount;
            $this->adjust($balances, $fromAsset ?: $toAsset, $sentAmount);
            // Apenas vendas possuem, em regra, o ativo recebido registrado no outro lado.
            if (in_array($type, ['sell', 'fiat_sell'], true) && $toAsset !== null && $toAmount > 0) {
                $this->adjust($balances, $toAsset, -$toAmount);
            }
        } elseif (in_array($type, self::CONVERSION_TYPES, true)) {
            $this->adjust($balances, $fromAsset, $fromAmount);
            $this->adjust($balances, $toAsset, -$toAmount);
        } else {
            return false;
        }

        $commission = (float) ($transaction->commission ?? 0);
        $commissionAsset = $this->symbol($transaction->commission_asset ?? null);
        if ($commission > 0 && $commissionAsset !== null) {
            $this->adjust($balances, $commissionAsset, $commission);
        }

        return true;
    }

    /** @param array<string, float> $balances */
    private function adjust(array &$balances, ?string $asset, float $delta): void
    {
        if ($asset === null || abs($delta) <= 1e-12) {
            return;
        }

        $balances[$asset] = ($balances[$asset] ?? 0.0) + $delta;
        if (abs($balances[$asset]) <= 1e-10) {
            unset($balances[$asset]);
        }
    }

    /**
     * @param array<string, float> $balances
     * @param array<string, object> $priceCache
     * @return array{total_value_brl:float,total_value_usd:float,coverage_percentage:float,assets:array<int, array<string, mixed>>,unpriced_assets:array<int, string>,negative_assets:array<int, string>}
     */
    private function snapshotData(array $balances, Carbon $date, array &$priceCache): array
    {
        $totalBrl = 0.0;
        $totalUsd = 0.0;
        $positiveAssets = [];
        $unpricedAssets = [];
        $negativeAssets = [];
        $assets = [];

        foreach ($balances as $symbol => $quantity) {
            if ($quantity < -1e-10) {
                $negativeAssets[] = $symbol;
                continue;
            }
            if ($quantity <= 1e-10) {
                continue;
            }

            $positiveAssets[] = $symbol;
            $cacheKey = "{$symbol}:{$date->toDateString()}";
            $price = $priceCache[$cacheKey] ??= $this->priceService->getOrCreatePrice($symbol, $date);
            $priceBrl = (float) ($price->price_brl ?? 0);
            $priceUsd = (float) ($price->price_usd ?? 0);

            if ($priceBrl <= 0 || $priceUsd <= 0) {
                $unpricedAssets[] = $symbol;
                $assets[] = [
                    'symbol' => $symbol,
                    'quantity' => $quantity,
                    'price_available' => false,
                ];
                continue;
            }

            $valueBrl = round($quantity * $priceBrl, 10);
            $valueUsd = round($quantity * $priceUsd, 10);
            $totalBrl += $valueBrl;
            $totalUsd += $valueUsd;
            $assets[] = [
                'symbol' => $symbol,
                'quantity' => $quantity,
                'price_available' => true,
                'price_brl' => $priceBrl,
                'value_brl' => $valueBrl,
            ];
        }

        $coverage = $positiveAssets === []
            ? 100.0
            : round(((count($positiveAssets) - count($unpricedAssets)) / count($positiveAssets)) * 100, 2);

        return [
            'total_value_brl' => round($totalBrl, 2),
            'total_value_usd' => round($totalUsd, 2),
            'coverage_percentage' => $coverage,
            'assets' => $assets,
            'unpriced_assets' => array_values(array_unique($unpricedAssets)),
            'negative_assets' => array_values(array_unique($negativeAssets)),
        ];
    }

    /**
     * @param array{total_value_brl:float,total_value_usd:float,coverage_percentage:float,assets:array<int, array<string, mixed>>,unpriced_assets:array<int, string>,negative_assets:array<int, string>} $snapshot
     */
    private function persistReconstructedSnapshot(
        Portfolio $portfolio,
        Wallet $wallet,
        Carbon $date,
        array $snapshot,
        string $status,
        int $unassignedTransactions,
    ): void {
        PortfolioSnapshot::query()->updateOrCreate(
            [
                'portfolio_id' => $portfolio->id,
                'wallet_id' => $wallet->id,
                'snapshot_date' => $date,
                'source' => 'reconstructed',
            ],
            [
                'total_value_brl' => $snapshot['total_value_brl'],
                'total_value_usd' => $snapshot['total_value_usd'],
                'total_pnl' => null,
                'reconstruction_status' => $status,
                'coverage_percentage' => $snapshot['coverage_percentage'],
                'data' => [
                    'assets' => $snapshot['assets'],
                    'unpriced_assets' => $snapshot['unpriced_assets'],
                    'negative_assets' => $snapshot['negative_assets'],
                    'unassigned_transactions' => $unassignedTransactions,
                    'coverage_basis' => 'assets_with_positive_balance',
                ],
            ],
        );
    }

    /**
     * Consolida snapshots por carteira, escolhendo a melhor fonte disponível
     * em cada uma: local confirmado, oficial da exchange e, por último, reconstruída.
     */
    private function consolidateSnapshots(Portfolio $portfolio, Carbon $from, Carbon $to): int
    {
        $walletSnapshotsByDate = PortfolioSnapshot::query()
            ->where('portfolio_id', $portfolio->id)
            ->whereNotNull('wallet_id')
            ->whereBetween('snapshot_date', [$from, $to])
            ->orderBy('snapshot_date')
            ->get()
            ->groupBy(fn (PortfolioSnapshot $snapshot) => $snapshot->snapshot_date->timezone(self::TIMEZONE)->toDateString());
        $localConsolidatedDates = PortfolioSnapshot::query()
            ->where('portfolio_id', $portfolio->id)
            ->whereNull('wallet_id')
            ->where('source', 'local')
            ->whereBetween('snapshot_date', [$from, $to])
            ->get()
            ->reject(fn (PortfolioSnapshot $snapshot) => $this->isLegacyEmptySnapshot($snapshot))
            ->mapWithKeys(fn (PortfolioSnapshot $snapshot) => [
                $snapshot->snapshot_date->timezone(self::TIMEZONE)->toDateString() => true,
            ]);
        $written = 0;

        foreach ($walletSnapshotsByDate as $dateKey => $snapshots) {
            // Um snapshot consolidado local confirmado prevalece sobre qualquer
            // total deduzido de snapshots individuais.
            if ($localConsolidatedDates->has($dateKey)) {
                continue;
            }

            $selected = $snapshots
                ->groupBy('wallet_id')
                ->map(fn (Collection $items) => $items->sortByDesc(
                    fn (PortfolioSnapshot $snapshot) => $this->sourcePriority($snapshot->source)
                )->first())
                ->values();
            if ($selected->isEmpty()) {
                continue;
            }

            $assetsCount = 0;
            $pricedAssets = 0.0;
            $sources = $selected->pluck('source')->unique()->values()->all();
            foreach ($selected as $snapshot) {
                $assetCount = max(1, count((array) data_get($snapshot->data, 'assets', [])));
                $assetsCount += $assetCount;
                $pricedAssets += $assetCount * ((float) $snapshot->coverage_percentage / 100);
            }
            $coverage = $assetsCount > 0 ? round(($pricedAssets / $assetsCount) * 100, 2) : 100.0;
            $status = $coverage >= 100 ? 'complete' : 'partial';
            $date = Carbon::parse($dateKey, self::TIMEZONE)->endOfDay();

            PortfolioSnapshot::query()->updateOrCreate(
                [
                    'portfolio_id' => $portfolio->id,
                    'wallet_id' => null,
                    'snapshot_date' => $date,
                    'source' => 'reconstructed',
                ],
                [
                    'total_value_brl' => round($selected->sum(fn (PortfolioSnapshot $snapshot) => (float) $snapshot->total_value_brl), 2),
                    'total_value_usd' => round($selected->sum(fn (PortfolioSnapshot $snapshot) => (float) ($snapshot->total_value_usd ?? 0)), 2),
                    'total_pnl' => null,
                    'reconstruction_status' => $status,
                    'coverage_percentage' => $coverage,
                    'data' => [
                        'sources' => $sources,
                        'wallets' => $selected->map(fn (PortfolioSnapshot $snapshot) => [
                            'wallet_id' => $snapshot->wallet_id,
                            'source' => $snapshot->source,
                            'value_brl' => (float) $snapshot->total_value_brl,
                            'coverage_percentage' => (float) $snapshot->coverage_percentage,
                        ])->all(),
                    ],
                ],
            );
            $written++;
        }

        return $written;
    }

    private function isLegacyEmptySnapshot(PortfolioSnapshot $snapshot): bool
    {
        return (float) $snapshot->total_value_brl <= 0
            && empty(data_get($snapshot->data, 'assets', []))
            && data_get($snapshot->data, 'coverage_basis') === null;
    }

    private function sourcePriority(?string $source): int
    {
        return match ($source) {
            'local' => 3,
            'official' => 2,
            'reconstructed' => 1,
            default => 0,
        };
    }

    private function unidentifiedWallet(User $user): Wallet
    {
        $network = Network::query()->firstOrCreate(
            ['slug' => 'unknown-source'],
            ['name' => 'Origem não identificada', 'explorer_url' => null],
        );

        return Wallet::query()->firstOrCreate(
            ['address' => "unidentified:source:user:{$user->id}"],
            [
                'user_id' => $user->id,
                'name' => 'Origem não identificada',
                'network_id' => $network->id,
                'description' => 'Origem criada para transações que não possuem carteira ou chave de API associada.',
            ],
        );
    }

    private function symbol(?string $symbol): ?string
    {
        $symbol = strtoupper(trim((string) $symbol));

        return $symbol === '' ? null : $symbol;
    }
}
