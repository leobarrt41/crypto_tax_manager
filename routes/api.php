<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TradingBotController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\CryptoAssetController;
use App\Http\Controllers\ExchangeKeyController;
use App\Http\Controllers\TaxReportController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
|--------------------------------------------------------------------------
| Dashboard API Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->group(function () {
    
    // Dashboard Stats
    Route::get('/dashboard/stats', [DashboardController::class, 'getStats']);
    Route::get('/dashboard/portfolio-chart', [DashboardController::class, 'getPortfolioChart']);
    Route::get('/dashboard/recent-transactions', [DashboardController::class, 'getRecentTransactions']);
    Route::get('/dashboard/top-assets', [DashboardController::class, 'getTopAssets']);
    
    // Real-time data
    Route::get('/dashboard/live-prices', [DashboardController::class, 'getLivePrices']);
    Route::get('/dashboard/market-summary', [DashboardController::class, 'getMarketSummary']);
});

/*
|--------------------------------------------------------------------------
| Trading Bot API Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->prefix('trading-bot')->group(function () {
    
    // Bot Control
    Route::post('/start', [TradingBotController::class, 'startBot']);
    Route::post('/stop', [TradingBotController::class, 'stopBot']);
    Route::get('/status', [TradingBotController::class, 'getBotStatus']);
    
    // Strategies
    Route::get('/strategies', [TradingBotController::class, 'getStrategies']);
    Route::post('/strategies', [TradingBotController::class, 'createStrategy']);
    Route::put('/strategies/{id}', [TradingBotController::class, 'updateStrategy']);
    Route::delete('/strategies/{id}', [TradingBotController::class, 'deleteStrategy']);
    Route::post('/strategies/{id}/toggle', [TradingBotController::class, 'toggleStrategy']);
    
    // Orders
    Route::get('/orders', [TradingBotController::class, 'getOrders']);
    Route::get('/orders/recent', [TradingBotController::class, 'getRecentOrders']);
    Route::post('/orders/cancel/{id}', [TradingBotController::class, 'cancelOrder']);
    
    // Logs
    Route::get('/logs', [TradingBotController::class, 'getLogs']);
    Route::get('/logs/live', [TradingBotController::class, 'getLiveLogs']);
    
    // Performance
    Route::get('/performance', [TradingBotController::class, 'getPerformance']);
    Route::get('/performance/chart', [TradingBotController::class, 'getPerformanceChart']);
});

/*
|--------------------------------------------------------------------------
| Transactions API Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->prefix('transactions')->group(function () {
    
    // CRUD Operations
    Route::get('/', [TransactionController::class, 'index']);
    Route::post('/', [TransactionController::class, 'store']);
    Route::get('/{id}', [TransactionController::class, 'show']);
    Route::put('/{id}', [TransactionController::class, 'update']);
    Route::delete('/{id}', [TransactionController::class, 'destroy']);
    
    // Import/Export
    Route::post('/import', [TransactionController::class, 'import']);
    Route::get('/export', [TransactionController::class, 'export']);
    Route::post('/sync-exchange', [TransactionController::class, 'syncFromExchange']);
    
    // Tax Calculations
    Route::get('/tax-summary', [TransactionController::class, 'getTaxSummary']);
    Route::get('/fifo-calculation', [TransactionController::class, 'getFifoCalculation']);
    Route::post('/generate-in1888', [TransactionController::class, 'generateIN1888']);
});

/*
|--------------------------------------------------------------------------
| Crypto Assets API Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->prefix('crypto-assets')->group(function () {
    
    // Assets Management
    Route::get('/', [CryptoAssetController::class, 'index']);
    Route::post('/', [CryptoAssetController::class, 'store']);
    Route::put('/{id}', [CryptoAssetController::class, 'update']);
    Route::delete('/{id}', [CryptoAssetController::class, 'destroy']);
    
    // Market Data
    Route::get('/prices', [CryptoAssetController::class, 'getCurrentPrices']);
    Route::get('/{symbol}/price-history', [CryptoAssetController::class, 'getPriceHistory']);
    Route::get('/{symbol}/market-data', [CryptoAssetController::class, 'getMarketData']);
    
    // Portfolio
    Route::get('/portfolio', [CryptoAssetController::class, 'getPortfolio']);
    Route::get('/portfolio/allocation', [CryptoAssetController::class, 'getPortfolioAllocation']);
    Route::get('/portfolio/performance', [CryptoAssetController::class, 'getPortfolioPerformance']);
});

/*
|--------------------------------------------------------------------------
| Exchange Keys API Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->prefix('exchange-keys')->group(function () {
    
    // API Keys Management
    Route::get('/', [ExchangeKeyController::class, 'index']);
    Route::post('/', [ExchangeKeyController::class, 'store']);
    Route::put('/{id}', [ExchangeKeyController::class, 'update']);
    Route::delete('/{id}', [ExchangeKeyController::class, 'destroy']);
    
    // Connection Testing
    Route::post('/{id}/test', [ExchangeKeyController::class, 'testConnection']);
    Route::get('/{id}/balance', [ExchangeKeyController::class, 'getBalance']);
    Route::get('/{id}/trading-pairs', [ExchangeKeyController::class, 'getTradingPairs']);
});

/*
|--------------------------------------------------------------------------
| Backtesting API Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->prefix('backtesting')->group(function () {
    
    // Backtesting Operations
    Route::post('/run', [TradingBotController::class, 'runBacktest']);
    Route::get('/results/{id}', [TradingBotController::class, 'getBacktestResults']);
    Route::get('/history', [TradingBotController::class, 'getBacktestHistory']);
    Route::delete('/results/{id}', [TradingBotController::class, 'deleteBacktestResult']);
    
    // Strategy Templates
    Route::get('/strategy-templates', [TradingBotController::class, 'getStrategyTemplates']);
    Route::get('/market-data/{symbol}', [TradingBotController::class, 'getMarketDataForBacktest']);
});

/*
|--------------------------------------------------------------------------
| Tax Reports API Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->prefix('tax-reports')->group(function () {
    
    // IN 1888 Reports
    Route::get('/in1888/preview', [TransactionController::class, 'previewIN1888']);
    Route::post('/in1888/generate', [TransactionController::class, 'generateIN1888']);
    Route::get('/in1888/download/{filename}', [TransactionController::class, 'downloadIN1888']);
    
    // Tax Calculations
    Route::get('/capital-gains', [TransactionController::class, 'getCapitalGains']);
    Route::get('/monthly-summary/{year}', [TransactionController::class, 'getMonthlySummary']);
    Route::get('/annual-summary/{year}', [TransactionController::class, 'getAnnualSummary']);
    
    // Compliance Check
    Route::get('/compliance-status', [TransactionController::class, 'getComplianceStatus']);
    Route::get('/missing-data', [TransactionController::class, 'getMissingData']);

    // ── FIFO / Relatórios IR ──
    Route::get('/relatorio-ir/summary', [TaxReportController::class, 'monthlySummary']);
    Route::post('/relatorio-ir/recalculate', [TaxReportController::class, 'recalculateFifo']);
    Route::get('/relatorio-ir/export-csv', [TaxReportController::class, 'exportCsv']);
});

/*
|--------------------------------------------------------------------------
| Market Data API Routes (Public)
|--------------------------------------------------------------------------
*/
Route::prefix('market')->group(function () {
    
    // Public market data (no auth required)
    Route::get('/prices/{symbols}', [CryptoAssetController::class, 'getPublicPrices']);
    Route::get('/trending', [CryptoAssetController::class, 'getTrendingAssets']);
    Route::get('/market-cap', [CryptoAssetController::class, 'getMarketCapData']);
    
    // Exchange status
    Route::get('/exchange-status', [ExchangeKeyController::class, 'getExchangeStatus']);
    Route::get('/supported-exchanges', [ExchangeKeyController::class, 'getSupportedExchanges']);
});

/*
|--------------------------------------------------------------------------
| Webhook Routes (for exchange notifications)
|--------------------------------------------------------------------------
*/
Route::prefix('webhooks')->group(function () {
    
    // Exchange webhooks
    Route::post('/binance/order-update', [TradingBotController::class, 'handleBinanceWebhook']);
    Route::post('/coinbase/order-update', [TradingBotController::class, 'handleCoinbaseWebhook']);
    
    // Price alerts
    Route::post('/price-alert', [CryptoAssetController::class, 'handlePriceAlert']);
});

/*
|--------------------------------------------------------------------------
| System Health Routes
|--------------------------------------------------------------------------
*/
Route::prefix('system')->group(function () {
    
    // Health checks
    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now(),
            'version' => '1.0.0'
        ]);
    });
    
    Route::get('/status', [DashboardController::class, 'getSystemStatus']);
});

