<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\DashboardService;
use App\Services\HealthCheckService;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(DashboardService $dashboard, HealthCheckService $health): Response
    {
        Gate::authorize('viewAny', Reservation::class);

        return Inertia::render('Staff/Home', [
            'summary' => $dashboard->summary(),
            // Only problems, e.g. the queue worker or scheduler has stopped.
            'health' => array_values(array_filter($health->operations(), fn (array $c) => $c['status'] !== HealthCheckService::OK)),
        ]);
    }
}
