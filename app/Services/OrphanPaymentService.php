<?php

namespace App\Services;

use App\Exceptions\StateConflictException;
use App\Jobs\SendOrphanRefund;
use App\Models\OrphanPayment;
use App\Models\User;
use App\Services\PayMongo\PaidCheckoutEvent;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * PayMongo payments whose reference matches no reservation. Stored so a person can refund them.
 * Refunds follow the same rules as reservation refunds: one attempt, claimed before the call,
 * never retried automatically, unknown outcomes reconciled by an admin (spec K.7/K.8).
 */
class OrphanPaymentService
{
    public const REFUND_PENDING = 'refund_pending';

    public const REFUNDED = 'refunded';

    public const REFUND_FAILED = 'refund_failed';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Idempotent on provider_ref: the same webhook delivered twice stores one row.
     */
    public function record(PaidCheckoutEvent $event): void
    {
        if (OrphanPayment::query()->where('provider_ref', $event->paymentId)->exists()) {
            return;
        }

        try {
            $orphan = new OrphanPayment;
            $orphan->forceFill([
                'provider_ref' => $event->paymentId,
                'reference_number' => mb_substr($event->referenceNumber, 0, 100),
                'checkout_id' => $event->checkoutId,
                'amount' => Money::fromCentavos($event->amountCentavos),
                'received_at' => now(),
            ])->save();
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery stored it first.
        }
    }

    /**
     * Queue a full refund. The row is claimed with a conditional update so two clicks send one refund.
     *
     * @throws StateConflictException
     */
    public function refund(OrphanPayment $orphan, string $reason, User $admin): void
    {
        DB::transaction(function () use ($orphan, $reason, $admin) {
            $claimed = OrphanPayment::query()
                ->whereKey($orphan->id)
                ->whereNull('refund_status')
                ->whereNull('reviewed_at')
                ->update([
                    'refund_status' => self::REFUND_PENDING,
                    'refund_attempted_at' => null,
                    'reviewed_at' => now(),
                    'reviewed_by' => $admin->id,
                    'note' => mb_substr("Refund: {$reason}", 0, 255),
                    'updated_at' => now(),
                ]);

            if ($claimed !== 1) {
                throw new StateConflictException('This payment has already been handled.');
            }

            $this->audit->record('orphan_payment.refund_requested', $orphan, null, ['amount' => $orphan->amount, 'reason' => $reason], $admin);
            SendOrphanRefund::dispatch($orphan->id)->afterCommit();
        });
    }

    /**
     * Retry after a definite PayMongo refusal only (refund_failed). Claimed atomically.
     *
     * @throws StateConflictException
     */
    public function retry(OrphanPayment $orphan, User $admin): void
    {
        $claimed = OrphanPayment::query()
            ->whereKey($orphan->id)
            ->where('refund_status', self::REFUND_FAILED)
            ->update(['refund_status' => self::REFUND_PENDING, 'refund_attempted_at' => null, 'updated_at' => now()]);

        if ($claimed !== 1) {
            throw new StateConflictException('This refund is not in a failed state, so it was not retried.');
        }

        $this->audit->record('orphan_payment.refund_retried', $orphan, ['refund_status' => self::REFUND_FAILED], ['refund_status' => self::REFUND_PENDING], $admin);
        SendOrphanRefund::dispatch($orphan->id);
    }

    /**
     * Admin record of what the PayMongo dashboard shows for a refund whose outcome is unknown.
     *
     * @throws StateConflictException
     */
    public function reconcile(OrphanPayment $orphan, ?string $payMongoRefundId, string $reason, User $admin): void
    {
        DB::transaction(function () use ($orphan, $payMongoRefundId, $reason, $admin) {
            $locked = OrphanPayment::query()->whereKey($orphan->id)->lockForUpdate()->firstOrFail();

            $stuck = $locked->refund_status === self::REFUND_PENDING
                && $locked->updated_at < now()->subMinutes(PaymentReviewService::STUCK_AFTER_MINUTES);

            if (! $stuck) {
                throw new StateConflictException('Only a refund waiting for more than '.PaymentReviewService::STUCK_AFTER_MINUTES.' minutes can be reconciled.');
            }

            $locked->forceFill($payMongoRefundId !== null
                ? ['refund_status' => self::REFUNDED, 'refund_ref' => $payMongoRefundId]
                : ['refund_status' => self::REFUND_FAILED, 'note' => mb_substr('Not found at PayMongo (reconciled). '.$locked->note, 0, 255)])
                ->save();

            $this->audit->record('orphan_payment.reconciled', $locked, ['refund_status' => self::REFUND_PENDING], [
                'refund_status' => $locked->refund_status,
                'refund_ref' => $payMongoRefundId,
                'reason' => $reason,
            ], $admin);
        });
    }

    /**
     * Close without refunding (resolved another way). Admin only.
     *
     * @throws StateConflictException
     */
    public function markReviewed(OrphanPayment $orphan, string $reason, User $admin): void
    {
        $changed = OrphanPayment::query()
            ->whereKey($orphan->id)
            ->whereNull('refund_status')
            ->whereNull('reviewed_at')
            ->update(['reviewed_at' => now(), 'reviewed_by' => $admin->id, 'note' => mb_substr("Kept: {$reason}", 0, 255), 'updated_at' => now()]);

        if ($changed !== 1) {
            throw new StateConflictException('This payment has already been handled.');
        }

        $this->audit->record('orphan_payment.reviewed', $orphan, null, ['outcome' => 'kept, no refund', 'reason' => $reason], $admin);
    }
}
