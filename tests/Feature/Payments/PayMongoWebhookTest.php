<?php

namespace Tests\Feature\Payments;

use App\Console\Commands\SimulatePayMongoWebhook;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Payment;
use App\Services\PayMongo\WebhookSignature;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesReservations;
use Tests\TestCase;

class PayMongoWebhookTest extends TestCase
{
    use MakesReservations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);
        $this->travelTo('2026-09-24 10:00:00');
    }

    public function test_a_verified_paid_event_confirms_the_reservation_and_sets_the_pickup_deadline(): void
    {
        $reservation = $this->pendingReservation();

        $this->postPaidWebhook($reservation, 'pay_abc')->assertOk()->assertJson(['result' => 'confirmed']);

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        // pickup_days = 3, end of day, Manila time.
        $this->assertSame('2026-09-27 23:59:59', $reservation->pickup_by->format('Y-m-d H:i:s'));

        $payment = Payment::sole();
        $this->assertSame('pay_abc', $payment->provider_ref);
        $this->assertSame('300.00', $payment->amount);
        $this->assertSame(PaymentStatus::Paid, $payment->status);
    }

    public function test_missing_signature_is_rejected(): void
    {
        $reservation = $this->pendingReservation();

        $this->postPaidWebhook($reservation, signatureHeader: '')->assertStatus(400);

        $this->assertSame(ReservationStatus::PendingPayment, $reservation->fresh()->status);
        $this->assertSame(0, Payment::count());
    }

    public function test_forged_signature_is_rejected(): void
    {
        $reservation = $this->pendingReservation();
        $t = (string) time();

        $this->postPaidWebhook($reservation, signatureHeader: "t={$t},te=".str_repeat('a', 64).',li=')->assertStatus(400);

        $this->assertSame(0, Payment::count());
    }

    public function test_signature_made_with_another_secret_is_rejected(): void
    {
        $reservation = $this->pendingReservation();
        $forger = new WebhookSignature('whsk_attacker', false, 300);
        $payload = SimulatePayMongoWebhook::payload($reservation, 'pay_x', 30000);

        $this->postPaidWebhook($reservation, payload: $payload, signatureHeader: $forger->header(json_encode($payload)))->assertStatus(400);

        $this->assertSame(0, Payment::count());
    }

    public function test_a_tampered_body_is_rejected(): void
    {
        $reservation = $this->pendingReservation();
        $signedFor = SimulatePayMongoWebhook::payload($reservation, 'pay_x', 30000);
        $header = WebhookSignature::fromConfig()->header(json_encode($signedFor));

        $tampered = $signedFor;
        $tampered['data']['attributes']['data']['attributes']['payments'][0]['id'] = 'pay_other';

        $this->postPaidWebhook($reservation, payload: $tampered, signatureHeader: $header)->assertStatus(400);
    }

    public function test_a_stale_signature_is_rejected(): void
    {
        $reservation = $this->pendingReservation();
        $payload = SimulatePayMongoWebhook::payload($reservation, 'pay_x', 30000);
        $header = WebhookSignature::fromConfig()->header(json_encode($payload), time() - 3600);

        $this->postPaidWebhook($reservation, payload: $payload, signatureHeader: $header)->assertStatus(400);
    }

    public function test_a_live_mode_signature_is_not_accepted_in_test_mode(): void
    {
        $reservation = $this->pendingReservation();
        $payload = SimulatePayMongoWebhook::payload($reservation, 'pay_x', 30000);
        $raw = json_encode($payload);
        $t = (string) time();
        $sig = hash_hmac('sha256', "{$t}.{$raw}", config('paymongo.webhook_secret'));

        $this->postPaidWebhook($reservation, payload: $payload, signatureHeader: "t={$t},te=,li={$sig}")->assertStatus(400);
    }

    public function test_the_same_event_delivered_twice_creates_one_payment(): void
    {
        $reservation = $this->pendingReservation();

        $this->postPaidWebhook($reservation, 'pay_dup')->assertOk()->assertJson(['result' => 'confirmed']);
        $this->postPaidWebhook($reservation, 'pay_dup')->assertOk()->assertJson(['result' => 'duplicate']);

        $this->assertSame(1, Payment::count());
        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    public function test_a_payment_after_expiry_is_stored_for_review_without_changing_status(): void
    {
        $reservation = $this->pendingReservation();
        $this->travel(31)->minutes();
        $this->artisan('reservations:expire')->assertSuccessful();

        $this->postPaidWebhook($reservation, 'pay_late')->assertOk()->assertJson(['result' => 'needs_review']);

        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
        $this->assertSame(PaymentStatus::NeedsReview, Payment::sole()->status);
    }

    public function test_a_payment_with_the_wrong_amount_is_stored_for_review(): void
    {
        $reservation = $this->pendingReservation();

        $this->postPaidWebhook($reservation, 'pay_short', amount: '100.00')->assertOk()->assertJson(['result' => 'needs_review']);

        $this->assertSame(ReservationStatus::PendingPayment, $reservation->fresh()->status);
        $this->assertSame(PaymentStatus::NeedsReview, Payment::sole()->status);
    }

    public function test_other_event_types_are_acknowledged_and_ignored(): void
    {
        $reservation = $this->pendingReservation();
        $payload = SimulatePayMongoWebhook::payload($reservation, 'pay_x', 30000);
        $payload['data']['attributes']['type'] = 'payment.failed';

        $this->postPaidWebhook($reservation, payload: $payload)->assertOk()->assertJson(['result' => 'ignored']);

        $this->assertSame(0, Payment::count());
    }

    public function test_the_alternative_payload_shape_is_understood(): void
    {
        $reservation = $this->pendingReservation();
        $standard = SimulatePayMongoWebhook::payload($reservation, 'pay_alt', 30000);
        $alternative = ['data' => [
            'type' => $standard['data']['attributes']['type'],
            'data' => $standard['data']['attributes']['data'],
        ]];

        $this->postPaidWebhook($reservation, payload: $alternative)->assertOk()->assertJson(['result' => 'confirmed']);
    }

    public function test_an_unreadable_paid_event_is_not_silently_dropped(): void
    {
        $reservation = $this->pendingReservation();
        $payload = SimulatePayMongoWebhook::payload($reservation, 'pay_x', 30000);
        unset($payload['data']['attributes']['data']['attributes']['payments']);

        $this->postPaidWebhook($reservation, payload: $payload)->assertStatus(422);

        $this->assertSame(ReservationStatus::PendingPayment, $reservation->fresh()->status);
    }

    public function test_an_unknown_reference_is_acknowledged(): void
    {
        $reservation = $this->pendingReservation();
        $payload = SimulatePayMongoWebhook::payload($reservation, 'pay_x', 30000);
        $payload['data']['attributes']['data']['attributes']['reference_number'] = 'SWA-NOTFOUND';

        $this->postPaidWebhook($reservation, payload: $payload)->assertOk()->assertJson(['result' => 'unknown_reservation']);
        $this->assertSame(0, Payment::count());
    }

    public function test_the_browser_return_page_never_confirms(): void
    {
        $reservation = $this->pendingReservation();

        $this->actingAs($reservation->user)->get(route('account.reservations.return', $reservation))->assertOk();
        $this->actingAs($reservation->user)->getJson(route('account.reservations.status', $reservation))
            ->assertOk()->assertJson(['status' => 'pending_payment']);

        $this->assertSame(ReservationStatus::PendingPayment, $reservation->fresh()->status);
    }
}
