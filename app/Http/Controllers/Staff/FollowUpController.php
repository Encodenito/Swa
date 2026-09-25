<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\FollowUp;
use App\Services\CrmService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FollowUpController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', FollowUp::class);

        $tab = $request->validate(['tab' => ['nullable', Rule::in(['overdue', 'today', 'upcoming', 'done'])]])['tab'] ?? 'overdue';
        $today = today()->toDateString();

        $base = FollowUp::query()->with(['customer:id,name', 'assignee:id,name', 'author:id,name', 'reservation:id,reference_code']);

        $query = match ($tab) {
            'overdue' => (clone $base)->open()->where('due_on', '<', $today)->orderBy('due_on'),
            'today' => (clone $base)->open()->where('due_on', $today),
            'upcoming' => (clone $base)->open()->where('due_on', '>', $today)->orderBy('due_on'),
            'done' => (clone $base)->whereNotNull('completed_at')->latest('completed_at'),
        };

        return Inertia::render('Staff/FollowUps', [
            'tab' => $tab,
            'followUps' => $query->paginate(30)->withQueryString()->through(fn (FollowUp $f) => CustomerController::followUp($f)),
            'counts' => [
                'overdue' => FollowUp::query()->open()->where('due_on', '<', $today)->count(),
                'today' => FollowUp::query()->open()->where('due_on', $today)->count(),
                'upcoming' => FollowUp::query()->open()->where('due_on', '>', $today)->count(),
            ],
        ]);
    }

    public function complete(Request $request, FollowUp $followUp, CrmService $crm): RedirectResponse
    {
        Gate::authorize('complete', $followUp);

        $crm->completeFollowUp($followUp, $request->user());

        return back(303)->with('success', 'Follow-up marked done.');
    }
}
