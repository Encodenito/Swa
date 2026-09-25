<?php

namespace App\Http\Controllers\Staff;

use App\Enums\ReservationStatus;
use App\Enums\ReservationType;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ReservationPresenter;
use App\Http\Requests\Staff\CancelReservationRequest;
use App\Http\Requests\Staff\ExtendDeadlineRequest;
use App\Models\Reservation;
use App\Services\ReservationService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ReservationController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Reservation::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ReservationStatus::class)],
            'type' => ['nullable', Rule::enum(ReservationType::class)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim($filters['q'] ?? '');

        $reservations = Reservation::query()
            ->with(['user:id,name,email,phone', 'lines:id,reservation_id,quantity'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('reference_code', 'like', like_pattern($search))
                ->orWhereHas('user', fn ($u) => $u->where('email', 'like', like_pattern($search))->orWhere('phone', 'like', like_pattern($search))->orWhere('name', 'like', like_pattern($search)))))
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Reservation $r) => ReservationPresenter::summary($r));

        return Inertia::render('Staff/Reservations/Index', [
            'reservations' => $reservations,
            'filters' => ['status' => $filters['status'] ?? null, 'type' => $filters['type'] ?? null, 'q' => $search],
            'statuses' => collect(ReservationStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
        ]);
    }

    public function show(Request $request, Reservation $reservation): Response
    {
        Gate::authorize('view', $reservation);

        return Inertia::render('Staff/Reservations/Show', [
            'reservation' => ReservationPresenter::detail($reservation, $request->user()),
            'can' => [
                'process' => $reservation->status === ReservationStatus::Confirmed,
                'extend' => in_array($reservation->status, ReservationStatus::awaitingPickup(), true),
                'complete' => in_array($reservation->status, ReservationStatus::awaitingPickup(), true),
            ],
        ]);
    }

    public function process(Reservation $reservation, ReservationService $service): RedirectResponse
    {
        Gate::authorize('process', $reservation);

        $service->startProcessing($reservation);

        return back(303)->with('success', 'Marked as being prepared. The customer can no longer cancel.');
    }

    public function extend(ExtendDeadlineRequest $request, Reservation $reservation, ReservationService $service): RedirectResponse
    {
        Gate::authorize('extendDeadline', $reservation);

        $service->extendDeadline($reservation, Carbon::parse($request->validated('pickup_by')), $request->validated('reason'), $request->user());

        return back(303)->with('success', 'Pickup deadline extended to '.$reservation->pickup_by->format('M j, Y').'.');
    }

    /**
     * Staff (0% refund) or admin (0, 50 or 100%) cancel with a required reason.
     */
    public function cancel(CancelReservationRequest $request, Reservation $reservation, ReservationService $service): RedirectResponse
    {
        Gate::authorize('cancel', $reservation);

        $refund = $service->cancel(
            $reservation,
            $request->user(),
            (int) $request->validated('refund_percent'),
            $request->validated('reason'),
            ReservationStatus::from($request->validated('expected_status')),
        );

        return back(303)->with('success', $refund
            ? 'Reservation cancelled. A refund of '.Money::format($refund->amount).' is queued for PayMongo.'
            : 'Reservation cancelled without a refund.');
    }
}
