<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Exceptions\StateConflictException;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Payments to review": payments that need a person (spec D).
 *  - needs_review: paid at PayMongo but not applied to a reservation (late, wrong amount, foreign session)
 *  - refund_failed: PayMongo refused a refund; a person retries
 *  - stuck: refund_pending for longer than STUCK_AFTER_MINUTES (e.g. the worker died mid-call)
 */
class PaymentReviewService
{
    public const STUCK_AFTER_MINUTES = 15;

    public function __construct(
        private readonly RefundService $refunds,
        private readonly AuditLogger $audit,
    ) {}

    public function query(): Builder
    {
        return Payment::query()
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $r) => $r
                    ->where('status', PaymentStatus::NeedsReview)
                    ->whereNull('reviewed_at'))
                ->orWhere(fn (Builder $r) => $r
                    ->where('type', PaymentType::Refund)
                    ->where('status', PaymentStatus::RefundFailed))
                ->orWhere(fn (Builder $r) => $r
                    ->where('type', PaymentType::Refund)
                    ->where('status', PaymentStatus::RefundPending)
                    ->where('updated_at', '<', now()->subMinutes(self::STUCK_AFTER_MINUTES))));
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    /**
     * Refund a payment that could not be applied, in full, and close it on the review list.
     *
     * @throws StateConflictException
     */
    public function refundInFull(Payment $payment, string $reason, User $by): Payment
    {
        return DB::transaction(function () use ($payment, $reason, $by) {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PaymentStatus::NeedsReview || $locked->reviewed_at !== null || $locked->method !== PaymentMethod::PayMongo) {
                throw new StateConflictException('This payment has already been reviewed.');
            }

            $refund = $this->refunds->queue($locked, $this->refunds->refundable($locked), "Review refund: {$reason}", $by);

            if (! $refund) {
                throw new StateConflictException('Nothing is left to refund on this payment.');
            }

            $locked->forceFill(['reviewed_at' => now(), 'reviewed_by' => $by->id])->save();
            $this->audit->record('payment.reviewed', $locked, ['status' => 'needs_review'], ['outcome' => 'refunded', 'refund_id' => $refund->id, 'reason' => $reason], $by);

            return $refund;
        }, 3);
    }

    /**
     * Admin decision to close a needs_review payment without refunding (resolved another way).
     *
     * @throws StateConflictException
     */
    public function markReviewed(Payment $payment, string $reason, User $admin): void
    {
        $changed = Payment::query()
            ->whereKey($payment->id)
            ->where('status', PaymentStatus::NeedsReview)
            ->whereNull('reviewed_at')
            ->update(['reviewed_at' => now(), 'reviewed_by' => $admin->id, 'updated_at' => now()]);

        if ($changed !== 1) {
            throw new StateConflictException('This payment has already been reviewed.');
        }

        $this->audit->record('payment.reviewed', $payment, ['status' => 'needs_review'], ['outcome' => 'kept, no refund', 'reason' => $reason], $admin);
    }
}
