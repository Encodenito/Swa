<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Supplier::class);

        return Inertia::render('Admin/Suppliers', [
            'suppliers' => Supplier::query()->withCount('deliveries')->orderBy('name')->get(['id', 'name', 'phone', 'email']),
            'canEdit' => Gate::allows('create', Supplier::class),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Supplier::class);

        Supplier::create($this->validated($request));

        return back(303)->with('success', 'Supplier added.');
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        Gate::authorize('update', $supplier);

        $supplier->update($this->validated($request, $supplier));

        return back(303)->with('success', 'Supplier updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('suppliers', 'name')->ignore($supplier)],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);
    }
}
