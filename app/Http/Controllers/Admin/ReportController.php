<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function index(Request $request, ReportService $reports): Response
    {
        Gate::authorize('view-reports');

        $input = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $to = isset($input['to']) ? Carbon::parse($input['to']) : now();
        $from = isset($input['from']) ? Carbon::parse($input['from']) : $to->copy()->subDays(29);
        $days = (int) ($input['days'] ?? 30);

        $sales = $reports->salesPerDay($from, $to);

        return Inertia::render('Admin/Reports', [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
            'sales' => [
                'rows' => $sales,
                'total' => Money::add(...array_column($sales, 'total')),
            ],
            'forfeited' => $reports->forfeitedKept($from, $to),
            'slowMoving' => $reports->slowMoving($days),
        ]);
    }
}
