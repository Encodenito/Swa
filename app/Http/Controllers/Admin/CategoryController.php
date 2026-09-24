<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Category::class);

        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'unique:categories,name']]);

        Category::create(['name' => $data['name'], 'slug' => Str::slug($data['name'])]);

        return back(303)->with('success', 'Category added.');
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        Gate::authorize('update', $category);

        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('categories', 'name')->ignore($category)]]);

        $category->update(['name' => $data['name'], 'slug' => Str::slug($data['name'])]);

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
