<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReconcileRefundRequest;
use App\Http\Requests\Staff\ReasonRequest;
use App\Models\OrphanPayment;
use App\Services\OrphanPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Admin decisions on payments that matched no reservation. Listed on Payments to review.
 */
class OrphanPaymentController extends Controller
{
    public function refund(ReasonRequest $request, OrphanPayment $orphan, OrphanPaymentService $service): RedirectResponse
    {
        Gate::authorize('handle', $orphan);

        $service->refund($orphan, $request->validated('reason'), $request->user());

        return back(303)->with('success', 'Refund sent to PayMongo.');
    }

    public function retry(OrphanPayment $orphan, OrphanPaymentService $service): RedirectResponse
    {
        Gate::authorize('handle', $orphan);

        $service->retry($orphan, request()->user());

        return back(303)->with('success', 'Refund sent to PayMongo again.');
    }

    public function reconcile(ReconcileRefundRequest $request, OrphanPayment $orphan, OrphanPaymentService $service): RedirectResponse
    {
        Gate::authorize('handle', $orphan);

        $found = $request->validated('outcome') === 'found';
        $service->reconcile($orphan, $found ? $request->validated('paymongo_refund_id') : null, $request->validated('reason'), $request->user());

        return back(303)->with('success', $found ? 'Refund recorded as completed.' : 'Marked as failed. You can now retry.');
    }

    public function markReviewed(ReasonRequest $request, OrphanPayment $orphan, OrphanPaymentService $service): RedirectResponse
    {
        Gate::authorize('handle', $orphan);

        $service->markReviewed($orphan, $request->validated('reason'), $request->user());

        return back(303)->with('success', 'Marked as reviewed without a refund.');
    }
}
