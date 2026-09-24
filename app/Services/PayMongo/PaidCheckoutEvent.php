<?php

namespace App\Services\PayMongo;

/**
 * The parts of a checkout_session.payment.paid event the app uses. Parsed defensively because
 * PayMongo documents the event in two shapes (data.attributes.data vs data.data).
 */
final class PaidCheckoutEvent
{
    public const TYPE = 'checkout_session.payment.paid';

    public function __construct(
        public readonly string $referenceNumber,
        public readonly string $paymentId,
        public readonly int $amountCentavos,
        public readonly ?string $checkoutId,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function eventType(array $payload): ?string
    {
        $type = data_get($payload, 'data.attributes.type') ?? data_get($payload, 'data.type');

        return is_string($type) ? $type : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): ?self
    {
        $session = data_get($payload, 'data.attributes.data') ?? data_get($payload, 'data.data');

        if (! is_array($session)) {
            return null;
        }

        $reference = data_get($session, 'attributes.reference_number');
        $payment = data_get($session, 'attributes.payments.0');
        $paymentId = data_get($payment, 'id');
        $amount = data_get($payment, 'attributes.amount');

        if (! is_string($reference) || ! is_string($paymentId) || ! str_starts_with($paymentId, 'pay_') || ! is_int($amount)) {
            return null;
        }

        $checkoutId = data_get($session, 'id');

        return new self($reference, $paymentId, $amount, is_string($checkoutId) ? $checkoutId : null);
    }
}
