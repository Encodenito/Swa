<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Exceptions\StateConflictException;
use App\Jobs\SendRefund;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Refund rows follow spec H: created as refund_pending inside the state-change transaction,
 * sent to PayMongo by SendRefund after commit, then refunded or refund_failed. Never retried
 * automatically; retry() is a person pressing a button.
 */
class RefundService
{
    /** PayMongo rejects refunds below 100 centavos. */
    public const MINIMUM = '1.00';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Record a refund of $amount against $original. Must run inside the caller's transaction.
     * Returns null when there is nothing refundable (amount below ₱1.00 after capping).
     */
    public function queue(Payment $original, string $amount, string $reason, ?User $by = null): ?Payment
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Queue refunds inside the transaction that changes the reservation.');
        }

        if ($original->type === PaymentType::Refund) {
            throw new InvalidArgumentException('A refund cannot be refunded.');
        }

        // Serialise refunds against the same payment so two of them cannot both see the full remainder.
        Payment::query()->whereKey($original->id)->lockForUpdate()->first();

        $amount = Money::of($amount);
        $remaining = $this->refundable($original);

        if (Money::compare($amount, $remaining) > 0) {
            $amount = $remaining;
        }

        if (Money::compare($amount, self::MINIMUM) < 0) {
            return null;
        }

        $refund = new Payment;
        $refund->forceFill([
            'reservation_id' => $original->reservation_id,
            'parent_id' => $original->id,
            'type' => PaymentType::Refund,
            'amount' => $amount,
            'method' => $original->method,
            // Cash goes back over the counter at once; PayMongo refunds are sent after commit.
            'status' => $original->method === PaymentMethod::Cash ? PaymentStatus::Refunded : PaymentStatus::RefundPending,
            'note' => mb_substr($reason, 0, 255),
            'created_by' => $by?->id,
        ])->save();

        $this->audit->record('refund.created', $refund, null, [
            'amount' => $refund->amount,
            'parent_payment' => $original->id,
            'method' => $refund->method->value,
            'reason' => $reason,
        ], $by);

        if ($refund->status === PaymentStatus::RefundPending) {
            SendRefund::dispatch($refund->id)->afterCommit();
        }

        return $refund;
    }

    /**
     * What is still refundable on a payment: its amount minus refunds that are not failed.
     */
    public function refundable(Payment $original): string
    {
        $refunded = Payment::query()
            ->where('parent_id', $original->id)
            ->where('type', PaymentType::Refund)
            ->where('status', '!=', PaymentStatus::RefundFailed)
            ->pluck('amount')
            ->all();

        $left = Money::sub($original->amount, Money::add(...$refunded));

        return Money::compare($left, '0') < 0 ? Money::zero() : $left;
    }

    /**
     * Manual retry of a failed refund. The conditional UPDATE is the claim: of two clicks,
     * exactly one moves the row back to refund_pending and dispatches the call.
     *
     * @throws StateConflictException
     */
    public function retry(Payment $refund, User $by): void
    {
        $claimed = Payment::query()
            ->whereKey($refund->id)
            ->where('type', PaymentType::Refund)
            ->where('status', PaymentStatus::RefundFailed)
            ->update(['status' => PaymentStatus::RefundPending, 'updated_at' => now()]);

        if ($claimed !== 1) {
            throw new StateConflictException('This refund is not in a failed state any more, so it was not retried.');
        }

        $this->audit->record('refund.retried', $refund, ['status' => 'refund_failed', 'note' => $refund->note], ['status' => 'refund_pending'], $by);

        SendRefund::dispatch($refund->id);
    }
}
