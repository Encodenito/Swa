<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BatchPriceRequest;
use App\Models\StockBatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Clearance price on one stock batch. Units already reserved keep the price they were reserved at.
 */
class BatchPriceController extends Controller
{
    public function update(BatchPriceRequest $request, StockBatch $batch): RedirectResponse
    {
        Gate::authorize('updatePrice', $batch);

        $batch->update(['sale_price' => $request->validated('sale_price')]);

        return back(303)->with('success', $batch->sale_price ? 'Clearance price set.' : 'Clearance price removed.');
    }
}
