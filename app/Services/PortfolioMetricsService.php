<?php

namespace App\Services;

use App\Models\CryptoAsset;
use App\Models\FifoOpeningBalance;
use App\Models\Portfolio;
use App\Models\PortfolioSnapshot;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Constrói as métricas exibidas no Portfólio a partir das fontes reais do sistema.
 *
 * Fonte de saldo: users -> wallets -> wallet_balances (available + locked).
 * Fonte de preço: crypto_assets.current_price_brl.
 * Fonte de custo: histórico normalizado de transações e lotes de abertura FIFO.
 */
class PortfolioMetricsService
{
    private const ENTRY_TYPES = ['buy', 'fiat_buy', 'deposit', 'receive', 'earn', 'reward', 'airdrop', 'mining', 'staking'];
    private const EXIT_TYPES = ['sell', 'fiat_sell', 'withdrawal', 'withdraw', 'send', 'fee'];
    private const CONVERSION_TYPES = ['trade', 'convert', 'swap'];

    public function overview(User $user, string $period = '30d', ?int $walletId = null): array
    {
        $assets = $this->assets($user, $walletId);
        $totalValue = round($assets->sum('value_brl'), 2);
        $pricedAssets = $assets->where('price_available', true);
        $costedAssets = $assets->where('cost_basis_available', true);
        $totalInvested = round($costedAssets->sum('cost_basis_brl'), 2);
        $totalPnl = $costedAssets->isEmpty()
            ? null
            : round($costedAssets->sum('unrealized_pnl_brl'), 2);
        $totalPnlPercentage = $totalInvested > 0 && $totalPnl !== null
            ? round(($totalPnl / $totalInvested) * 100, 4)
            : null;
        $totalChange24h = round($assets->sum('change_value_24h_brl'), 2);
        $totalChange24hPercentage = $totalValue > 0 && ($totalValue - $totalChange24h) != 0
            ? round(($totalChange24h / ($totalValue - $totalChange24h)) * 100, 4)
            : null;

        $portfolio = $this->portfolioRecord($user);
        // A fotografia local consolidada só pode refletir todas as carteiras.
        // Ao filtrar uma carteira, a tela deve apenas consultar seus dados.
        if ($walletId === null) {
            $this->recordDailySnapshot($portfolio, $totalValue, $totalInvested, $totalPnl, $assets);
        }
        $history = $this->history($user, $period, $walletId);
        $riskMetrics = $this->riskMetrics($history['data']);

        $allocations = $assets
            ->filter(fn (array $asset) => $asset['value_brl'] > 0)
            ->map(function (array $asset) use ($totalValue) {
                return [
                    ...$asset,
                    'value' => $asset['value_brl'],
                    'percentage' => $totalValue > 0
                        ? round(($asset['value_brl'] / $totalValue) * 100, 4)
                        : 0.0,
                ];
            })
            ->sortByDesc('value_brl')
            ->values();

        $performers = $assets
            ->filter(fn (array $asset) => $asset['price_change_24h'] !== null)
            ->sortByDesc('price_change_24h')
            ->take(5)
            ->values()
            ->all();

        $losers = $assets
            ->filter(fn (array $asset) => $asset['price_change_24h'] !== null)
            ->sortBy('price_change_24h')
            ->take(5)
            ->values()
            ->all();

        $walletCount = Wallet::query()
            ->where('user_id', $user->id)
            ->when($walletId !== null, fn ($query) => $query->whereKey($walletId))
            ->count();
        $priceCoverage = $assets->isEmpty()
            ? 100.0
            : round(($pricedAssets->count() / $assets->count()) * 100, 2);
        $costCoverage = $totalValue > 0
            ? round(($costedAssets->sum('value_brl') / $totalValue) * 100, 2)
            : 100.0;

        return [
            // Contrato canônico.
            'total_value' => $totalValue,
            'total_invested' => $totalInvested,
            'total_profit_loss' => $totalPnl,
            'total_profit_loss_percentage' => $totalPnlPercentage,
            'assets_count' => $assets->count(),
            'wallets_count' => $walletCount,
            'assets' => $assets->values()->all(),

            // Campos usados pela página existente, preservados como aliases.
            'total_pnl' => $totalPnl,
            'total_assets' => $assets->count(),
            'total_wallets' => $walletCount,
            'total_roi' => $totalPnlPercentage,
            'total_change_24h' => $totalChange24h,
            'total_change_24h_percentage' => $totalChange24hPercentage,
            'allocations' => $allocations->all(),
            'top_performers' => $performers,
            'top_losers' => $losers,
            'history' => $history,
            'diversification_score' => $this->diversificationScore($allocations),
            'volatility_30d' => $riskMetrics['volatility_annualized_pct'],
            'sharpe_ratio' => $riskMetrics['sharpe_ratio'],
            'max_drawdown' => $riskMetrics['max_drawdown_pct'],
            'metrics_data_available' => $riskMetrics['available'],
            'price_coverage_percentage' => $priceCoverage,
            'cost_basis_coverage_percentage' => $costCoverage,
            'period' => $period,
            'wallet_id' => $walletId,
        ];
    }

