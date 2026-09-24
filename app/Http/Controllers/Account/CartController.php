<?php

namespace App\Http\Controllers\Account;

use App\Enums\SettingKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateCartRequest;
use App\Models\Item;
use App\Models\Reservation;
use App\Services\CartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CartController extends Controller
{
    public function show(CartService $cart): Response
    {
        return Inertia::render('Account/Cart', [
            'cart' => $cart->preview(),
            'terms' => [
                'pickupDays' => setting(SettingKey::PickupDays),
                'forfeitRefundPercent' => setting(SettingKey::ForfeitRefundPercent),
                'downpaymentPercent' => setting(SettingKey::DownpaymentPercent),
                'pendingMinutes' => setting(SettingKey::PendingMinutes),
            ],
            'maxQuantity' => setting(SettingKey::MaxQtyPerItem),
            'canReserve' => Gate::allows('create', Reservation::class),
        ]);
    }

    public function add(UpdateCartRequest $request, CartService $cart): RedirectResponse
    {
        $item = Item::query()->where('active', true)->findOrFail($request->integer('item_id'));
        $cart->add($item, $request->integer('quantity'));

        return redirect()->route('account.cart')->with('success', "{$item->name} added to your cart.");
    }

    public function update(UpdateCartRequest $request, CartService $cart): RedirectResponse
    {
        $item = Item::query()->findOrFail($request->integer('item_id'));
        $cart->set($item, $request->integer('quantity'));

        return back(303);
    }
}
