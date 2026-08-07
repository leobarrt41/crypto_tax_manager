<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ExchangeKeyController;
use App\Http\Controllers\CryptoAssetController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\TaxRuleController;
use App\Http\Controllers\TradingStrategyController;
use App\Http\Controllers\BotOrderController;
use App\Http\Controllers\TradingLogController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TaxReportController;
use App\Http\Controllers\In1888StatusController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\TradingBotController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

// ===== ROTA INICIAL =====
Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }
    
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
})->name('welcome');

// ===== ROTAS DE AUTENTICAÇÃO =====
require __DIR__.'/auth.php';

// ===== DASHBOARD =====
Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

// ===== GRUPO DE ROTAS PROTEGIDAS =====
Route::middleware(['auth', 'verified'])->group(function () {
    
    // ===== PERFIL =====
    Route::prefix('profile')->name('profile.')->group(function () {
        Route::get('/', [ProfileController::class, 'edit'])->name('edit');
        Route::patch('/', [ProfileController::class, 'update'])->name('update');
        Route::delete('/', [ProfileController::class, 'destroy'])->name('destroy');
    });

    // ===== CHAVES DE API DAS EXCHANGES =====
    Route::prefix('exchanges/keys')->name('exchanges.keys.')->group(function () {
        Route::get('/', [ExchangeKeyController::class, 'index'])->name('index');
        Route::get('/create', [ExchangeKeyController::class, 'create'])->name('create');
        Route::post('/', [ExchangeKeyController::class, 'store'])->name('store');
        Route::get('/{exchangeKey}', [ExchangeKeyController::class, 'show'])->name('show');
        Route::get('/{exchangeKey}/edit', [ExchangeKeyController::class, 'edit'])->name('edit');
        Route::patch('/{exchangeKey}', [ExchangeKeyController::class, 'update'])->name('update');
        Route::delete('/{exchangeKey}', [ExchangeKeyController::class, 'destroy'])->name('destroy');
        
        // Ações especiais
        Route::post('/{exchangeKey}/test', [ExchangeKeyController::class, 'testConnection'])->name('test');
        Route::post('/{exchangeKey}/sync', [ExchangeKeyController::class, 'syncTransactions'])->name('sync');
    });

    // ===== TRANSAÇÕES =====
 Route::prefix('transactions')->name('transactions.')->group(function () {
        Route::get('/', [TransactionController::class, 'index'])->name('index');
        Route::get('/import', [TransactionController::class, 'import'])->name('import');
        Route::get('/create', [TransactionController::class, 'create'])->name('create');
        Route::post('/', [TransactionController::class, 'store'])->name('store');
        
        Route::get('/{transaction}', [TransactionController::class, 'show'])->whereNumber('transaction')->name('show');
        Route::get('/{transaction}/edit', [TransactionController::class, 'edit'])->whereNumber('transaction')->name('edit');
        Route::patch('/{transaction}', [TransactionController::class, 'update'])->whereNumber('transaction')->name('update');
        Route::delete('/{transaction}', [TransactionController::class, 'destroy'])->whereNumber('transaction')->name('destroy');
        
        // Ações em Massa
        Route::delete('/delete-all', [TransactionController::class, 'destroyAll'])->name('destroyAll');
        Route::post('/import/csv', [TransactionController::class, 'importCsv'])->name('import.csv');
        Route::post('/export/{format}', [TransactionController::class, 'export'])->name('export');
    
     // PORTFÓLIO, TRADING BOT, RELATÓRIOS, etc.
    // (Todos os seus outros grupos de rotas permanecem aqui, sem alterações)
    // ...
    // ...

    // ===================================================================
    // ROTAS COM RATE LIMITING (para ações "caras" de API)
    // ===================================================================
    Route::middleware('throttle:10,1')->group(function () {
        
        // ✅ ROTA ÚNICA E CORRETA PARA SINCRONIZAR TRANSAÇÕES DE UMA EXCHANGE
        Route::post('/sync-transactions/{exchange}', [TransactionController::class, 'syncFromExchange'])->name('sync');
        
        Route::post('/import-crypto/{exchange}', [CryptoAssetController::class, 'importCryptoAssets'])->name('crypto.assets.import');
    });
    
    
    
    });




    // ===== PORTFÓLIO =====
    Route::prefix('portfolio')->name('portfolio.')->group(function () {
        Route::get('/', [PortfolioController::class, 'index'])->name('index');
        Route::get('/analytics', [PortfolioController::class, 'analytics'])->name('analytics');
        Route::get('/performance', [PortfolioController::class, 'performance'])->name('performance');
        Route::get('/allocation', [PortfolioController::class, 'allocation'])->name('allocation');
        
        // API endpoints para gráficos
        Route::get('/api/summary', [PortfolioController::class, 'apiSummary'])->name('api.summary');
        Route::get('/api/chart-data', [PortfolioController::class, 'apiChartData'])->name('api.chart');
        Route::get('/api/allocation-data', [PortfolioController::class, 'apiAllocationData'])->name('api.allocation');
    });

    // ===== TRADING BOT =====
    Route::prefix('trading-bot')->name('trading-bot.')->group(function () {
        Route::get('/', [TradingBotController::class, 'dashboard'])->name('dashboard');
        Route::post('/start', [TradingBotController::class, 'start'])->name('start');
        Route::post('/stop', [TradingBotController::class, 'stop'])->name('stop');
        Route::get('/status', [TradingBotController::class, 'status'])->name('status');
    });

    // ===== ESTRATÉGIAS DE TRADING =====
    Route::prefix('trading-strategies')->name('trading-strategies.')->group(function () {
        Route::get('/', [TradingStrategyController::class, 'index'])->name('index');
        Route::get('/create', [TradingStrategyController::class, 'create'])->name('create');
        Route::post('/', [TradingStrategyController::class, 'store'])->name('store');
        Route::get('/{strategy}', [TradingStrategyController::class, 'show'])->name('show');
        Route::get('/{strategy}/edit', [TradingStrategyController::class, 'edit'])->name('edit');
        Route::patch('/{strategy}', [TradingStrategyController::class, 'update'])->name('update');
        Route::delete('/{strategy}', [TradingStrategyController::class, 'destroy'])->name('destroy');
        
        // Ações especiais
        Route::post('/{strategy}/start', [TradingStrategyController::class, 'start'])->name('start');
        Route::post('/{strategy}/stop', [TradingStrategyController::class, 'stop'])->name('stop');
        Route::post('/{strategy}/backtest', [TradingStrategyController::class, 'backtest'])->name('backtest');
    });

    // ===== ORDENS DO BOT =====
    Route::prefix('bot-orders')->name('bot-orders.')->group(function () {
        Route::get('/', [BotOrderController::class, 'index'])->name('index');
        Route::get('/{order}', [BotOrderController::class, 'show'])->name('show');
        Route::post('/{order}/cancel', [BotOrderController::class, 'cancel'])->name('cancel');
        Route::delete('/{order}', [BotOrderController::class, 'destroy'])->name('destroy');
    });

    // ===== LOGS DE TRADING =====
    Route::prefix('trading-logs')->name('trading-logs.')->group(function () {
        Route::get('/', [TradingLogController::class, 'index'])->name('index');
        Route::get('/{log}', [TradingLogController::class, 'show'])->name('show');
        Route::delete('/clear', [TradingLogController::class, 'clear'])->name('clear');
        Route::post('/export', [TradingLogController::class, 'export'])->name('export');
    });

    // ===== RELATÓRIOS =====
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('/in1888', [ReportController::class, 'in1888'])->name('in1888');
        Route::get('/tax-summary', [ReportController::class, 'taxSummary'])->name('tax-summary');
        Route::get('/portfolio', [ReportController::class, 'portfolioReport'])->name('portfolio');
        Route::get('/transactions', [ReportController::class, 'transactionReport'])->name('transactions');
        Route::get('/performance', [ReportController::class, 'performanceReport'])->name('performance');
        
        // ── Relatórios IR (FIFO / Ganhos de Capital) ──
        Route::get('/relatorio-ir', [TaxReportController::class, 'index'])->name('relatorio-ir');
        Route::get('/relatorio-ir/summary', [TaxReportController::class, 'monthlySummary'])->name('relatorio-ir.summary');
        Route::post('/relatorio-ir/recalculate', [TaxReportController::class, 'recalculateFifo'])->name('relatorio-ir.recalculate');
        Route::get('/relatorio-ir/export-csv', [TaxReportController::class, 'exportCsv'])->name('relatorio-ir.export-csv');

        // ── IN 1888 (status mensal/anual) via sessão web ──
        Route::prefix('in1888-status')->name('in1888-status.')->group(function () {
            Route::get('/current', [In1888StatusController::class, 'current'])->name('current');
            Route::get('/annual', [In1888StatusController::class, 'annual'])->name('annual');
            Route::get('/monthly', [In1888StatusController::class, 'monthly'])->name('monthly');
            Route::get('/export-csv', [In1888StatusController::class, 'exportCsv'])->name('export-csv');
        });

        // Exportações
        Route::post('/export/in1888', [ReportController::class, 'exportIn1888'])->name('export.in1888');
        Route::post('/export/tax/{format}', [ReportController::class, 'exportTax'])->name('export.tax');
        Route::post('/export/portfolio/{format}', [ReportController::class, 'exportPortfolio'])->name('export.portfolio');
        Route::post('/export/transactions/{format}', [ReportController::class, 'exportTransactions'])->name('export.transactions');
    });

    // ===== ALIAS DE URL PARA RELATÓRIOS FISCAIS =====
    Route::prefix('tax-reports')->name('tax-reports.')->group(function () {
        Route::get('/in1888', [ReportController::class, 'in1888'])->name('in1888');
        Route::get('/in1888-status/current', [In1888StatusController::class, 'current'])->name('in1888-status.current');
        Route::get('/in1888-status/annual', [In1888StatusController::class, 'annual'])->name('in1888-status.annual');
        Route::get('/in1888-status/monthly', [In1888StatusController::class, 'monthly'])->name('in1888-status.monthly');
        Route::get('/in1888-status/export-csv', [In1888StatusController::class, 'exportCsv'])->name('in1888-status.export-csv');
    });

    // ===== REGRAS FISCAIS =====
    Route::prefix('tax-rules')->name('tax-rules.')->group(function () {
        Route::get('/', [TaxRuleController::class, 'index'])->name('index');
        Route::get('/create', [TaxRuleController::class, 'create'])->name('create');
        Route::post('/', [TaxRuleController::class, 'store'])->name('store');
        Route::get('/{taxRule}', [TaxRuleController::class, 'show'])->name('show');
        Route::get('/{taxRule}/edit', [TaxRuleController::class, 'edit'])->name('edit');
        Route::patch('/{taxRule}', [TaxRuleController::class, 'update'])->name('update');
        Route::delete('/{taxRule}', [TaxRuleController::class, 'destroy'])->name('destroy');
    });

    // ===== NOTIFICAÇÕES =====
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::post('/{notification}/read', [NotificationController::class, 'markAsRead'])->name('read');
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead'])->name('read-all');
        Route::delete('/{notification}', [NotificationController::class, 'destroy'])->name('destroy');
    });

    // ===== CONFIGURAÇÕES =====
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', function () {
            return Inertia::render('Settings/Index');
        })->name('index');
        
        Route::get('/preferences', function () {
            return Inertia::render('Settings/Preferences');
        })->name('preferences');
        
        Route::get('/notifications', function () {
            return Inertia::render('Settings/Notifications');
        })->name('notifications');
        
        Route::get('/security', function () {
            return Inertia::render('Settings/Security');
        })->name('security');
        
        Route::get('/api', function () {
            return Inertia::render('Settings/Api');
        })->name('api');
    });

    // ===== CRYPTO ASSETS =====
    Route::prefix('crypto-assets')->name('crypto.assets.')->group(function () {
        Route::get('/all', [CryptoAssetController::class, 'all']);
        Route::get('/', [CryptoAssetController::class, 'index'])->name('index');
        Route::get('/list', [CryptoAssetController::class, 'list'])->name('list');
        Route::post('/update', [CryptoAssetController::class, 'update'])->name('update');
        Route::get('/parity-data', [CryptoAssetController::class, 'parityData'])->name('parity');
        Route::get('/chart-data/{symbol}', [CryptoAssetController::class, 'getCryptoChartData'])->name('chart');
        Route::get('/{symbol}', [CryptoAssetController::class, 'show'])->name('show');
    });
});

