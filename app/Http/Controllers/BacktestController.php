<?php

namespace App\Http\Controllers;

use App\Models\BacktestRun;
use App\Models\Exchange;
use App\Models\TradingStrategyVersion;
use App\Services\BacktestRunService;
use App\Services\MarketCandleIngestionService;
use App\Services\TradingAuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BacktestController extends Controller
{
    private const MAX_PERIOD_DAYS = 180;

    public function __construct(
        private readonly MarketCandleIngestionService $marketData,
        private readonly BacktestRunService $backtests,
        private readonly TradingAuditLogger $audit,
    ) {
    }

    public function index(Request $request): Response
    {
        $runs = $request->user()->backtestRuns()
            ->with([
                'strategy:id,name',
                'strategyVersion:id,trading_strategy_id,version,definition_hash',
                'exchange:id,name,description',
            ])
            ->latest()
            ->get();

        return Inertia::render('TradingBot/Backtests/Index', [
            'backtests' => $runs,
            'executionEnabled' => false,
        ]);
    }

    public function create(Request $request): Response
    {
        $strategies = $request->user()->tradingStrategies()
            ->notArchived()
            ->with(['versions' => fn ($query) => $query->select([
                'id', 'trading_strategy_id', 'version', 'definition_hash', 'status', 'created_at',
            ])->orderByDesc('version')])
            ->orderBy('name')
            ->get(['id', 'name', 'current_version_id']);
        $binance = Exchange::query()
            ->where('name', 'binance')
            ->first(['id', 'name', 'description']);

        return Inertia::render('TradingBot/Backtests/Create', [
            'strategies' => $strategies,
            'markets' => $binance ? [[
                'exchange_id' => $binance->id,
                'exchange_name' => $binance->name,
                'exchange_label' => $binance->description ?: 'Binance',
                'symbols' => ['BTCUSDT', 'ETHUSDT'],
                'timeframes' => ['1h', '4h'],
            ]] : [],
            'defaults' => [
                'initial_capital' => '10000',
                'allocation_pct' => '100',
                'fee_rate' => '0.1',
                'slippage_rate' => '0.05',
                'close_open_position_at_end' => false,
                'maximum_period_days' => self::MAX_PERIOD_DAYS,
                'timezone' => 'UTC',
            ],
            'executionEnabled' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'strategy_version_id' => ['required', 'integer', 'exists:trading_strategy_versions,id'],
            'exchange_id' => ['required', 'integer', 'exists:exchanges,id'],
            'symbol' => ['required', 'string', 'in:BTCUSDT,ETHUSDT'],
            'timeframe' => ['required', 'string', 'in:1h,4h'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'initial_capital' => ['required', 'numeric', 'gt:0'],
            'allocation_pct' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'fee_rate' => ['required', 'numeric', 'gte:0', 'lte:100'],
            'slippage_rate' => ['required', 'numeric', 'gte:0', 'lte:100'],
            'close_open_position_at_end' => ['required', 'boolean'],
        ]);

        $startAt = CarbonImmutable::parse($payload['start_at'], 'UTC')->utc();
        $endAt = CarbonImmutable::parse($payload['end_at'], 'UTC')->utc();
        if ($startAt->diffInSeconds($endAt) > self::MAX_PERIOD_DAYS * 86400) {
            return back()->withErrors([
                'end_at' => 'O intervalo não pode ser maior que 180 dias.',
            ])->withInput();
        }

        $version = TradingStrategyVersion::query()->with('strategy')->findOrFail($payload['strategy_version_id']);
        $this->authorize('view', $version->strategy);
        $exchange = Exchange::query()->findOrFail($payload['exchange_id']);
        $dataset = $this->marketData->cacheFirst($exchange, $payload['symbol'], $payload['timeframe'], $startAt, $endAt);
        $run = $this->backtests->create(
            $request->user(),
            $version,
            $dataset['candles'],
            [
                'exchange_id' => $exchange->id,
                'symbol' => $payload['symbol'],
                'timeframe' => $payload['timeframe'],
                'initial_capital' => $payload['initial_capital'],
                'allocation_pct' => $payload['allocation_pct'],
                'fee_rate' => $payload['fee_rate'],
                'slippage_rate' => $payload['slippage_rate'],
                'close_open_position_at_end' => $payload['close_open_position_at_end'],
                'evaluation_time' => now('UTC')->toIso8601String(),
            ],
            $startAt,
            $endAt,
            [
                'cache_hit' => $dataset['cache_hit'],
                'fetched_count' => $dataset['fetched_count'],
                'detected_gaps' => $dataset['gaps'],
                'execution_boundary' => 'historical_only',
            ],
        );

        return redirect()->route('trading-bot.backtests.show', $run)
            ->with('success', 'Backtest histórico concluído. Nenhuma ordem foi enviada e nenhuma credencial foi utilizada.');
    }

    public function show(BacktestRun $backtest): Response
    {
        $this->authorize('view', $backtest);
        $backtest->load([
            'strategy:id,name',
            'strategyVersion:id,trading_strategy_id,version,definition_hash',
            'exchange:id,name,description',
            'trades',
        ]);

        return Inertia::render('TradingBot/Backtests/Show', [
            'backtest' => $backtest,
            'executionEnabled' => false,
        ]);
    }

    public function destroy(Request $request, BacktestRun $backtest): RedirectResponse
    {
        $this->authorize('delete', $backtest);

        DB::transaction(function () use ($request, $backtest): void {
            $this->audit->record(
                $request->user()->id,
                'backtest_deleted',
                'Resultado histórico de backtest excluído pelo usuário.',
                'info',
                $backtest->trading_strategy_id,
                [
                    'backtest_run_id' => $backtest->id,
                    'strategy_version_id' => $backtest->trading_strategy_version_id,
                    'strategy_definition_hash' => $backtest->strategy_definition_hash,
                    'dataset_hash' => $backtest->dataset_hash,
                    'symbol' => $backtest->symbol,
                    'timeframe' => $backtest->timeframe,
                    'status' => $backtest->status,
                ],
                'backtest',
            );

            $backtest->delete();
        });

        return redirect()->route('trading-bot.backtests.index')
            ->with('success', 'Backtest excluído. O evento de exclusão foi registrado na auditoria.');
    }
}