    /**
     * Dados reais de atividade. Não assume que Transaction possui campos legados
     * como crypto_asset, amount, value_brl ou executed_at.
     */
    public function recentActivity(User $user, int $limit = 10): array
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->with(['fromCryptoAsset:symbol,name', 'toCryptoAsset:symbol,name', 'source'])
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get()
            ->map(function (Transaction $transaction) {
                $isEntry = in_array(strtolower((string) $transaction->type), self::ENTRY_TYPES, true);
                $asset = $isEntry
                    ? ($transaction->toCryptoAsset ?? $transaction->fromCryptoAsset)
                    : ($transaction->fromCryptoAsset ?? $transaction->toCryptoAsset);
                $quantity = $isEntry
                    ? ((float) ($transaction->to_amount ?? $transaction->from_amount ?? 0))
                    : ((float) ($transaction->from_amount ?? $transaction->to_amount ?? 0));

                return [
                    'id' => $transaction->id,
                    'type' => strtolower((string) $transaction->type),
                    'asset_symbol' => $asset?->symbol ?? ($isEntry ? $transaction->to_asset : $transaction->from_asset),
                    'asset_name' => $asset?->name,
                    'quantity' => $quantity,
                    'total_brl' => (float) ($transaction->total_brl ?? 0),
                    'occurred_at' => optional($transaction->date)->toIso8601String(),
                    'source_name' => data_get($transaction->source, 'name')
                        ?? data_get($transaction->source, 'exchange.name')
                        ?? 'Origem não informada',
                ];
            })
            ->all();
    }

    public function history(User $user, string $period = '30d', ?int $walletId = null): array
    {
        $startDate = $this->periodStart($period);
        $portfolio = $this->portfolioRecord($user);

        $snapshots = PortfolioSnapshot::query()
            ->where('portfolio_id', $portfolio->id)
            ->where('snapshot_date', '>=', $startDate->copy()->startOfDay())
            ->when(
                $walletId !== null,
                fn ($query) => $query->where('wallet_id', $walletId),
                fn ($query) => $query->whereNull('wallet_id'),
            )
            ->orderBy('snapshot_date')
            ->get()
            ->reject(fn (PortfolioSnapshot $snapshot) => $this->isLegacyEmptySnapshot($snapshot))
            ->reject(fn (PortfolioSnapshot $snapshot) => $this->isFullyUnpricedSnapshot($snapshot))
            ->groupBy(fn (PortfolioSnapshot $snapshot) => $snapshot->snapshot_date->timezone('America/Sao_Paulo')->toDateString())
            ->map(fn (Collection $items) => $items->sortByDesc(
                fn (PortfolioSnapshot $snapshot) => $this->snapshotDisplayPriority($snapshot)
            )->first())
            ->values()
            // Snapshots vazios criados antes da primeira sincronização não
            // representam uma queda ou crescimento real do portfólio. Remover
            // apenas os zeros iniciais preserva uma liquidação posterior real.
            ->skipUntil(function (PortfolioSnapshot $snapshot) {
                $assets = data_get($snapshot->data, 'assets', []);

                return (float) $snapshot->total_value_brl > 0 || !empty($assets);
            });
        $data = $snapshots
            ->map(fn (PortfolioSnapshot $snapshot) => [
                'date' => $snapshot->snapshot_date->timezone('America/Sao_Paulo')->toDateString(),
                'value_brl' => (float) $snapshot->total_value_brl,
                'total_pnl_brl' => $snapshot->total_pnl === null ? null : (float) $snapshot->total_pnl,
                'source' => $this->resolvedSnapshotSource($snapshot),
                'reconstruction_status' => $snapshot->reconstruction_status ?? 'complete',
                'coverage_percentage' => $snapshot->coverage_percentage === null ? 100.0 : (float) $snapshot->coverage_percentage,
            ])
            ->values()
            ->all();

        return [
            'period' => $period,
            'wallet_id' => $walletId,
            'start_date' => $startDate->toDateString(),
            'end_date' => now('America/Sao_Paulo')->toDateString(),
            'data' => $data,
        ];
    }

    public function allocation(User $user, ?int $walletId = null): array
    {
        return $this->overview($user, '30d', $walletId)['allocations'];
    }

    public function performance(User $user, string $period = '30d', ?int $walletId = null): array
    {
        $history = $this->history($user, $period, $walletId);
        $points = $history['data'];
        $first = $points[0]['value_brl'] ?? null;
        $last = $points[array_key_last($points)]['value_brl'] ?? null;
        $absoluteChange = $first !== null && $last !== null ? round($last - $first, 2) : null;
        $percentageChange = $first !== null && $first > 0 && $last !== null
            ? round(($absoluteChange / $first) * 100, 4)
            : null;

        return [
            ...$history,
            'start_value' => $first,
            'end_value' => $last,
            'absolute_change' => $absoluteChange,
            'percentage_change' => $percentageChange,
            'metrics' => $this->riskMetrics($points),
        ];
    }

    private function assets(User $user, ?int $walletId = null): Collection
    {
        $balances = $user->walletBalances()
            ->with('wallet:id,name')
            ->when($walletId !== null, fn ($query) => $query->where('wallet_balances.wallet_id', $walletId))
            ->whereRaw('(available + locked) > 0')
            ->get()
            ->groupBy(fn ($balance) => strtoupper((string) $balance->asset));

        $assetModels = CryptoAsset::query()
            ->whereIn('symbol', $balances->keys()->all())
            ->get()
            ->keyBy(fn (CryptoAsset $asset) => strtoupper($asset->symbol));
        $inventory = $this->inventoryByAsset($user);

        return $balances->map(function (Collection $assetBalances, string $symbol) use ($assetModels, $inventory) {
            $asset = $assetModels->get($symbol);
            $available = (float) $assetBalances->sum(fn ($balance) => (float) $balance->available);
            $locked = (float) $assetBalances->sum(fn ($balance) => (float) $balance->locked);
            $quantity = $available + $locked;
            $price = $asset?->current_price_brl === null ? null : (float) $asset->current_price_brl;
            $value = $price === null ? 0.0 : round($quantity * $price, 2);
            $inventoryItem = $inventory[$symbol] ?? null;
            $costBasis = $this->costBasisForCurrentBalance($inventoryItem, $quantity);
            $priceChange = $asset?->price_change_24h === null ? null : (float) $asset->price_change_24h;
            $changeValue = $priceChange === null ? 0.0 : round($value * ($priceChange / (100 + $priceChange)), 2);

            return [
                'symbol' => $symbol,
                'name' => $asset?->name ?? $symbol,
                'available' => $available,
                'locked' => $locked,
                'quantity' => $quantity,
                'wallets_count' => $assetBalances->pluck('wallet_id')->unique()->count(),
                'current_price_brl' => $price,
                'current_price' => $price,
                'price_available' => $price !== null,
                'value_brl' => $value,
                'value' => $value,
                'cost_basis_brl' => $costBasis,
                'cost_basis_available' => $costBasis !== null,
                'unrealized_pnl_brl' => $costBasis === null ? null : round($value - $costBasis, 2),
                'profit_loss' => $costBasis === null ? null : round($value - $costBasis, 2),
                'unrealized_pnl_percentage' => $costBasis !== null && $costBasis > 0
                    ? round((($value - $costBasis) / $costBasis) * 100, 4)
                    : null,
                'profit_loss_percentage' => $costBasis !== null && $costBasis > 0
                    ? round((($value - $costBasis) / $costBasis) * 100, 4)
                    : null,
                'price_change_24h' => $priceChange,
                'change_24h' => $priceChange,
                'change_value_24h_brl' => $changeValue,
                'change_value' => $changeValue,
                'price_change_7d' => $asset?->price_change_7d === null ? null : (float) $asset->price_change_7d,
                'price_change_30d' => $asset?->price_change_30d === null ? null : (float) $asset->price_change_30d,
            ];
        })->values();
    }

    /**
     * Reconstitui lotes remanescentes de custo com a mesma semântica principal do FIFO.
     * O resultado é usado apenas para custo de posição no Portfólio, não para substituir
     * os campos fiscais apurados nas transações.
     */
    private function inventoryByAsset(User $user): array
    {
        $lots = [];
        $openingBalanceQuery = FifoOpeningBalance::query()->where('user_id', $user->id);
        $startYear = (clone $openingBalanceQuery)->min('fiscal_year');
        $openingBalances = $startYear === null
            ? collect()
            : $openingBalanceQuery->where('fiscal_year', $startYear)->orderBy('asset')->get();

        foreach ($openingBalances as $balance) {
            $this->addLot($lots, strtoupper($balance->asset), (float) $balance->quantity, (float) $balance->total_cost_brl);
        }

        $transactionsQuery = Transaction::query()->where('user_id', $user->id);
        if ($startYear !== null) {
            // O saldo de 31/12 já incorpora a história anterior a este ano.
            $transactionsQuery->whereYear('date', '>=', $startYear);
        }

        $transactionsQuery
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->each(function (Transaction $transaction) use (&$lots) {
                $type = strtolower(trim((string) $transaction->type));
                $totalBrl = (float) ($transaction->total_brl ?? 0);

                if (in_array($type, self::ENTRY_TYPES, true)) {
                    // Um depósito ou reward sem custo em BRL não pode ser tratado como
                    // custo zero. Ele é mantido fora da base até existir cotação/custo.
                    if ($totalBrl > 0) {
                        $this->addLot($lots, $transaction->to_asset ?? $transaction->from_asset, (float) ($transaction->to_amount ?? $transaction->from_amount ?? 0), $totalBrl);
                    }
                    return;
                }

                if (in_array($type, self::EXIT_TYPES, true)) {
                    $this->consumeLots($lots, $transaction->from_asset ?? $transaction->to_asset, (float) ($transaction->from_amount ?? $transaction->to_amount ?? 0));
                    return;
                }

                if (in_array($type, self::CONVERSION_TYPES, true)) {
                    $this->consumeLots($lots, $transaction->from_asset, (float) ($transaction->from_amount ?? 0));
                    $this->addLot($lots, $transaction->to_asset, (float) ($transaction->to_amount ?? 0), $totalBrl);
                }
            });

        return collect($lots)->map(function (array $assetLots) {
            return [
                'quantity' => array_sum(array_column($assetLots, 'quantity')),
                'cost_brl' => array_sum(array_column($assetLots, 'cost_brl')),
            ];
        })->all();
    }

    private function addLot(array &$lots, ?string $symbol, float $quantity, float $costBrl): void
    {
        $symbol = strtoupper(trim((string) $symbol));
        if ($symbol === '' || $quantity <= 0 || $costBrl < 0) {
            return;
        }

        $lots[$symbol] ??= [];
        $lots[$symbol][] = ['quantity' => $quantity, 'cost_brl' => $costBrl];
    }

    private function consumeLots(array &$lots, ?string $symbol, float $quantity): void
    {
        $symbol = strtoupper(trim((string) $symbol));
        if ($symbol === '' || $quantity <= 0 || empty($lots[$symbol])) {
            return;
        }

        $remaining = $quantity;
        foreach ($lots[$symbol] as $index => &$lot) {
            if ($remaining <= 0) {
                break;
            }

            $consumed = min($lot['quantity'], $remaining);
            $unitCost = $lot['quantity'] > 0 ? $lot['cost_brl'] / $lot['quantity'] : 0;
            $lot['quantity'] -= $consumed;
            $lot['cost_brl'] -= $consumed * $unitCost;
            $remaining -= $consumed;

            if ($lot['quantity'] <= 1e-10) {
                unset($lots[$symbol][$index]);
            }
        }
        unset($lot);
        $lots[$symbol] = array_values($lots[$symbol]);
    }

    private function costBasisForCurrentBalance(?array $inventory, float $currentQuantity): ?float
    {
        if (!$inventory || $inventory['quantity'] <= 0 || $currentQuantity <= 0) {
            return null;
        }

        // Divergência significa que parte do saldo atual não possui lote de custo
        // reconstituído. Nesse caso, o Portfólio exibe custo/P&L indisponível em vez
        // de extrapolar um custo médio e produzir um ganho potencialmente incorreto.
        $tolerance = max(1e-8, $currentQuantity * 1e-8);
        if (abs($inventory['quantity'] - $currentQuantity) > $tolerance) {
            return null;
        }

        return round($inventory['cost_brl'], 2);
    }

    private function portfolioRecord(User $user): Portfolio
    {
        return Portfolio::query()->firstOrCreate(
            ['user_id' => $user->id, 'name' => 'Portfolio Principal'],
            ['is_active' => true]
        );
    }

    private function recordDailySnapshot(Portfolio $portfolio, float $totalValue, float $totalInvested, ?float $totalPnl, Collection $assets): void
    {
        $portfolio->fill([
            'total_value_brl' => $totalValue,
            'total_invested' => $totalInvested,
            'total_pnl' => $totalPnl ?? 0,
            'pnl_percentage' => $totalInvested > 0 && $totalPnl !== null ? ($totalPnl / $totalInvested) * 100 : 0,
            'last_updated_at' => now(),
        ])->save();

        $hasAssets = $assets->isNotEmpty();
        $hasPreviousPosition = PortfolioSnapshot::query()
            ->where('portfolio_id', $portfolio->id)
            ->where('total_value_brl', '>', 0)
            ->exists();

        // Um portfólio que nunca teve posição não deve produzir um ponto zero
        // artificial. Depois do primeiro snapshot positivo, zero é mantido para
        // representar corretamente uma eventual liquidação total.
        if (!$hasAssets && $totalValue <= 0 && !$hasPreviousPosition) {
            return;
        }

        $pricedAssets = $assets->where('price_available', true);
        $coverage = $assets->isEmpty()
            ? 100.0
            : round(($pricedAssets->count() / $assets->count()) * 100, 2);
        $unpricedAssets = $assets
            ->reject(fn (array $asset) => $asset['price_available'])
            ->pluck('symbol')
            ->values()
            ->all();

        $snapshot = PortfolioSnapshot::query()
            ->where('portfolio_id', $portfolio->id)
            ->whereNull('wallet_id')
            ->where('source', 'local')
            ->whereDate('snapshot_date', now('America/Sao_Paulo')->toDateString())
            ->first() ?? new PortfolioSnapshot([
                'portfolio_id' => $portfolio->id,
                'wallet_id' => null,
                'source' => 'local',
            ]);

        $snapshot->fill([
            'total_value_brl' => $totalValue,
            'total_value_usd' => null,
            'total_pnl' => $totalPnl ?? 0,
            'snapshot_date' => now('America/Sao_Paulo')->endOfDay()->microsecond(0),
            'source' => 'local',
            'reconstruction_status' => $coverage >= 100 ? 'complete' : 'partial',
            'coverage_percentage' => $coverage,
            'data' => [
                'assets' => $assets->map(fn (array $asset) => [
                    'symbol' => $asset['symbol'],
                    'quantity' => $asset['quantity'],
                    'value_brl' => $asset['value_brl'],
                ])->values()->all(),
                'unpriced_assets' => $unpricedAssets,
                'coverage_basis' => 'assets_with_current_balance',
            ],
        ])->save();
    }

    private function periodStart(string $period): Carbon
    {
        return match ($period) {
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '90d' => now()->subDays(90),
            '1y' => now()->subYear(),
            'all' => Carbon::create(2009, 1, 1),
            default => now()->subDays(30),
        };
    }

    private function riskMetrics(array $points): array
    {
        if (count($points) < 3) {
            return [
                'available' => false,
                'volatility_annualized_pct' => null,
                'sharpe_ratio' => null,
                'max_drawdown_pct' => null,
            ];
        }

        $returns = [];
        $peak = 0.0;
        $maxDrawdown = 0.0;
        $previousValue = null;

        foreach ($points as $point) {
            $value = (float) $point['value_brl'];
            if ($previousValue !== null && $previousValue > 0) {
                $returns[] = ($value / $previousValue) - 1;
            }
            $peak = max($peak, $value);
            if ($peak > 0) {
                $maxDrawdown = min($maxDrawdown, (($value / $peak) - 1) * 100);
            }
            $previousValue = $value;
        }

        if (count($returns) < 2) {
            return [
                'available' => false,
                'volatility_annualized_pct' => null,
                'sharpe_ratio' => null,
                'max_drawdown_pct' => round($maxDrawdown, 4),
            ];
        }

        $mean = array_sum($returns) / count($returns);
        $variance = array_sum(array_map(fn (float $return) => ($return - $mean) ** 2, $returns)) / (count($returns) - 1);
        $standardDeviation = sqrt($variance);

        return [
            'available' => true,
            'volatility_annualized_pct' => round($standardDeviation * sqrt(365) * 100, 4),
            'sharpe_ratio' => $standardDeviation > 0 ? round(($mean / $standardDeviation) * sqrt(365), 4) : null,
            'max_drawdown_pct' => round($maxDrawdown, 4),
        ];
    }

    private function resolvedSnapshotSource(PortfolioSnapshot $snapshot): string
    {
        if (data_get($snapshot->data, 'source') === 'binance_account_snapshot') {
            return 'official';
        }

        return $snapshot->source ?? 'local';
    }

    private function snapshotSourcePriority(?string $source): int
    {
        return match ($source) {
            'local' => 3,
            'official' => 2,
            'reconstructed' => 1,
            default => 0,
        };
    }

    private function snapshotDisplayPriority(PortfolioSnapshot $snapshot): int
    {
        // Versões anteriores criavam um snapshot local vazio antes da primeira
        // sincronização. Quando há outro ponto real na mesma data, esse legado
        // não pode vencer pela prioridade da fonte e simular liquidação total.
        if ($this->isLegacyEmptySnapshot($snapshot)) {
            return -1;
        }

        return $this->snapshotSourcePriority($this->resolvedSnapshotSource($snapshot));
    }

    private function isLegacyEmptySnapshot(PortfolioSnapshot $snapshot): bool
    {
        return $this->resolvedSnapshotSource($snapshot) === 'local'
            && (float) $snapshot->total_value_brl <= 0
            && empty(data_get($snapshot->data, 'assets', []))
            && data_get($snapshot->data, 'coverage_basis') === null;
    }

    private function isFullyUnpricedSnapshot(PortfolioSnapshot $snapshot): bool
    {
        // Total zero com cobertura zero significa “nenhum preço encontrado”,
        // não liquidação. Uma liquidação verdadeira possui cobertura válida e
        // deve continuar visível no gráfico como R$ 0,00.
        return ($snapshot->reconstruction_status ?? 'complete') === 'partial'
            && (float) ($snapshot->coverage_percentage ?? 100) <= 0
            && (float) $snapshot->total_value_brl <= 0;
    }

    private function diversificationScore(Collection $allocations): ?float
    {
        $count = $allocations->count();
        if ($count === 0) {
            return null;
        }
        if ($count === 1) {
            return 0.0;
        }

        $entropy = $allocations->sum(function (array $allocation) {
            $weight = $allocation['percentage'] / 100;
            return $weight > 0 ? -$weight * log($weight) : 0;
        });

        return round(($entropy / log($count)) * 10, 2);
    }
}
