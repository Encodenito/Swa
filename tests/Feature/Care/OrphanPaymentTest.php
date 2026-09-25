<?php

namespace Tests\Feature\Care;

use App\Console\Commands\SimulatePayMongoWebhook;
use App\Models\OrphanPayment;
use App\Models\User;
use App\Services\OrphanPaymentService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\MakesReservations;
use Tests\TestCase;

class OrphanPaymentTest extends TestCase
{
    use MakesReservations, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);
        Notification::fake();
        $this->admin = User::factory()->admin()->create();
    }

    private function orphan(string $paymentId = 'pay_orphan1'): OrphanPayment
    {
        $reservation = $this->pendingReservation();
        $payload = SimulatePayMongoWebhook::payload($reservation, $paymentId, 30000);
        $payload['data']['attributes']['data']['attributes']['reference_number'] = 'SWA-UNKNOWN1';

        $this->postPaidWebhook($reservation, payload: $payload)->assertOk()->assertJson(['result' => 'unknown_reservation']);

        return OrphanPayment::query()->where('provider_ref', $paymentId)->sole();
    }

    public function test_an_unknown_reference_is_stored_once(): void
    {
        $orphan = $this->orphan();
        $this->assertSame('300.00', $orphan->amount);
        $this->assertSame('SWA-UNKNOWN1', $orphan->reference_number);

        // Same event again: still one row, and no reservation was touched.
        $reservation = $this->pendingReservation();
        $payload = SimulatePayMongoWebhook::payload($reservation, 'pay_orphan1', 30000);
        $payload['data']['attributes']['data']['attributes']['reference_number'] = 'SWA-UNKNOWN1';
        $this->postPaidWebhook($reservation, payload: $payload)->assertOk();

        $this->assertSame(1, OrphanPayment::count());
    }

    public function test_orphans_appear_on_the_review_screen(): void
    {
        $orphan = $this->orphan();

        $this->actingAs($this->admin)->get(route('staff.payments.review'))
            ->assertInertia(fn ($page) => $page->has('orphans', 1)->where('orphans.0.id', $orphan->id)->where('orphans.0.kind', 'unmatched'));
    }

    public function test_admin_refunds_once_even_on_a_double_click(): void
    {
        Http::fake(['api.paymongo.com/v1/refunds' => Http::response(['data' => ['id' => 'ref_orphan']])]);
        $orphan = $this->orphan();

        $this->actingAs($this->admin)->post(route('admin.orphans.refund', $orphan), ['reason' => 'Not our customer'])->assertSessionHas('success');
        $this->actingAs($this->admin)->postJson(route('admin.orphans.refund', $orphan), ['reason' => 'Again'])->assertStatus(409);

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['data']['attributes']['payment_id'] === 'pay_orphan1'
            && $r['data']['attributes']['amount'] === 30000
            && $r['data']['attributes']['metadata']['refund_id'] === "orphan-{$orphan->id}");

        $orphan->refresh();
        $this->assertSame(OrphanPaymentService::REFUNDED, $orphan->refund_status);
        $this->assertSame('ref_orphan', $orphan->refund_ref);
    }

    public function test_a_definite_refusal_is_retryable_and_an_unknown_outcome_is_not(): void
    {
        Http::fake(['api.paymongo.com/v1/refunds' => Http::sequence()
            ->push(['errors' => [['code' => 'available_balance_insufficient']]], 400)
            ->push(['errors' => [['code' => 'internal']]], 502)]);

        $orphan = $this->orphan();
        $this->actingAs($this->admin)->post(route('admin.orphans.refund', $orphan), ['reason' => 'Refund']);
        $this->assertSame(OrphanPaymentService::REFUND_FAILED, $orphan->fresh()->refund_status);

        $this->actingAs($this->admin)->post(route('admin.orphans.retry', $orphan))->assertSessionHas('success');
        // 502: outcome unknown, stays pending, cannot be retried.
        $this->assertSame(OrphanPaymentService::REFUND_PENDING, $orphan->fresh()->refund_status);
        $this->actingAs($this->admin)->postJson(route('admin.orphans.retry', $orphan))->assertStatus(409);
        Http::assertSentCount(2);

        // After 15 minutes an admin reconciles against the dashboard.
        $this->travel(16)->minutes();
        $this->actingAs($this->admin)->post(route('admin.orphans.reconcile', $orphan), [
            'outcome' => 'found', 'paymongo_refund_id' => 'ref_found1', 'reason' => 'Seen in dashboard',
        ])->assertSessionHas('success');
        $this->assertSame(OrphanPaymentService::REFUNDED, $orphan->fresh()->refund_status);
    }

    public function test_only_admins_decide_on_orphans(): void
    {
        $orphan = $this->orphan();

        $this->actingAs(User::factory()->staff()->create())->post(route('admin.orphans.refund', $orphan), ['reason' => 'x x x'])->assertForbidden();

        $this->actingAs($this->admin)->post(route('admin.orphans.reviewed', $orphan), ['reason' => 'Refunded by bank transfer'])->assertSessionHas('success');
        $this->assertNotNull($orphan->fresh()->reviewed_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'orphan_payment.reviewed', 'subject_type' => 'orphan_payment']);
    }
}
