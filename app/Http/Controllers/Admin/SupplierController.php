<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SupplierRequest;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
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

    public function store(SupplierRequest $request): RedirectResponse
    {
        Gate::authorize('create', Supplier::class);

        Supplier::create($request->validated());

        return back(303)->with('success', 'Supplier added.');
    }

    public function update(SupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        Gate::authorize('update', $supplier);

        $supplier->update($request->validated());

        return back(303)->with('success', 'Supplier updated.');
    }
}
