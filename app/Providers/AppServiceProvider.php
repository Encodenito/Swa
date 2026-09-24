<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        // Per-IP cap on login attempts; LoginRequest already limits each email+IP pair to 5.
        RateLimiter::for('login-ip', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(5)->by($request->ip()));
    }
}
