<?php

namespace App\Http\Controllers\Staff;

use App\Enums\MovementType;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ItemPresenter;
use App\Http\Requests\Staff\AdjustStockRequest;
use App\Models\Item;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Services\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class StockController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewStock', Item::class);

        $search = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? ''));

        $items = Item::query()
            ->withAvailable()
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Item $item) => ItemPresenter::card($item) + ['sku' => $item->sku, 'active' => $item->active]);

        return Inertia::render('Staff/Stock/Index', ['items' => $items, 'q' => $search]);
    }

    public function show(Request $request, Item $item): Response
    {
        Gate::authorize('viewStock', Item::class);

        return Inertia::render('Staff/Stock/Show', [
            'item' => ItemPresenter::card(Item::query()->withAvailable()->findOrFail($item->id)),
            'batches' => $item->batches()->orderBy('received_at')->orderBy('id')->get()->map(fn (StockBatch $b) => [
                'id' => $b->id,
                'quantity_remaining' => $b->quantity_remaining,
                'sale_price' => $b->sale_price,
                'received_at' => $b->received_at->toIso8601String(),
            ]),
            'movements' => $item->movements()->with('creator:id,name', 'reservation:id,reference_code')->latest('id')->limit(50)->get()
                ->map(fn (StockMovement $m) => [
                    'id' => $m->id,
                    'batch_id' => $m->batch_id,
                    'type' => $m->type->value,
                    'quantity' => $m->quantity,
                    'reason' => $m->reason,
                    'reference_code' => $m->reservation?->reference_code,
                    'by' => $m->creator?->name,
                    'created_at' => $m->created_at?->toIso8601String(),
                ]),
            'canSetClearance' => $request->user()->isAdmin(),
        ]);
    }

    public function adjust(AdjustStockRequest $request, StockBatch $batch, StockService $stock): RedirectResponse
    {
        Gate::authorize('adjust', $batch);

        $type = MovementType::from($request->validated('type'));
        $quantity = (int) $request->validated('quantity');

        $stock->adjust($batch, $type === MovementType::Damaged ? -abs($quantity) : $quantity, $type, $request->validated('reason'), $request->user());

        return back(303)->with('success', 'Stock updated.');
    }
}
