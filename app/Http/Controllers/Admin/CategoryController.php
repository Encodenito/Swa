<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryRequest;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Category::class);

        return Inertia::render('Admin/Categories', [
            'categories' => Category::query()->withCount('items')->orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        Gate::authorize('create', Category::class);

        Category::create($request->safe()->only(['name', 'slug']));

        return back(303)->with('success', 'Category added.');
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        Gate::authorize('update', $category);

        $category->update($request->safe()->only(['name', 'slug']));

        return back(303)->with('success', 'Category renamed.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        Gate::authorize('delete', $category);

        if ($category->items()->withTrashed()->exists()) {
            return back(303)->with('error', 'This category still has items. Move them to another category first.');
        }

        $category->delete();

        return back(303)->with('success', 'Category removed.');
    }
}
