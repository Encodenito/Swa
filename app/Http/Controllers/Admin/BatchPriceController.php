<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StockBatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Clearance price on one stock batch. Units already reserved keep the price they were reserved at.
 */
class BatchPriceController extends Controller
{
    public function update(Request $request, StockBatch $batch): RedirectResponse
    {
        Gate::authorize('updatePrice', $batch);

        $data = $request->validate([
            'sale_price' => ['nullable', 'regex:/^\d{1,8}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
        ]);

        $batch->update(['sale_price' => $data['sale_price'] ?? null]);

        return back(303)->with('success', $batch->sale_price ? 'Clearance price set.' : 'Clearance price removed.');
    }
}
