<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\DashboardService;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(DashboardService $dashboard): Response
    {
        Gate::authorize('viewAny', Reservation::class);

        return Inertia::render('Staff/Home', [
            'summary' => $dashboard->summary(),
        ]);
    }
}
