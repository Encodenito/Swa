<?php

namespace Tests\Feature\Money;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\ReservationStatus;
use App\Jobs\SendRefund;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\PickupReminder;
use App\Services\RefundService;
use App\Services\ReservationService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\MakesReservations;
use Tests\TestCase;

/**
 * Regression tests for the Phase 2 payments and security review findings.
 */
class ReviewFindingsTest extends TestCase
{
    use MakesReservations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);
        Notification::fake();
    }

    private function queueRefund(): Payment
    {
        $downpayment = $this->confirmedReservation()->payments()->where('type', PaymentType::Downpayment)->sole();

        return DB::transaction(fn () => app(RefundService::class)->queue($downpayment, '300.00', 'Cancelled'));
    }

    public function test_a_timeout_leaves_the_refund_pending_and_it_is_not_retryable(): void
    {
        Http::fake(['api.paymongo.com/v1/refunds' => fn () => throw new ConnectionException('Operation timed out')]);

        $refund = $this->queueRefund()->fresh();

        $this->assertSame(PaymentStatus::RefundPending, $refund->status);
        $this->assertNotNull($refund->refund_attempted_at);
        $this->assertStringContainsString('Outcome unknown', $refund->note);
        $this->assertDatabaseHas('audit_logs', ['action' => 'refund.outcome_unknown']);

        // Not retryable: the refund may already exist at PayMongo.
        $this->actingAs(User::factory()->staff()->create())->postJson(route('staff.payments.retry', $refund))->assertStatus(409);
        $this->assertSame(PaymentStatus::RefundPending, $refund->fresh()->status);
    }

    public function test_a_server_error_is_also_an_unknown_outcome(): void
    {
        Http::fake(['api.paymongo.com/v1/refunds' => Http::response('Bad gateway', 502)]);

        $this->assertSame(PaymentStatus::RefundPending, $this->queueRefund()->fresh()->status);
    }

    public function test_a_definite_refusal_is_failed_and_retryable(): void
    {
        Http::fake(['api.paymongo.com/v1/refunds' => Http::response(['errors' => [['code' => 'available_balance_insufficient']]], 400)]);

        $this->assertSame(PaymentStatus::RefundFailed, $this->queueRefund()->fresh()->status);
    }

    public function test_running_the_job_twice_sends_one_refund(): void
    {
        Http::fake(['api.paymongo.com/v1/refunds' => Http::response(['data' => ['id' => 'ref_once']])]);
        Queue::fake();
        $refund = $this->queueRefund();

        // e.g. a failed job pushed back with queue:retry after the first attempt already ran.
        app()->call([new SendRefund($refund->id), 'handle']);
        $refund->forceFill(['status' => PaymentStatus::RefundPending])->save(); // even if something reset the status
        app()->call([new SendRefund($refund->id), 'handle']);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r['data']['attributes']['metadata']['refund_id'] === (string) $refund->id);
    }

    public function test_admin_reconciles_a_stuck_refund_that_exists_at_paymongo(): void
    {
        Http::fake(['api.paymongo.com/v1/refunds' => Http::response('', 504)]);
        $refund = $this->queueRefund();
        $admin = User::factory()->admin()->create();

        // Not stuck yet.
        $this->actingAs($admin)->postJson(route('admin.payments.reconcile', $refund), ['outcome' => 'found', 'paymongo_refund_id' => 'ref_seen', 'reason' => 'Dashboard'])->assertStatus(409);

        $this->travel(16)->minutes();
        $this->actingAs(User::factory()->staff()->create())->post(route('admin.payments.reconcile', $refund), ['outcome' => 'found', 'paymongo_refund_id' => 'ref_seen', 'reason' => 'Dashboard'])->assertForbidden();
        $this->actingAs($admin)->post(route('admin.payments.reconcile', $refund), ['outcome' => 'found', 'paymongo_refund_id' => 'ref_seen', 'reason' => 'Seen in dashboard'])->assertSessionHas('success');

        $refund->refresh();
        $this->assertSame(PaymentStatus::Refunded, $refund->status);
        $this->assertSame('ref_seen', $refund->provider_ref);
        Http::assertSentCount(1); // reconciling never calls PayMongo
    }

    public function test_admin_reconciles_a_stuck_refund_that_does_not_exist_then_retries_once(): void
    {
        Http::fake(['api.paymongo.com/v1/refunds' => Http::sequence()->push('', 504)->push(['data' => ['id' => 'ref_second']])]);
        $refund = $this->queueRefund();
        $admin = User::factory()->admin()->create();
        $this->travel(16)->minutes();

        $this->actingAs($admin)->post(route('admin.payments.reconcile', $refund), ['outcome' => 'not_found', 'reason' => 'Nothing in dashboard'])->assertSessionHas('success');
        $this->assertSame(PaymentStatus::RefundFailed, $refund->fresh()->status);

        $this->actingAs($admin)->post(route('staff.payments.retry', $refund))->assertSessionHas('success');

        $this->assertSame(PaymentStatus::Refunded, $refund->fresh()->status);
        $this->assertSame('ref_second', $refund->fresh()->provider_ref);
        Http::assertSentCount(2);
    }

    public function test_staff_cancel_is_refused_when_the_status_changed_since_the_page_loaded(): void
    {
        Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);
        $reservation = $this->pendingReservation();
        $staff = User::factory()->staff()->create();

        // The webhook confirms it while staff are looking at the unpaid reservation.
        $this->postPaidWebhook($reservation);

        $this->actingAs($staff)->postJson(route('staff.reservations.cancel', $reservation), [
            'refund_percent' => 0, 'reason' => 'Customer changed mind', 'expected_status' => 'pending_payment',
        ])->assertStatus(409);

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    public function test_cancelling_an_unpaid_reservation_closes_its_paymongo_checkout(): void
    {
        Http::fake(['api.paymongo.com/v1/checkout_sessions/cs_open/expire' => Http::response(['data' => ['id' => 'cs_open']])]);
        $reservation = $this->pendingReservation();
        $reservation->forceFill(['paymongo_checkout_id' => 'cs_open'])->save();

        app(ReservationService::class)->cancel($reservation, $reservation->user, 100, null);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/checkout_sessions/cs_open/expire'));
    }

    public function test_no_reminder_for_a_reservation_cancelled_before_the_claim(): void
    {
        Http::fake(['api.paymongo.com/*' => Http::response(['data' => ['id' => 'ref_x']])]);
        $this->travelTo('2026-09-24 10:00:00');
        $reservation = $this->confirmedReservation();
        $this->travelTo('2026-09-27 08:00:00');
        Reservation::query()->whereKey($reservation->id)->update(['status' => ReservationStatus::Cancelled]);

        $this->artisan('reservations:remind')->expectsOutput('Sent 0 pickup reminder(s).');
        Notification::assertNotSentTo($reservation->user, PickupReminder::class);
    }

    public function test_staff_cannot_reserve_for_an_unverified_customer(): void
    {
        Http::fake();
        $unverified = User::factory()->unverified()->create();
        $item = Item::factory()->create();
        $this->stockBatch($item, 3);

        $this->actingAs(User::factory()->staff()->create())->post(route('staff.customer-reservations.store'), [
            'customer_id' => $unverified->id,
            'lines' => [['item_id' => $item->id, 'quantity' => 1]],
            'terms_confirmed' => '1',
        ])->assertSessionHasErrors('customer_id');

        $this->assertSame(0, Reservation::count());
    }

    public function test_staff_reserve_for_a_verified_customer_and_see_the_payment_link(): void
    {
        Http::fake(['api.paymongo.com/v1/checkout_sessions' => Http::response(['data' => ['id' => 'cs_staff', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_staff']]])]);
        $customer = User::factory()->create();
        $staff = User::factory()->staff()->create();
        $item = Item::factory()->create(['price' => '500.00']);
        $this->stockBatch($item, 3);

        $this->actingAs($staff)->post(route('staff.customer-reservations.store'), [
            'customer_id' => $customer->id,
            'lines' => [['item_id' => $item->id, 'quantity' => 2]],
            'terms_confirmed' => '1',
        ])->assertSessionHas('success');

        $reservation = Reservation::sole();
        $this->assertSame($customer->id, $reservation->user_id);
        $this->assertSame($staff->id, $reservation->created_by);
        $this->assertSame('1000.00', $reservation->total);

        $this->actingAs($staff)->get(route('staff.reservations.show', $reservation))
            ->assertInertia(fn ($page) => $page->where('reservation.checkout_url', 'https://checkout.paymongo.com/cs_staff'));
        $this->actingAs($customer)->get(route('account.reservations.show', $reservation))
            ->assertInertia(fn ($page) => $page->where('reservation.checkout_url', null));
    }

    public function test_new_staff_must_replace_the_temporary_password_first(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'New Staff', 'email' => 'new@swa.test', 'role' => 'staff', 'password' => 'Temporary12345',
        ]);
        $staff = User::where('email', 'new@swa.test')->sole();
        $this->assertTrue($staff->must_change_password);

        $this->actingAs($staff)->get('/staff')->assertRedirect(route('password.edit'));

        $this->actingAs($staff)->put(route('password.update'), [
            'current_password' => 'Temporary12345', 'password' => 'short1', 'password_confirmation' => 'short1',
        ])->assertSessionHasErrors('password');

        $this->actingAs($staff)->put(route('password.update'), [
            'current_password' => 'Temporary12345', 'password' => 'MyOwnPassword2026', 'password_confirmation' => 'MyOwnPassword2026',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($staff->fresh()->must_change_password);
        $this->actingAs($staff->fresh())->get('/staff')->assertOk();
    }

    public function test_walk_in_phone_must_be_a_philippine_mobile_number(): void
    {
        $item = Item::factory()->create();
        $this->stockBatch($item, 3);

        $this->actingAs(User::factory()->staff()->create())->post(route('staff.walk-ins.store'), [
            'lines' => [['item_id' => $item->id, 'quantity' => 1]],
            'walk_in_phone' => 'call me maybe',
        ])->assertSessionHasErrors('walk_in_phone');
    }

    public function test_phone_numbers_with_spaces_or_dashes_are_accepted(): void
    {
        $item = Item::factory()->create();
        $this->stockBatch($item, 3);

        $this->actingAs(User::factory()->staff()->create())->post(route('staff.walk-ins.store'), [
            'lines' => [['item_id' => $item->id, 'quantity' => 1]],
            'walk_in_phone' => '0917 123-4567',
        ])->assertSessionHasNoErrors();

        $this->assertSame('09171234567', Reservation::sole()->walk_in_phone);
    }

    public function test_staff_writes_are_rate_limited(): void
    {
        $staff = User::factory()->staff()->create();

        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($staff)->post(route('staff.walk-ins.store'), []);
        }

        $this->actingAs($staff)->post(route('staff.walk-ins.store'), [])->assertTooManyRequests();
    }
}
