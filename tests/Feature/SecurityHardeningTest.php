<?php

namespace Tests\Feature;

use App\Console\Commands\SimulatePayMongoWebhook;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Category;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Services\PayMongo\WebhookSignature;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MakesReservations;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use MakesReservations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);
    }

    public function test_the_placeholder_webhook_secret_is_refused_outside_local(): void
    {
        config(['paymongo.webhook_secret' => WebhookSignature::PLACEHOLDER]);
        $reservation = $this->pendingReservation();

        // Signed correctly with the public placeholder, as an attacker could.
        $this->postPaidWebhook($reservation)->assertStatus(400);

        $this->assertSame(ReservationStatus::PendingPayment, $reservation->fresh()->status);
        $this->assertSame(0, Payment::count());
    }

    public function test_a_payment_from_a_different_checkout_session_is_held_for_review(): void
    {
        $reservation = $this->pendingReservation();
        $reservation->forceFill(['paymongo_checkout_id' => 'cs_ours'])->save();

        $payload = SimulatePayMongoWebhook::payload($reservation, 'pay_other_session', 30000);
        $payload['data']['attributes']['data']['id'] = 'cs_someone_elses';

        $this->postPaidWebhook($reservation, payload: $payload)->assertOk()->assertJson(['result' => 'needs_review']);

        $this->assertSame(ReservationStatus::PendingPayment, $reservation->fresh()->status);
        $this->assertSame(PaymentStatus::NeedsReview, Payment::sole()->status);
    }

    public function test_the_matching_checkout_session_confirms(): void
    {
        $reservation = $this->pendingReservation();
        $reservation->forceFill(['paymongo_checkout_id' => 'cs_ours'])->save();

        // The simulator uses the reservation's own session id.
        $this->postPaidWebhook($reservation)->assertOk()->assertJson(['result' => 'confirmed']);
    }

    public function test_a_checkout_url_off_paymongo_is_never_used_for_a_redirect(): void
    {
        Http::fake([
            'api.paymongo.com/*' => Http::response(['data' => ['id' => 'cs_x', 'attributes' => ['checkout_url' => 'https://evil.example/pay']]]),
        ]);
        $customer = User::factory()->create();
        $item = Item::factory()->create();
        $this->stockBatch($item, 2);

        $this->actingAs($customer)->post(route('account.cart.add'), ['item_id' => $item->id, 'quantity' => 1]);
        $this->actingAs($customer)->post(route('account.reservations.store'), ['terms' => '1'])
            ->assertRedirect(route('account.cart'));

        $this->assertSame(ReservationStatus::Cancelled, Reservation::sole()->status);
        $this->assertNull(Reservation::sole()->checkout_url);
    }

    public function test_the_webhook_runs_without_a_session(): void
    {
        $reservation = $this->pendingReservation();

        $response = $this->postPaidWebhook($reservation);

        $response->assertOk();
        $this->assertEmpty($response->headers->getCookies(), 'The webhook should not start a session or set cookies.');
    }

    public function test_search_wildcards_are_literal(): void
    {
        Item::factory()->create(['name' => 'Acer Aspire 5']);

        $this->get(route('items.index', ['q' => '%']))->assertInertia(fn ($page) => $page->has('items.data', 0));
        $this->get(route('items.index', ['q' => 'A_er']))->assertInertia(fn ($page) => $page->has('items.data', 0));
        $this->get(route('items.index', ['q' => 'Acer']))->assertInertia(fn ($page) => $page->has('items.data', 1));
    }

    public function test_category_names_that_collide_as_slugs_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        Category::create(['name' => 'Mice', 'slug' => 'mice']);

        $this->actingAs($admin)->post(route('admin.categories.store'), ['name' => 'mice!'])->assertSessionHasErrors('slug');
        $this->actingAs($admin)->post(route('admin.categories.store'), ['name' => '!!!'])->assertSessionHasErrors('slug');
        $this->assertSame(1, Category::count());
    }

    public function test_responses_carry_security_headers(): void
    {
        $this->get('/')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_an_inactive_item_can_be_removed_from_the_cart_but_not_increased(): void
    {
        $customer = User::factory()->create();
        $item = Item::factory()->create();
        $this->actingAs($customer)->post(route('account.cart.add'), ['item_id' => $item->id, 'quantity' => 1]);
        $item->forceFill(['active' => false])->save();

        $this->actingAs($customer)->patch(route('account.cart.update'), ['item_id' => $item->id, 'quantity' => 3])->assertNotFound();
        $this->actingAs($customer)->patch(route('account.cart.update'), ['item_id' => $item->id, 'quantity' => 0])->assertRedirect();

        $this->assertSame([], session('cart'));
    }
}
