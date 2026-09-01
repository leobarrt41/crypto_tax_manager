<?php

namespace App\Http\Controllers;

use App\Models\Exchange;
use App\Models\PaperTradingSession;
use App\Models\TradingStrategyVersion;
use App\Services\ManualPaperTradingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class PaperTradingController extends Controller
{
    public function __construct(private readonly ManualPaperTradingService $paperTrading)
    {
    }

    public function index(Request $request): Response
    {
        $sessions = $request->user()->paperTradingSessions()
            ->with([
                'strategy:id,name',
                'strategyVersion:id,trading_strategy_id,version,definition_hash',
                'exchange:id,name,description',
                'cycles' => fn ($query) => $query->latest('sequence')->limit(1),
            ])
            ->latest()
            ->get();

        return Inertia::render('TradingBot/PaperTrading/Index', [
            'sessions' => $sessions,
            'executionEnabled' => false,
            'mode' => 'manual_paper_trading_only',
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
        $binance = Exchange::query()->where('name', 'binance')->first(['id', 'name', 'description']);

        return Inertia::render('TradingBot/PaperTrading/Create', [
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
                'timezone' => 'UTC',
            ],
            'executionEnabled' => false,
            'mode' => 'manual_paper_trading_only',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'strategy_version_id' => ['required', 'integer', 'exists:trading_strategy_versions,id'],
            'exchange_id' => ['required', 'integer', 'exists:exchanges,id'],
            'symbol' => ['required', 'string', 'in:BTCUSDT,ETHUSDT'],
            'timeframe' => ['required', 'string', 'in:1h,4h'],
            'initial_capital' => ['required', 'numeric', 'gt:0'],
            'allocation_pct' => ['required', 'numeric', 'gte:0', 'lte:100'],
            'fee_rate' => ['required', 'numeric', 'gte:0', 'lte:100'],
            'slippage_rate' => ['required', 'numeric', 'gte:0', 'lte:100'],
        ]);

        $version = TradingStrategyVersion::query()->with('strategy')->findOrFail($payload['strategy_version_id']);
        $this->authorize('view', $version->strategy);
        $exchange = Exchange::query()->findOrFail($payload['exchange_id']);

        try {
            $session = $this->paperTrading->createSession($request->user(), $version, $exchange, $payload);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['paper_trading' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('trading-bot.paper-trading.show', $session)
            ->with('success', 'Sessão de paper trading criada. Todas as operações são simuladas e nenhuma ordem será enviada.');
    }

    public function show(PaperTradingSession $session): Response
    {
        $this->authorize('view', $session);
        $session->load([
            'strategy:id,name',
            'strategyVersion:id,trading_strategy_id,version,definition_hash',
            'exchange:id,name,description',
            'cycles.trades',
            'trades',
        ]);

        return Inertia::render('TradingBot/PaperTrading/Show', [
            'session' => $session,
            'executionEnabled' => false,
            'mode' => 'manual_paper_trading_only',
        ]);
    }

    public function run(Request $request, PaperTradingSession $session): RedirectResponse
    {
        $this->authorize('update', $session);

        try {
            $cycle = $this->paperTrading->runCycle($request->user(), $session);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['paper_trading' => $exception->getMessage()]);
        }

        return redirect()->route('trading-bot.paper-trading.show', $session)
            ->with('success', "Ciclo manual concluído: {$cycle->candles_processed} candle(s) processado(s), {$cycle->trades()->count()} operação(ões) simulada(s). Nenhuma ordem foi enviada.");
    }

    public function pause(Request $request, PaperTradingSession $session): RedirectResponse
    {
        $this->authorize('update', $session);
        $this->paperTrading->pause($request->user(), $session);

        return back()->with('success', 'Sessão de paper trading pausada.');
    }

    public function resume(Request $request, PaperTradingSession $session): RedirectResponse
    {
        $this->authorize('update', $session);
        $this->paperTrading->resume($request->user(), $session);

        return back()->with('success', 'Sessão de paper trading retomada manualmente.');
    }

    public function archive(Request $request, PaperTradingSession $session): RedirectResponse
    {
        $this->authorize('update', $session);
        $this->paperTrading->archive($request->user(), $session);

        return redirect()->route('trading-bot.paper-trading.index')
            ->with('success', 'Sessão arquivada. O histórico simulado foi preservado para auditoria.');
    }
}
