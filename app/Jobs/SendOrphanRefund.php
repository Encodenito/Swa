<?php

namespace App\Jobs;

use App\Models\OrphanPayment;
use App\Services\AuditLogger;
use App\Services\OrphanPaymentService;
use App\Services\PayMongo\PayMongoException;
use App\Services\PayMongo\PayMongoService;
use App\Support\Money;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One refund attempt for an orphan payment. Same rules as SendRefund: claim first, never retried
 * automatically, and an unknown outcome is left pending for an admin to reconcile.
 */
class SendOrphanRefund implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $orphanId) {}

    public function handle(PayMongoService $payMongo, AuditLogger $audit): void
    {
        $claimed = OrphanPayment::query()
            ->whereKey($this->orphanId)
            ->where('refund_status', OrphanPaymentService::REFUND_PENDING)
            ->whereNull('refund_attempted_at')
            ->update(['refund_attempted_at' => now(), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $orphan = OrphanPayment::query()->findOrFail($this->orphanId);

        try {
            $refundRef = $payMongo->createRefund(
                $orphan->provider_ref,
                Money::toCentavos($orphan->amount),
                "Refund of a payment with unknown reference {$orphan->reference_number}.",
                "orphan-{$orphan->id}",
            );
        } catch (PayMongoException $e) {
            $e->definite ? $this->fail($orphan, $e->errorCode, $audit) : $this->unknown($orphan, $e->errorCode, $audit);

            return;
        } catch (Throwable $e) {
            $this->unknown($orphan, class_basename($e), $audit);

            return;
        }

        $orphan->forceFill(['refund_status' => OrphanPaymentService::REFUNDED, 'refund_ref' => $refundRef])->save();
        $audit->record('orphan_payment.refunded', $orphan, ['refund_status' => 'refund_pending'], ['refund_status' => 'refunded', 'refund_ref' => $refundRef]);
    }

    private function fail(OrphanPayment $orphan, string $code, AuditLogger $audit): void
    {
        $orphan->forceFill([
            'refund_status' => OrphanPaymentService::REFUND_FAILED,
            'note' => mb_substr("PayMongo: {$code}. ".$orphan->note, 0, 255),
        ])->save();

        $audit->record('orphan_payment.refund_failed', $orphan, null, ['error' => $code]);
    }

    private function unknown(OrphanPayment $orphan, string $code, AuditLogger $audit): void
    {
        $orphan->forceFill(['note' => mb_substr("Outcome unknown ({$code}): check PayMongo before acting. ".$orphan->note, 0, 255)])->save();

        $audit->record('orphan_payment.refund_outcome_unknown', $orphan, null, ['error' => $code]);
        Log::error('Orphan refund outcome unknown; not resending.', ['orphan' => $orphan->id, 'code' => $code]);
    }
}
