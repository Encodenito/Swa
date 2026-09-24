<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BatchPriceRequest;
use App\Models\StockBatch;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Clearance price on one stock batch. Units already reserved keep the price they were reserved at.
 */
class BatchPriceController extends Controller
{
    public function update(BatchPriceRequest $request, StockBatch $batch, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('updatePrice', $batch);

        DB::transaction(function () use ($request, $batch, $audit) {
            $before = $batch->sale_price;
            $batch->update(['sale_price' => $request->validated('sale_price')]);
            $audit->record('batch.clearance_changed', $batch, ['sale_price' => $before], ['sale_price' => $batch->sale_price]);
        });

        return back(303)->with('success', $batch->sale_price ? 'Clearance price set.' : 'Clearance price removed.');
    }
}
