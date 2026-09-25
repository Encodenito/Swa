<?php

namespace App\Jobs;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\AuditLogger;
use App\Services\PayMongo\PayMongoException;
use App\Services\PayMongo\PayMongoService;
use App\Support\Money;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one refund to PayMongo, at most once per claim (spec K.7, K.8).
 *
 *  - The row is claimed with a conditional UPDATE (refund_attempted_at) before the HTTP call, so a
 *    second run of this job (queue:retry, a redelivered job) cannot send the same refund again.
 *  - A definite refusal from PayMongo (4xx with an error code) becomes refund_failed; a person may
 *    retry it from "Payments to review".
 *  - An unknown outcome (timeout, connection error, 5xx) stays refund_pending with a note: the
 *    refund may exist at PayMongo, so it is never resent blindly. It shows as "stuck" until an
 *    admin reconciles it against the PayMongo dashboard.
 */
class SendRefund implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $refundId) {}

    public function handle(PayMongoService $payMongo, AuditLogger $audit): void
    {
        $claimed = Payment::query()
            ->whereKey($this->refundId)
            ->where('status', PaymentStatus::RefundPending)
            ->whereNull('refund_attempted_at')
            ->update(['refund_attempted_at' => now(), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $refund = Payment::query()->with('parent', 'reservation')->findOrFail($this->refundId);
        $notes = "Refund for reservation {$refund->reservation->reference_code}. {$refund->note}";

        try {
            $refundRef = $payMongo->createRefund((string) $refund->parent->provider_ref, Money::toCentavos($refund->amount), $notes, (string) $refund->id);
        } catch (PayMongoException $e) {
            $e->definite ? $this->markFailed($refund, $e->errorCode, $audit) : $this->markUnknown($refund, $e->errorCode, $audit);

            return;
        } catch (Throwable $e) {
            $this->markUnknown($refund, class_basename($e), $audit);

            return;
        }

        $refund->forceFill(['status' => PaymentStatus::Refunded, 'provider_ref' => $refundRef])->save();
        $audit->record('refund.sent', $refund, ['status' => 'refund_pending'], ['status' => 'refunded', 'provider_ref' => $refundRef]);
    }

    private function markFailed(Payment $refund, string $code, AuditLogger $audit): void
    {
        $refund->forceFill([
            'status' => PaymentStatus::RefundFailed,
            'note' => mb_substr("PayMongo: {$code}. ".$refund->note, 0, 255),
        ])->save();

        $audit->record('refund.failed', $refund, ['status' => 'refund_pending'], ['status' => 'refund_failed', 'error' => $code]);
        Log::warning('Refund refused by PayMongo; waiting for a person to retry.', ['refund' => $refund->id, 'code' => $code]);
    }

    private function markUnknown(Payment $refund, string $code, AuditLogger $audit): void
    {
        $refund->forceFill([
            'note' => mb_substr("Outcome unknown ({$code}): check PayMongo before acting. ".$refund->note, 0, 255),
        ])->save();

        $audit->record('refund.outcome_unknown', $refund, null, ['status' => 'refund_pending', 'error' => $code]);
        Log::error('Refund outcome unknown; not resending. Reconcile against the PayMongo dashboard.', ['refund' => $refund->id, 'code' => $code]);
    }
}
