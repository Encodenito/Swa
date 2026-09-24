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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one refund to PayMongo. Exactly one attempt: a failure becomes refund_failed and waits
 * for a person (spec K.8). No database transaction is open during the HTTP call (K.7).
 */
class SendRefund implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $refundId) {}

    public function handle(PayMongoService $payMongo, AuditLogger $audit): void
    {
        // Guards against the same refund being sent twice by overlapping workers.
        $lock = Cache::lock("refund:{$this->refundId}", 120);

        if (! $lock->get()) {
            return;
        }

        try {
            $refund = Payment::query()->with('parent', 'reservation')->find($this->refundId);

            if (! $refund || $refund->status !== PaymentStatus::RefundPending) {
                return;
            }

            $original = $refund->parent;
            $notes = "Refund for reservation {$refund->reservation->reference_code}. {$refund->note}";

            try {
                $refundRef = $payMongo->createRefund((string) $original->provider_ref, Money::toCentavos($refund->amount), $notes);
            } catch (PayMongoException $e) {
                $this->markFailed($refund, $e->errorCode, $audit);

                return;
            } catch (Throwable $e) {
                $this->markFailed($refund, class_basename($e), $audit);
                Log::error('Refund call crashed.', ['refund' => $refund->id, 'error' => $e->getMessage()]);

                return;
            }

            $refund->forceFill(['status' => PaymentStatus::Refunded, 'provider_ref' => $refundRef])->save();
            $audit->record('refund.sent', $refund, ['status' => 'refund_pending'], ['status' => 'refunded', 'provider_ref' => $refundRef]);
        } finally {
            $lock->release();
        }
    }

    private function markFailed(Payment $refund, string $code, AuditLogger $audit): void
    {
        $refund->forceFill([
            'status' => PaymentStatus::RefundFailed,
            'note' => mb_substr("PayMongo: {$code}. ".$refund->note, 0, 255),
        ])->save();

        $audit->record('refund.failed', $refund, ['status' => 'refund_pending'], ['status' => 'refund_failed', 'error' => $code]);
        Log::warning('Refund failed; waiting for a person to retry.', ['refund' => $refund->id, 'code' => $code]);
    }
}
