<?php

namespace Tests\Concerns;

use App\Console\Commands\SimulatePayMongoWebhook;
use App\Enums\ReservationStatus;
use App\Models\Item;
use App\Models\Reservation;
use App\Models\User;
use App\Services\PayMongo\WebhookSignature;
use App\Services\ReservationService;
use App\Support\Money;
use Illuminate\Testing\TestResponse;

trait MakesReservations
{
    use BuildsStock;

    /**
     * A pending_payment reservation for one item (default: 1 unit at ₱1,000, downpayment ₱300).
     */
    protected function pendingReservation(?User $customer = null, ?Item $item = null, int $quantity = 1): Reservation
    {
        $customer ??= User::factory()->create();

        if (! $item) {
            $item = Item::factory()->create(['price' => '1000.00']);
            $this->stockBatch($item, 5);
        }

        return app(ReservationService::class)->create($customer, [$item->id => $quantity], true);
    }

    protected function confirmedReservation(?User $customer = null): Reservation
    {
        $reservation = $this->pendingReservation($customer);
        $this->postPaidWebhook($reservation)->assertOk();

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);

        return $reservation;
    }

    /**
     * POST a correctly signed checkout_session.payment.paid event, as PayMongo would.
     *
     * @param  array<string, mixed>|null  $payload
     */
    protected function postPaidWebhook(
        Reservation $reservation,
        string $paymentId = 'pay_test123',
        ?string $amount = null,
        ?array $payload = null,
        ?string $signatureHeader = null,
    ): TestResponse {
        $payload ??= SimulatePayMongoWebhook::payload(
            $reservation,
            $paymentId,
            Money::toCentavos($amount ?? $reservation->downpayment_amount),
        );

        $raw = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', route('webhooks.paymongo'), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => $signatureHeader ?? WebhookSignature::fromConfig()->header($raw),
        ], content: $raw);
    }
}
