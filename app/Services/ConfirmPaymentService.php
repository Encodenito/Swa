<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\ReservationStatus;
use App\Enums\SettingKey;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\PayMongo\PaidCheckoutEvent;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only code path that moves a reservation to `confirmed`. Called from the verified webhook.
 */
class ConfirmPaymentService
{
    public const CONFIRMED = 'confirmed';

    public const DUPLICATE = 'duplicate';

    public const NEEDS_REVIEW = 'needs_review';

    public const UNKNOWN_RESERVATION = 'unknown_reservation';

    public function __construct(private readonly SettingsService $settings) {}

    public function handlePaid(PaidCheckoutEvent $event): string
    {
        try {
            return DB::transaction(fn () => $this->record($event), 3);
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery of the same event inserted the payment first.
            return self::DUPLICATE;
        }
    }

    private function record(PaidCheckoutEvent $event): string
    {
        $reservation = Reservation::query()
            ->where('reference_code', $event->referenceNumber)
            ->lockForUpdate()
            ->first();

        if (! $reservation) {
            Log::warning('PayMongo payment for an unknown reservation.', ['reference' => $event->referenceNumber, 'payment' => $event->paymentId]);

            return self::UNKNOWN_RESERVATION;
        }

        if (Payment::query()->where('provider_ref', $event->paymentId)->exists()) {
            return self::DUPLICATE;
        }

        $amount = Money::fromCentavos($event->amountCentavos);
        $expected = Money::equals($amount, $reservation->downpayment_amount);
        $confirmable = $reservation->status === ReservationStatus::PendingPayment && $expected;

        $payment = new Payment;
        $payment->forceFill([
            'reservation_id' => $reservation->id,
            'type' => PaymentType::Downpayment,
            'amount' => $amount,
            'method' => PaymentMethod::PayMongo,
            'provider_ref' => $event->paymentId,
            'status' => $confirmable ? PaymentStatus::Paid : PaymentStatus::NeedsReview,
            'note' => $confirmable ? null : $this->reviewReason($reservation, $expected),
        ])->save();

        if (! $confirmable) {
            Log::warning('PayMongo payment stored for review.', ['reservation' => $reservation->reference_code, 'payment' => $event->paymentId]);

            return self::NEEDS_REVIEW;
        }

        $reservation->forceFill([
            'status' => ReservationStatus::Confirmed,
            'confirmed_at' => now(),
            'pickup_by' => now()->addDays($this->settings->int(SettingKey::PickupDays))->endOfDay(),
        ])->save();

        return self::CONFIRMED;
    }

    private function reviewReason(Reservation $reservation, bool $amountMatches): string
    {
        return $amountMatches
            ? "Payment arrived while the reservation was {$reservation->status->value}."
            : "Amount paid differs from the downpayment of {$reservation->downpayment_amount}.";
    }
}
