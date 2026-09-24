<?php

namespace App\Http\Controllers;

use App\Enums\SettingKey;
use App\Http\Presenters\ItemPresenter;
use App\Models\Category;
use App\Models\Item;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:120'],
        ]);

        $search = trim($validated['q'] ?? '');
        $category = $validated['category'] ?? null;

        $items = Item::query()
            ->listed()
            ->withAvailable()
            ->with('category:id,name,slug')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', like_pattern($search))
                ->orWhere('brand', 'like', like_pattern($search))
                ->orWhere('model', 'like', like_pattern($search))))
            ->when($category, fn ($q) => $q->whereHas('category', fn ($c) => $c->where('slug', $category)))
            ->orderBy('name')
            ->paginate(24)
            ->withQueryString()
            ->through(fn (Item $item) => ItemPresenter::card($item));

        return Inertia::render('Catalog/Index', [
            'items' => $items,
            'categories' => Category::query()->orderBy('name')->get(['name', 'slug']),
            'filters' => ['q' => $search, 'category' => $category],
        ]);
    }

    public function show(Item $item): Response|RedirectResponse
    {
        $item = Item::query()->withAvailable()->with('category:id,name,slug', 'supersededBy')->findOrFail($item->id);

        // A replaced model that has sold out sends shoppers to its successor.
        if ($item->superseded_by_id && $item->availableQuantity() === 0 && $item->supersededBy?->active) {
            return redirect()->route('items.show', $item->supersededBy, 301);
        }

        Gate::authorize('view', $item);

        return Inertia::render('Catalog/Show', [
            'item' => ItemPresenter::detail($item),
            'maxQuantity' => setting(SettingKey::MaxQtyPerItem),
        ]);
    }
}
