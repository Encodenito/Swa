<?php

namespace Tests\Feature\Care;

use App\Console\Commands\SimulatePayMongoWebhook;
use App\Models\ChatHandoff;
use App\Models\OrphanPayment;
use App\Models\User;
use App\Services\ChatHandoffService;
use App\Services\DashboardService;
use App\Services\OrphanPaymentService;
use App\Support\SensitiveData;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\MakesReservations;
use Tests\TestCase;

class Phase3ReviewFindingsTest extends TestCase
{
    use MakesReservations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);
        Notification::fake();
    }

    public function test_card_detection_uses_luhn_and_common_separators(): void
    {
        $this->assertTrue(SensitiveData::containsCardNumber('card 4111.1111.1111.1111 please'));
        $this->assertTrue(SensitiveData::containsCardNumber('4111/1111/1111/1111'));
        $this->assertFalse(SensitiveData::containsCardNumber('call 0917 123 4567 or ref SWA-ABCD2345'));
        $this->assertFalse(SensitiveData::containsCardNumber('4111 1111 1111 1112')); // fails Luhn

        $this->assertSame('pay with [card number removed, ends 1111] ok', SensitiveData::maskCardNumbers('pay with 4111 1111 1111 1111 ok'));
    }

    public function test_notes_reject_dotted_card_numbers(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->post(route('staff.customers.notes.store', User::factory()->create()), ['body' => 'card 4111.1111.1111.1111'])
            ->assertSessionHasErrors('body');
    }

    public function test_handoff_masks_card_numbers_and_rejects_mailto_injection(): void
    {
        $this->postJson(route('chatbot.handoff'), ['name' => 'A', 'contact' => 'x?body=evil@a.com', 'message' => 'hi there'])
            ->assertStatus(422)->assertJsonValidationErrors('contact');

        $this->postJson(route('chatbot.handoff'), [
            'name' => 'A', 'contact' => 'ana@example.com', 'message' => 'My card is 4111 1111 1111 1111',
            'transcript' => [['from' => 'user', 'text' => 'charge 4111-1111-1111-1111']],
        ])->assertCreated();

        $handoff = ChatHandoff::sole();
        $this->assertStringNotContainsString('4111 1111 1111 1111', $handoff->message);
        $this->assertStringContainsString('ends 1111', $handoff->message);
        $this->assertStringNotContainsString('4111-1111', $handoff->transcript[0]['text']);
    }

    public function test_a_second_open_request_from_the_same_person_is_refused(): void
    {
        $this->postJson(route('chatbot.handoff'), ['name' => 'A', 'contact' => 'ana@example.com', 'message' => 'first'])->assertCreated();
        $this->postJson(route('chatbot.handoff'), ['name' => 'A', 'contact' => 'ana@example.com', 'message' => 'again'])->assertStatus(409);

        $this->assertSame(1, ChatHandoff::count());
    }

    public function test_an_admin_closing_keeps_the_original_claimer(): void
    {
        $handoff = app(ChatHandoffService::class)->create('Ana', 'a@example.com', 'Help', [], null);
        $staff = User::factory()->staff()->create();
        $stale = $handoff->replicate(); // page loaded before the claim
        $stale->id = $handoff->id;

        app(ChatHandoffService::class)->claim($handoff, $staff);
        app(ChatHandoffService::class)->close($stale, 'Admin closed it', User::factory()->admin()->create());

        $this->assertSame($staff->id, $handoff->fresh()->claimed_by);
    }

    public function test_closed_handoffs_are_purged_after_ninety_days(): void
    {
        $old = app(ChatHandoffService::class)->create('Old', 'old@example.com', 'x', [], null);
        $old->forceFill(['status' => 'closed', 'closed_at' => now()->subDays(91)])->save();
        $recent = app(ChatHandoffService::class)->create('New', 'new@example.com', 'x', [], null);
        $recent->forceFill(['status' => 'closed', 'closed_at' => now()->subDays(5)])->save();
        $open = app(ChatHandoffService::class)->create('Open', 'open@example.com', 'x', [], null);

        $this->artisan('chat:purge-handoffs')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
        $this->assertModelExists($open);
    }

    private function orphan(string $reference = 'SWA-UNKNOWN1'): OrphanPayment
    {
        $reservation = $this->pendingReservation();
        $payload = SimulatePayMongoWebhook::payload($reservation, 'pay_o'.$reference, 30000);
        $payload['data']['attributes']['data']['attributes']['reference_number'] = $reference;
        $this->postPaidWebhook($reservation, payload: $payload)->assertOk();

        return OrphanPayment::query()->where('reference_number', $reference)->sole();
    }

    public function test_foreign_looking_references_are_flagged(): void
    {
        $this->assertNull($this->orphan('SWA-ABCDEFGH')->note);
        $this->assertStringContainsString('not in this shop', $this->orphan('INV-2026-0001')->note);
    }

    public function test_stuck_orphan_refunds_count_on_the_dashboard_and_reconcile_waits_fifteen_minutes(): void
    {
        Http::fake(['api.paymongo.com/v1/refunds' => Http::response(['errors' => [['code' => 'x']]], 502)]);
        $admin = User::factory()->admin()->create();
        $orphan = $this->orphan();

        $this->actingAs($admin)->post(route('admin.orphans.refund', $orphan), ['reason' => 'Refund']);
        $this->assertSame(OrphanPaymentService::REFUND_PENDING, $orphan->fresh()->refund_status);
        $this->assertSame(0, app(DashboardService::class)->summary()['payments_to_review']['orphans']);

        $this->actingAs($admin)->postJson(route('admin.orphans.reconcile', $orphan), ['outcome' => 'not_found', 'reason' => 'Too early'])->assertStatus(409);

        $this->travel(16)->minutes();
        $this->assertSame(1, app(DashboardService::class)->summary()['payments_to_review']['orphans']);

        $this->actingAs($admin)->post(route('admin.orphans.reconcile', $orphan), ['outcome' => 'not_found', 'reason' => 'Not in dashboard'])->assertSessionHas('success');
        $this->assertSame(OrphanPaymentService::REFUND_FAILED, $orphan->fresh()->refund_status);
    }

    public function test_a_kept_orphan_cannot_then_be_refunded(): void
    {
        $admin = User::factory()->admin()->create();
        $orphan = $this->orphan();

        $this->actingAs($admin)->post(route('admin.orphans.reviewed', $orphan), ['reason' => 'Settled by bank transfer']);
        $this->actingAs($admin)->postJson(route('admin.orphans.refund', $orphan), ['reason' => 'Refund'])->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_staff_see_orphans_read_only(): void
    {
        $orphan = $this->orphan();

        $this->actingAs(User::factory()->staff()->create())->get(route('staff.payments.review'))
            ->assertInertia(fn ($page) => $page->has('orphans', 1)->where('canReconcile', false));
        $this->actingAs(User::factory()->staff()->create())->post(route('admin.orphans.retry', $orphan))->assertForbidden();
    }
}
