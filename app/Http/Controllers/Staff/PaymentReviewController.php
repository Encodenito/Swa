<?php

namespace App\Http\Controllers\Staff;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ReservationPresenter;
use App\Http\Requests\Admin\ReconcileRefundRequest;
use App\Http\Requests\Staff\ReasonRequest;
use App\Models\OrphanPayment;
use App\Models\Payment;
use App\Services\OrphanPaymentService;
use App\Services\PaymentReviewService;
use App\Services\RefundService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PaymentReviewController extends Controller
{
    public function index(Request $request, PaymentReviewService $reviews, OrphanPaymentService $orphans): Response
    {
        Gate::authorize('review', Payment::class);

        $stuckBefore = now()->subMinutes(PaymentReviewService::STUCK_AFTER_MINUTES);

        $payments = $reviews->query()
            ->with(['reservation:id,reference_code,status,user_id', 'reservation.user:id,name,email,phone', 'parent:id,provider_ref,amount'])
            ->oldest('updated_at')
            ->paginate(30)
            ->through(fn (Payment $payment) => ReservationPresenter::payment($payment, true) + [
                'kind' => match (true) {
                    $payment->status === PaymentStatus::NeedsReview => 'needs_review',
                    $payment->status === PaymentStatus::RefundFailed => 'refund_failed',
                    default => 'stuck',
                },
                'stuck_since' => $payment->status === PaymentStatus::RefundPending && $payment->updated_at < $stuckBefore
                    ? $payment->updated_at->toIso8601String() : null,
                'reservation' => [
                    'reference_code' => $payment->reservation->reference_code,
                    'status' => $payment->reservation->status->value,
                    'status_label' => $payment->reservation->status->label(),
                    'customer' => $payment->reservation->user?->only(['name', 'email', 'phone']),
                ],
                'original' => $payment->parent ? ['id' => $payment->parent->id, 'provider_ref' => $payment->parent->provider_ref] : null,
            ]);

        return Inertia::render('Staff/Payments/Review', [
            'payments' => $payments,
            'canMarkReviewed' => $request->user()->isAdmin(),
            'canReconcile' => $request->user()->isAdmin(),
            // Payments PayMongo captured for a reference that matches no reservation (admin decides).
            'orphans' => $request->user()->can('viewAny', OrphanPayment::class) ? $orphans->reviewQuery()
                ->limit(50)
                ->get()
                ->map(fn (OrphanPayment $o) => [
                    'id' => $o->id,
                    'provider_ref' => $o->provider_ref,
                    'reference_number' => $o->reference_number,
                    'amount' => $o->amount,
                    'received_at' => $o->received_at?->toIso8601String(),
                    'refund_status' => $o->refund_status,
                    'kind' => match (true) {
                        $o->refund_status === OrphanPaymentService::REFUND_FAILED => 'refund_failed',
                        $o->refund_status === OrphanPaymentService::REFUND_PENDING => 'stuck',
                        default => 'unmatched',
                    },
                    'note' => $o->note,
                ]) : [],
        ]);
    }

    public function refund(ReasonRequest $request, Payment $payment, PaymentReviewService $reviews): RedirectResponse
    {
        Gate::authorize('refund', $payment);

        $refund = $reviews->refundInFull($payment, $request->validated('reason'), $request->user());

        return back(303)->with('success', 'Refund of '.Money::format($refund->amount).' queued. This page shows the result once PayMongo answers.');
    }

    public function retry(Request $request, Payment $payment, RefundService $refunds): RedirectResponse
    {
        Gate::authorize('retry', $payment);

        $refunds->retry($payment, $request->user());

        return back(303)->with('success', 'Refund queued again. Check back in a minute for the result.');
    }

    public function markReviewed(ReasonRequest $request, Payment $payment, PaymentReviewService $reviews): RedirectResponse
    {
        Gate::authorize('markReviewed', $payment);

        $reviews->markReviewed($payment, $request->validated('reason'), $request->user());

        return back(303)->with('success', 'Marked as reviewed without a refund.');
    }

    public function reconcile(ReconcileRefundRequest $request, Payment $payment, RefundService $refunds): RedirectResponse
    {
        Gate::authorize('reconcile', $payment);

        $found = $request->validated('outcome') === 'found';
        $refunds->reconcile($payment, $found ? $request->validated('paymongo_refund_id') : null, $request->validated('reason'), $request->user());

        return back(303)->with('success', $found
            ? 'Refund recorded as completed at PayMongo.'
            : 'Marked as failed. You can now retry the refund.');
    }
}