// ===== ROTAS COM RATE LIMITING =====
Route::middleware(['auth', 'verified', 'throttle:10,1'])->group(function () {
    // Importações de dados (limitadas)
    Route::post('/import-crypto/{exchange}', [CryptoAssetController::class, 'importCryptoAssets'])->name('crypto.assets.import');
    Route::post('/import-transactions/{exchange}', [TransactionController::class, 'importFromExchange'])->name('transactions.import.exchange');
});

// ===== WEBHOOKS (SEM AUTENTICAÇÃO) =====
Route::prefix('webhooks')->name('webhooks.')->group(function () {
    Route::post('/binance', [TransactionController::class, 'binanceWebhook'])->name('binance');
    Route::post('/coinbase', [TransactionController::class, 'coinbaseWebhook'])->name('coinbase');
    Route::post('/kucoin', [TransactionController::class, 'kucoinWebhook'])->name('kucoin');
    Route::post('/mercadobitcoin', [TransactionController::class, 'mercadoBitcoinWebhook'])->name('mercadobitcoin');
});

// ===== ROTAS DE DESENVOLVIMENTO =====
if (app()->environment('local', 'staging')) {
    Route::prefix('dev')->name('dev.')->group(function () {
        Route::get('/test-middleware', function () {
            return response()->json([
                'message' => 'Middleware funcionando',
                'user' => auth()->user(),
                'timestamp' => now()
            ]);
        })->middleware('auth');
        
        Route::get('/test-db', function () {
            return response()->json([
                'message' => 'Banco conectado',
                'time' => now(),
                'connection' => config('database.default')
            ]);
        });
        
        Route::get('/routes', function () {
            $routes = collect(Route::getRoutes())->map(function ($route) {
                return [
                    'method' => implode('|', $route->methods()),
                    'uri' => $route->uri(),
                    'name' => $route->getName(),
                    'action' => $route->getActionName(),
                ];
            });
            
            return response()->json($routes);
        });
    });
}

Route::get('/run-binance-diagnostic', [\App\Http\Controllers\TransactionController::class, 'runBinanceDiagnostic']);


// ===== FALLBACK ROUTE =====
Route::fallback(function () {
    return Inertia::render('Errors/404');
});
