<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\ReceiveDeliveryRequest;
use App\Http\Requests\Staff\StoreDeliveryRequest;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Item;
use App\Models\Supplier;
use App\Services\DeliveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Stock Receiving": supplier deliveries arriving at the shop.
 */
class DeliveryController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Delivery::class);

        $deliveries = Delivery::query()
            ->with('supplier:id,name')
            ->withCount('lines')
            ->latest()
            ->paginate(20)
            ->through(fn (Delivery $d) => [
                'id' => $d->id,
                'supplier' => $d->supplier->name,
                'reference' => $d->reference,
                'status' => $d->status->value,
                'expected_at' => $d->expected_at?->toDateString(),
                'received_at' => $d->received_at?->toIso8601String(),
                'lines_count' => $d->lines_count,
            ]);

        return Inertia::render('Staff/Deliveries/Index', ['deliveries' => $deliveries]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Delivery::class);

        return Inertia::render('Staff/Deliveries/Create', [
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'items' => Item::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'sku', 'price']),
            'canSetClearance' => request()->user()->isAdmin(),
        ]);
    }

    public function store(StoreDeliveryRequest $request, DeliveryService $service): RedirectResponse
    {
        Gate::authorize('create', Delivery::class);

        $data = $request->validated();

        $delivery = $service->create(
            (int) $data['supplier_id'],
            $data['reference'] ?? null,
            $data['expected_at'] ?? null,
            $data['lines'],
            $request->user(),
        );

        return redirect()->route('staff.deliveries.show', $delivery)->with('success', 'Delivery recorded. Receive it when it arrives.');
    }

    public function show(Delivery $delivery): Response
    {
        Gate::authorize('view', $delivery);

        $delivery->load(['supplier:id,name', 'lines.item:id,name,sku', 'receivedBy:id,name']);

        return Inertia::render('Staff/Deliveries/Show', [
            'delivery' => [
                'id' => $delivery->id,
                'supplier' => $delivery->supplier->name,
                'reference' => $delivery->reference,
                'status' => $delivery->status->value,
                'expected_at' => $delivery->expected_at?->toDateString(),
                'received_at' => $delivery->received_at?->toIso8601String(),
                'received_by' => $delivery->receivedBy?->name,
                'lines' => $delivery->lines->map(fn (DeliveryItem $line) => [
                    'id' => $line->id,
                    'item' => $line->item->name,
                    'sku' => $line->item->sku,
                    'quantity_ordered' => $line->quantity_ordered,
                    'quantity_received' => $line->quantity_received,
                    'unit_cost' => $line->unit_cost,
                    'sale_price' => $line->sale_price,
                ])->values(),
            ],
        ]);
    }

    public function receive(ReceiveDeliveryRequest $request, Delivery $delivery, DeliveryService $service): RedirectResponse
    {
        Gate::authorize('receive', $delivery);

        $service->receive($delivery, $request->receivedQuantities($delivery), $request->user());

        return back(303)->with('success', 'Delivery received. The stock is now available to customers.');
    }
}
