<?php

use App\Exceptions\StateConflictException;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetSecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware(SubstituteBindings::class)->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);

        $middleware->append(SetSecurityHeaders::class);

        // The PayMongo webhook is outside the web group (routes/webhooks.php); this exemption is a
        // second guard in case it is ever moved back.
        $middleware->validateCsrfTokens(except: [
            'webhooks/paymongo',
        ]);

        $middleware->web(append: [
            EnsureAccountIsActive::class,
            EnsurePasswordIsChanged::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Someone else changed the record first: 409 for JSON, a flash error for Inertia pages.
        $exceptions->render(function (StateConflictException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 409);
            }

            return back(303)->with('error', $e->getMessage());
        });
    })->create();
