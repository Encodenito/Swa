<?php

namespace App\Providers;

use App\Models\Item;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\StockBatch;
use App\Models\User;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SmsSender::class, LogSmsSender::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        Gate::define('manage-settings', fn (User $user) => $user->isAdmin());
        Gate::define('view-audit-log', fn (User $user) => $user->isAdmin());
        Gate::define('view-reports', fn (User $user) => $user->isAdmin());

        // Short, stable names in audit_logs.subject_type instead of PHP class names.
        Relation::enforceMorphMap([
            'user' => User::class,
            'item' => Item::class,
            'stock_batch' => StockBatch::class,
            'reservation' => Reservation::class,
            'payment' => Payment::class,
            'setting' => Setting::class,
        ]);
    }

    private function configureRateLimiting(): void
    {
        // Per-IP cap on login attempts; LoginRequest already limits each email+IP pair to 5.
        RateLimiter::for('login-ip', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(5)->by($request->ip()));

        RateLimiter::for('reservations', fn (Request $request) => Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()));

        // Browsing and account pages (catalog search, cart edits, the payment status poll).
        RateLimiter::for('browse', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        // Staff and admin writes: generous for real counter work, but a stolen session cannot script thousands.
        RateLimiter::for('staff-writes', fn (Request $request) => Limit::perMinute(30)->by('staff:'.($request->user()?->id ?: $request->ip())));

        RateLimiter::for('account-admin', fn (Request $request) => Limit::perMinute(10)->by('accounts:'.($request->user()?->id ?: $request->ip())));

        RateLimiter::for('paymongo-webhook', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
    }
}
