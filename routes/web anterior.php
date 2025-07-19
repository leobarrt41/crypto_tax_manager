<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ExchangeKeyController;
use App\Http\Controllers\CryptoAssetController;
use App\Http\Controllers\TransactionController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Rota inicial
Route::get('/', function () {
    Log::info('Rota inicial acessada.');
    
    if (auth()->check()) { // Verifica autenticação
        Log::info('Usuário está logado.', ['user' => auth()->user()]);
        return redirect()->route('dashboard');
    }
    
    // Log para usuário não autenticado
    Log::info('Usuário não autenticado.');
    
    // Página de boas-vindas para usuários não autenticados
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
})->name('welcome');

// Rota de login (opcional, caso precise verificar)
Route::get('/login', function () {
    return Inertia::render('Auth/Login', [
        'canRegister' => Route::has('register'),
    ]);
})->name('login');

// Rota de registro (opcional, caso precise verificar)
Route::get('/register', function () {
    return Inertia::render('Auth/Register', [
        'canLogin' => Route::has('login'),
    ]);
})->name('register');


// Rota para o Dashboard
Route::get('/dashboard', function () {
  
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// Rotas protegidas por autenticação
Route::middleware('auth')->group(function () {
    // Rotas do perfil
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Cadastro de Chaves (Exchange Keys)
    Route::get('/exchanges/keys', [ExchangeKeyController::class, 'index'])->name('exchanges.keys');
    Route::post('/exchanges/keys', [ExchangeKeyController::class, 'store'])->name('exchanges.keys.store');

    Route::delete('/exchanges/keys/{id}', [ExchangeKeyController::class, 'destroy'])->name('exchanges.keys.destroy');
   
    Route::get('/crypto-assets', [CryptoAssetController::class, 'list'])->name('crypto.assets.list');
    Route::post('/import-crypto/{exchange}', [CryptoAssetController::class, 'import'])->name('crypto.assets.import');

    // Listagem de Moedas (Crypto Assets)
    Route::post('/crypto-assets/update', [CryptoAssetController::class, 'update'])->name('crypto.assets.update');
    
    
    Route::get('/crypto-assets/parity-data', [CryptoAssetController::class, 'parityData'])->name('crypto.assets.parity');
    // Movimentação (Transactions)

    Route::get('/crypto-assets/chart-data/{symbol}', [CryptoAssetController::class, 'getCryptoChartData'])->name('crypto.assets.chart');
   

    Route::get('/api/crypto/{symbol}/chart', [CryptoAssetController::class, 'getCryptoChartData']);


    Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::post('/import-binance-transactions', [TransactionController::class, 'importFromBinance'])->name('transactions.import.binance');

});

Route::get('/test', function () {
    return 'Middleware Testado';
})->middleware('auth');


require __DIR__.'/auth.php';

