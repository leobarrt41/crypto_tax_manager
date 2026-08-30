<?php

namespace App\Http\Controllers;

use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Services\StrategyDefinitionValidator;
use App\Services\StrategySignalEvaluator;
use App\Services\StrategyVersionService;
use App\Services\TradingAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StrategyDefinitionController extends Controller
{
    public function __construct(
        private readonly StrategyVersionService $strategyVersions,
        private readonly StrategyDefinitionValidator $validator,
        private readonly StrategySignalEvaluator $signalEvaluator,
        private readonly TradingAuditLogger $auditLogger,
    ) {
    }

    public function overview(Request $request): Response
    {
        $strategies = $request->user()->tradingStrategies();
        $latestStrategy = (clone $strategies)->latest('updated_at')->first(['id', 'updated_at']);

        return Inertia::render('TradingBot/Overview', [
            'summary' => [
                'strategies_count' => (clone $strategies)->count(),
                'versions_count' => TradingStrategyVersion::query()
                    ->whereHas('strategy', fn ($query) => $query->where('user_id', $request->user()->id))
                    ->count(),
                'last_updated_at' => $latestStrategy?->updated_at?->toIso8601String(),
            ],
            'executionEnabled' => false,
        ]);
    }

    public function index(Request $request): Response
    {
        $strategies = $request->user()->tradingStrategies()
            ->notArchived()
            ->with('currentVersion:id,trading_strategy_id,version,status,definition_hash,created_at')
            ->withCount('versions')
            ->latest()
            ->get();

        return Inertia::render('TradingBot/Strategies/Index', [
            'strategies' => $strategies,
            'executionEnabled' => false,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('TradingBot/Strategies/Editor', [
            'strategy' => null,
            'version' => null,
            'catalog' => $this->catalog(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validatedPayload($request);
        $strategy = $this->strategyVersions->createStrategy(
            $request->user(),
            $payload['name'],
            $payload['description'] ?? null,
            $payload['definition'],
        );

        return redirect()->route('trading-bot.strategies.show', $strategy)
            ->with('success', 'Estratégia salva para backtesting. Nenhuma operação foi criada.');
    }

    public function show(TradingStrategy $strategy): Response
    {
        $this->authorize('view', $strategy);

        $strategy->load([
            'currentVersion',
            'versions' => fn ($query) => $query->select([
                'id', 'trading_strategy_id', 'version', 'definition_hash', 'status', 'created_by', 'created_at',
            ])->with('creator:id,name'),
        ]);

        return Inertia::render('TradingBot/Strategies/Show', [
            'strategy' => $strategy,
            'executionEnabled' => false,
        ]);
    }

    public function edit(TradingStrategy $strategy): Response
    {
        $this->authorize('update', $strategy);

        return Inertia::render('TradingBot/Strategies/Editor', [
            'strategy' => $strategy,
            'version' => $strategy->currentVersion,
            'catalog' => $this->catalog(),
        ]);
    }

    public function update(Request $request, TradingStrategy $strategy): RedirectResponse
    {
        $this->authorize('update', $strategy);
        $payload = $this->validatedPayload($request);
        $version = $this->strategyVersions->createNewVersion(
            $strategy,
            $request->user(),
            $payload['name'],
            $payload['description'] ?? null,
            $payload['definition'],
        );

        return redirect()->route('trading-bot.strategies.show', $strategy)
            ->with('success', "Versão {$version->version} salva para backtesting. Nenhuma operação foi criada.");
    }

    public function archive(Request $request, TradingStrategy $strategy): RedirectResponse
    {
        $this->authorize('archive', $strategy);
        $this->strategyVersions->archive($strategy, $request->user());

        return redirect()->route('trading-bot.strategies.index')
            ->with('success', 'Estratégia arquivada. O histórico de versões foi preservado.');
    }

    public function validateDefinition(Request $request, ?TradingStrategy $strategy = null): JsonResponse
    {
        if ($strategy !== null) {
            $this->authorize('update', $strategy);
        }

        $definition = $request->validate(['definition' => ['required', 'array']])['definition'];
        $normalized = $this->validator->validate($definition);

        $this->auditLogger->record(
            $request->user()->id,
            'strategy_definition_validated',
            'Definição de estratégia validada sem persistência.',
            'info',
            $strategy?->id,
            payload: [
                'entry_conditions_count' => count($normalized['entry_conditions']),
                'exit_conditions_count' => count($normalized['exit_conditions']),
            ],
            source: 'strategy_definition_controller',
        );

        return response()->json([
            'valid' => true,
            'definition' => $normalized,
            'minimum_closed_candles' => $this->minimumRequiredCandles($normalized),
        ]);
    }

    public function preview(Request $request, TradingStrategy $strategy): JsonResponse
    {
        $this->authorize('view', $strategy);
        $payload = $request->validate([
            'candles' => ['required', 'array', 'min:1'],
            'candles.*.close' => ['required', 'numeric'],
            'candles.*.close_time' => ['required'],
            'candles.*.is_closed' => ['nullable', 'boolean'],
        ]);

        $version = $strategy->currentVersion;
        abort_if($version === null, 422, 'A estratégia não possui uma versão atual para prévia.');
        $result = $this->signalEvaluator->evaluate($version, $payload['candles']);

        $this->auditLogger->record(
            $request->user()->id,
            'strategy_signal_previewed',
            "Prévia determinística da versão {$version->version} executada sem criar operação.",
            'info',
            $strategy->id,
            [
                'strategy_version_id' => $version->id,
                'definition_hash' => $version->definition_hash,
                'decision' => $result['decision'],
                'data_status' => $result['data_status'],
            ],
            'strategy_definition_controller',
        );

        return response()->json($result);
    }

    /** @return array{name: string, description?: string|null, definition: array<string, mixed>} */
    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'definition' => ['required', 'array'],
        ]);
    }

    /** @param array<string, mixed> $definition */
    private function minimumRequiredCandles(array $definition): int
    {
        $conditions = array_merge($definition['entry_conditions'], $definition['exit_conditions']);

        return max(1, ...array_map(function (array $condition): int {
            $parameters = $condition['parameters'] ?? [];

            $required = match ($condition['indicator']) {
                'rsi' => ((int) $parameters['period']) + 1,
                'sma', 'ema', 'bollinger' => (int) $parameters['period'],
                'macd' => (int) $parameters['slow_period'] + (int) $parameters['signal_period'] - 1,
                'ma_cross' => (int) $parameters['slow_period'],
                default => 1,
            };

            return $required + (in_array($condition['operator'] ?? null, ['crosses_above', 'crosses_below'], true) ? 1 : 0);
        }, $conditions));
    }

    /** @return array<string, mixed> */
    private function catalog(): array
    {
        return [
            'indicators' => [
                ['key' => 'rsi', 'label' => 'RSI', 'parameters' => ['period']],
                ['key' => 'sma', 'label' => 'Média móvel simples (SMA)', 'parameters' => ['period']],
                ['key' => 'ema', 'label' => 'Média móvel exponencial (EMA)', 'parameters' => ['period']],
                ['key' => 'macd', 'label' => 'MACD', 'parameters' => ['fast_period', 'slow_period', 'signal_period']],
                ['key' => 'bollinger', 'label' => 'Bandas de Bollinger', 'parameters' => ['period', 'std_dev']],
                ['key' => 'ma_cross', 'label' => 'Cruzamento de médias exponenciais', 'parameters' => ['fast_period', 'slow_period']],
            ],
            'operators' => [
                'greater_than', 'less_than', 'greater_than_or_equal', 'less_than_or_equal',
                'crosses_above', 'crosses_below', 'greater_than_indicator', 'less_than_indicator',
                'close_above_upper_band', 'close_below_lower_band',
            ],
        ];
    }
}
