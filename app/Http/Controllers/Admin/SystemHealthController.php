<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\HealthCheckService;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The `swa:preflight` checks on a page. Read-only; secret values are never included.
 */
class SystemHealthController extends Controller
{
    public function __invoke(HealthCheckService $health): Response
    {
        Gate::authorize('manage-settings');

        $checks = $health->all();

        return Inertia::render('Admin/SystemHealth', [
            'checks' => $checks,
            'summary' => collect($checks)->countBy('status'),
            'checkedAt' => now()->toIso8601String(),
        ]);
    }
}
