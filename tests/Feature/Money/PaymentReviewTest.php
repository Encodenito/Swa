<?php

namespace Tests\Feature\Money;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\ReservationStatus;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\MakesReservations;
use Tests\TestCase;

class PaymentReviewTest extends TestCase
{
    use MakesReservations, RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);
        Notification::fake();
        $this->staff = User::factory()->staff()->create();
    }

    /**
     * A payment that arrived after the reservation expired: stored as needs_review, status unchanged.
     */
    private function latePayment(): Payment
    {
        $reservation = $this->pendingReservation();
        $this->travel(31)->minutes();
        $this->artisan('reservations:expire');
        $this->postPaidWebhook($reservation, 'pay_late')->assertJson(['result' => 'needs_review']);

        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);

        return Payment::where('provider_ref', 'pay_late')->sole();
    }

    public function test_a_late_payment_appears_for_review_and_staff_refund_it_in_full(): void
    {
        Http::fake(['api.paymongo.com/v1/refunds' => Http::response(['data' => ['id' => 'ref_late']])]);
        $late = $this->latePayment();

        $this->actingAs($this->staff)->get(route('staff.payments.review'))
            ->assertInertia(fn ($page) => $page->component('Staff/Payments/Review')
                ->has('payments.data', 1)
                ->where('payments.data.0.kind', 'needs_review')
                ->where('payments.data.0.id', $late->id));

        $this->actingAs($this->staff)->post(route('staff.payments.refund', $late), ['reason' => 'Paid after expiry'])->assertSessionHas('success');

        $refund = Payment::where('type', PaymentType::Refund)->sole();
        $this->assertSame('300.00', $refund->amount);
        $this->assertSame($late->id, $refund->parent_id);
        $this->assertSame(PaymentStatus::Refunded, $refund->status);
        $this->assertNotNull($late->fresh()->reviewed_at);
        Http::assertSent(fn ($r) => $r['data']['attributes']['payment_id'] === 'pay_late');

        // Resolved: the list is empty and a second refund is refused.
        $this->actingAs($this->staff)->get(route('staff.payments.review'))->assertInertia(fn ($page) => $page->has('payments.data', 0));
        $this->actingAs($this->staff)->postJson(route('staff.payments.refund', $late), ['reason' => 'Again'])->assertStatus(409);
        Http::assertSentCount(1);
    }

    public function test_failed_refunds_are_listed_until_a_person_retries(): void
    {
        Http::fake(['api.paymongo.com/v1/refunds' => Http::response(['errors' => [['code' => 'resource_failed_state']]], 400)]);
        $late = $this->latePayment();

        $this->actingAs($this->staff)->post(route('staff.payments.refund', $late), ['reason' => 'Paid after expiry']);

        $this->actingAs($this->staff)->get(route('staff.payments.review'))
            ->assertInertia(fn ($page) => $page->has('payments.data', 1)->where('payments.data.0.kind', 'refund_failed'));
    }

    public function test_only_an_admin_can_keep_a_payment_without_refunding(): void
    {
        $late = $this->latePayment();

        $this->actingAs($this->staff)->post(route('admin.payments.reviewed', $late), ['reason' => 'Customer took store credit'])->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('admin.payments.reviewed', $late), ['reason' => 'Customer took store credit'])->assertSessionHas('success');

        $this->assertNotNull($late->fresh()->reviewed_at);
        $this->assertSame(0, Payment::where('type', PaymentType::Refund)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.reviewed', 'subject_id' => (string) $late->id]);
    }

    public function test_stuck_refunds_show_after_fifteen_minutes(): void
    {
        $late = $this->latePayment();
        $stuck = new Payment;
        $stuck->forceFill([
            'reservation_id' => $late->reservation_id,
            'parent_id' => $late->id,
            'type' => PaymentType::Refund,
            'amount' => '300.00',
            'method' => 'paymongo',
            'status' => PaymentStatus::RefundPending,
        ])->save();
        $late->forceFill(['reviewed_at' => now()])->save();

        $this->actingAs($this->staff)->get(route('staff.payments.review'))->assertInertia(fn ($page) => $page->has('payments.data', 0));

        $this->travel(16)->minutes();

        $this->actingAs($this->staff)->get(route('staff.payments.review'))
            ->assertInertia(fn ($page) => $page->has('payments.data', 1)->where('payments.data.0.kind', 'stuck'));
    }

    public function test_customers_cannot_open_the_review_screen(): void
    {
        $this->actingAs(User::factory()->create())->get(route('staff.payments.review'))->assertForbidden();
    }
}
