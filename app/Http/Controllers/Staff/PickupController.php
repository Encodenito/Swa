<?php

namespace App\Http\Controllers\Staff;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ReservationPresenter;
use App\Http\Requests\Staff\CompletePickupRequest;
use App\Models\Reservation;
use App\Services\ReservationService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PickupController extends Controller
{
    /**
     * Pickup counter: find reservations awaiting pickup by reference code, phone or email.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Reservation::class);

        $search = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? ''));

        $results = $search === '' ? [] : Reservation::query()
            ->with(['user:id,name,email,phone', 'lines.item:id,name,slug', 'payments'])
            ->whereIn('status', ReservationStatus::awaitingPickup())
            ->where(fn ($w) => $w
                ->where('reference_code', strtoupper($search))
                ->orWhereHas('user', fn ($u) => $u->where('email', $search)->orWhere('phone', $search)))
            ->orderBy('pickup_by')
            ->limit(20)
            ->get()
            ->map(fn (Reservation $r) => ReservationPresenter::detail($r, $request->user()));

        return Inertia::render('Staff/Pickup', [
            'q' => $search,
            'results' => $results,
        ]);
    }

    public function complete(CompletePickupRequest $request, Reservation $reservation, ReservationService $service): RedirectResponse
    {
        Gate::authorize('complete', $reservation);

        $payment = $service->complete($reservation, $request->validated('amount'), $request->user());

        return redirect()->route('staff.pickup')
            ->with('success', "{$reservation->reference_code} picked up. Balance of ".Money::format($payment->amount).' received.');
    }
}
