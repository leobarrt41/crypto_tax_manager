<?php

namespace App\Providers;

use App\Models\BacktestRun;
use App\Models\PaperTradingSession;
use App\Policies\BacktestRunPolicy;
use App\Policies\PaperTradingSessionPolicy;

// use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        BacktestRun::class => BacktestRunPolicy::class,
        PaperTradingSession::class => PaperTradingSessionPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}
