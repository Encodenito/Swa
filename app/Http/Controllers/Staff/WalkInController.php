<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ItemPresenter;
use App\Http\Presenters\ReservationPresenter;
use App\Http\Requests\Staff\ReasonRequest;
use App\Http\Requests\Staff\StoreWalkInRequest;
use App\Models\Item;
use App\Models\Reservation;
use App\Services\WalkInService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Counter sales for customers without a reservation. Paid in full in cash.
 */
class WalkInController extends Controller
{
    public function create(): Response
    {
        Gate::authorize('sellWalkIn', Reservation::class);

        return Inertia::render('Staff/WalkIns/Create', [
            // Current prices are for display; the sale is priced again, from locked batches, on submit.
            'items' => Item::query()->where('active', true)->withAvailable()->orderBy('name')->get()
                ->map(fn (Item $item) => ItemPresenter::card($item) + ['sku' => $item->sku])
                ->filter(fn (array $item) => $item['available'] > 0)
                ->values(),
        ]);
    }

    public function store(StoreWalkInRequest $request, WalkInService $walkIns): RedirectResponse
    {
        Gate::authorize('sellWalkIn', Reservation::class);

        $sale = $walkIns->sell(
            $request->quantities(),
            $request->validated('walk_in_name'),
            $request->validated('walk_in_phone'),
            $request->user(),
        );

        return redirect()->route('staff.walk-ins.show', $sale)->with('success', 'Sale recorded. Collect the total in cash.');
    }

    public function show(Request $request, Reservation $reservation): Response
    {
        Gate::authorize('view', $reservation);
        abort_unless($reservation->type->value === 'walk_in', 404);

        return Inertia::render('Staff/WalkIns/Show', [
            'sale' => ReservationPresenter::detail($reservation, $request->user()),
        ]);
    }

    public function void(ReasonRequest $request, Reservation $reservation, WalkInService $walkIns): RedirectResponse
    {
        Gate::authorize('void', $reservation);

        $walkIns->void($reservation, $request->validated('reason'), $request->user());

        return back(303)->with('success', 'Sale voided. The items are back in stock; return the cash to the customer.');
    }
}
