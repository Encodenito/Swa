<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Role;
use App\Enums\SettingKey;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ItemPresenter;
use App\Http\Requests\Staff\StoreCustomerReservationRequest;
use App\Models\Item;
use App\Models\Reservation;
use App\Models\User;
use App\Services\PayMongo\PayMongoException;
use App\Services\ReservationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff reserve on behalf of a customer who has an account (spec B). The customer still pays the
 * downpayment on PayMongo: staff hand over the checkout link, or the customer pays from their account.
 */
class CustomerReservationController extends Controller
{
    public function create(Request $request): Response
    {
        Gate::authorize('createForCustomer', Reservation::class);

        $search = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? ''));

        $customers = $search === '' ? [] : User::query()
            ->where('role', Role::Customer)
            ->whereNull('deactivated_at')
            ->whereNotNull('email_verified_at')
            ->where(fn ($q) => $q->where('email', 'like', like_pattern($search))->orWhere('phone', 'like', like_pattern($search))->orWhere('name', 'like', like_pattern($search)))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'email', 'phone']);

        return Inertia::render('Staff/CustomerReservations/Create', [
            'q' => $search,
            'customers' => $customers,
            'items' => Item::query()->listed()->withAvailable()->orderBy('name')->get()
                ->map(fn (Item $item) => ItemPresenter::card($item) + ['sku' => $item->sku])
                ->filter(fn (array $item) => $item['available'] > 0)
                ->values(),
            'terms' => [
                'pickupDays' => setting(SettingKey::PickupDays),
                'forfeitRefundPercent' => setting(SettingKey::ForfeitRefundPercent),
                'downpaymentPercent' => setting(SettingKey::DownpaymentPercent),
                'pendingMinutes' => setting(SettingKey::PendingMinutes),
            ],
            'maxQuantity' => setting(SettingKey::MaxQtyPerItem),
        ]);
    }

    public function store(StoreCustomerReservationRequest $request, ReservationService $service): RedirectResponse
    {
        Gate::authorize('createForCustomer', Reservation::class);

        $customer = User::query()->findOrFail($request->integer('customer_id'));
        $reservation = $service->create($customer, $request->quantities(), true, $request->user());

        try {
            $service->startCheckout($reservation);
        } catch (PayMongoException) {
            return back(303)->with('error', 'The payment page could not be created, so the reservation was cancelled. Please try again.');
        }

        return redirect()->route('staff.reservations.show', $reservation)
            ->with('success', "Reservation {$reservation->reference_code} created for {$customer->name}. Give them the payment link below.");
    }
}
