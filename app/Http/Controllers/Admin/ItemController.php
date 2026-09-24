<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ItemPresenter;
use App\Http\Requests\Admin\ItemRequest;
use App\Models\Category;
use App\Models\Item;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ItemController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('create', Item::class);

        $search = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? ''));

        $items = Item::query()
            ->withTrashed()
            ->withAvailable()
            ->with('category:id,name')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Item $item) => ItemPresenter::admin($item));

        return Inertia::render('Admin/Items/Index', ['items' => $items, 'q' => $search]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Item::class);

        return Inertia::render('Admin/Items/Form', $this->formProps());
    }

    public function store(ItemRequest $request): RedirectResponse
    {
        Gate::authorize('create', Item::class);

        $item = new Item($this->attributes($request));
        $item->slug = $this->uniqueSlug($item->name);
        $item->save();

        return redirect()->route('admin.items.index')->with('success', "{$item->name} added.");
    }

    public function edit(Item $item): Response
    {
        Gate::authorize('update', $item);

        return Inertia::render('Admin/Items/Form', $this->formProps($item));
    }

    public function update(ItemRequest $request, Item $item): RedirectResponse
    {
        Gate::authorize('update', $item);

        $oldImage = $item->image;
        $item->fill($this->attributes($request));
        $item->save();

        if ($request->hasFile('image') && $oldImage) {
            Storage::disk('public')->delete($oldImage);
        }

        return redirect()->route('admin.items.index')->with('success', "{$item->name} saved.");
    }

    /**
     * Soft delete only: reservations and stock history keep pointing at the item.
     */
    public function destroy(Item $item): RedirectResponse
    {
        Gate::authorize('delete', $item);

        $item->forceFill(['active' => false])->save();
        $item->delete();

        return redirect()->route('admin.items.index')->with('success', "{$item->name} removed from the catalog.");
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(ItemRequest $request): array
    {
        $data = $request->safe()->except('image');
        $data['active'] = $request->boolean('active');

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('items', 'public');
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function formProps(?Item $item = null): array
    {
        return [
            'item' => $item ? ItemPresenter::admin(Item::query()->withTrashed()->withAvailable()->findOrFail($item->id)) : null,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'replacementOptions' => Item::query()
                ->when($item, fn ($q) => $q->whereKeyNot($item->id))
                ->orderBy('name')
                ->get(['id', 'name']),
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;

        for ($i = 2; Item::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
