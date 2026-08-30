<?php

namespace App\Providers;

use App\Contracts\MarketDataProviderInterface;
use App\Services\BinancePublicMarketDataProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MarketDataProviderInterface::class, BinancePublicMarketDataProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
