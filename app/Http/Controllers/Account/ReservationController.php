<?php

namespace App\Http\Controllers\Account;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ReservationPresenter;
use App\Http\Requests\Account\StoreReservationRequest;
use App\Models\Reservation;
use App\Services\CartService;
use App\Services\PayMongo\PayMongoException;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ReservationController extends Controller
{
    public function index(Request $request): Response
    {
        $reservations = $request->user()->reservations()
            ->with('lines')
            ->latest()
            ->paginate(15)
            ->through(fn (Reservation $r) => ReservationPresenter::summary($r));

        return Inertia::render('Account/Reservations/Index', [
            'reservations' => $reservations,
        ]);
    }

    /**
     * Creates the reservation (stock held, totals computed server-side) and sends the customer to
     * PayMongo's hosted checkout. Nothing here confirms payment; only the webhook does.
     */
    public function store(StoreReservationRequest $request, ReservationService $service, CartService $cart): SymfonyResponse
    {
        Gate::authorize('create', Reservation::class);

        $reservation = $service->create($request->user(), $cart->quantities(), $request->boolean('terms'));

        try {
            $checkoutUrl = $service->startCheckout($reservation);
        } catch (PayMongoException) {
            // The cart is kept so the customer can simply try again.
            return redirect()->route('account.cart')
                ->with('error', 'We could not open the payment page, so your reservation was cancelled and nothing was charged. Please try again.');
        }

        $cart->clear();

        return Inertia::location($checkoutUrl);
    }

    public function show(Request $request, Reservation $reservation): Response
    {
        Gate::authorize('view', $reservation);

        return Inertia::render('Account/Reservations/Show', [
            'reservation' => ReservationPresenter::detail($reservation, $request->user()),
        ]);
    }

    /**
     * Resume payment for a reservation that is still within its payment window.
     */
    public function pay(Reservation $reservation): SymfonyResponse
    {
        Gate::authorize('view', $reservation);

        if ($reservation->status !== ReservationStatus::PendingPayment || ! $reservation->checkout_url || $reservation->expires_at->isPast()) {
            return redirect()->route('account.reservations.show', $reservation)->with('error', 'This reservation can no longer be paid.');
        }

        return Inertia::location($reservation->checkout_url);
    }

    /**
     * PayMongo's success_url. Shows "Confirming your payment..." and polls; it never confirms anything.
     */
    public function return(Request $request, Reservation $reservation): Response
    {
        Gate::authorize('view', $reservation);

        return Inertia::render('Account/Reservations/Return', [
            'reservation' => ReservationPresenter::summary($reservation),
        ]);
    }

    public function status(Reservation $reservation): JsonResponse
    {
        Gate::authorize('view', $reservation);

        return response()->json([
            'status' => $reservation->status->value,
            'status_label' => $reservation->status->label(),
            'pickup_by' => $reservation->pickup_by?->toIso8601String(),
        ]);
    }
}
