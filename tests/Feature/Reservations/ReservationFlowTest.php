<?php

namespace Tests\Feature\Reservations;

use App\Enums\MovementType;
use App\Enums\ReservationStatus;
use App\Models\Item;
use App\Models\Reservation;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\SettingsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MakesReservations;
use Tests\TestCase;

class ReservationFlowTest extends TestCase
{
    use MakesReservations, RefreshDatabase;

    private User $customer;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);

        $this->customer = User::factory()->create();
        $this->item = Item::factory()->create(['price' => '1500.00']);
        $this->stockBatch($this->item, 3);
    }

    private function fakeCheckout(int $status = 200): void
    {
        Http::fake([
            'api.paymongo.com/v1/checkout_sessions' => $status === 200
                ? Http::response(['data' => ['id' => 'cs_test_1', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_test_1']]])
                : Http::response(['errors' => [['code' => 'internal_error']]], $status),
        ]);
    }

    public function test_customer_reserves_and_is_sent_to_the_paymongo_checkout(): void
    {
        $this->fakeCheckout();

        $this->actingAs($this->customer)
            ->post(route('account.cart.add'), ['item_id' => $this->item->id, 'quantity' => 2])
            ->assertRedirect(route('account.cart'));

        // Client-sent amounts are ignored; only the terms flag is read.
        $response = $this->actingAs($this->customer)->post(route('account.reservations.store'), [
            'terms' => '1',
            'total' => '1.00',
            'downpayment_amount' => '0.01',
        ]);

        $response->assertRedirect('https://checkout.paymongo.com/cs_test_1');

        $reservation = Reservation::sole();
        $this->assertSame(ReservationStatus::PendingPayment, $reservation->status);
        $this->assertSame('3000.00', $reservation->total);
        $this->assertSame('900.00', $reservation->downpayment_amount);
        $this->assertSame('2100.00', $reservation->balance);
        $this->assertSame('cs_test_1', $reservation->paymongo_checkout_id);
        $this->assertNotNull($reservation->terms_accepted_at);
        $this->assertTrue($reservation->expires_at->between(now()->addMinutes(29), now()->addMinutes(31)));
        $this->assertMatchesRegularExpression('/^SWA-[A-HJ-NP-Z2-9]{8}$/', $reservation->reference_code);

        Http::assertSent(function (Request $request) use ($reservation) {
            $attributes = $request['data']['attributes'];

            return $request->url() === 'https://api.paymongo.com/v1/checkout_sessions'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('sk_test_phpunit:'))
                && $attributes['line_items'][0]['amount'] === 90000
                && $attributes['line_items'][0]['name'] === "Downpayment - {$reservation->reference_code}"
                && $attributes['line_items'][0]['currency'] === 'PHP'
                && $attributes['reference_number'] === $reservation->reference_code
                && $attributes['payment_method_types'] === ['gcash', 'card']
                && $attributes['metadata']['reservation_id'] === (string) $reservation->id;
        });

        // Cart is emptied once the reservation exists.
        $this->assertSame([], session('cart', []));
    }

    public function test_terms_must_be_accepted(): void
    {
        $this->fakeCheckout();
        $this->actingAs($this->customer)->post(route('account.cart.add'), ['item_id' => $this->item->id, 'quantity' => 1]);

        $this->actingAs($this->customer)->post(route('account.reservations.store'), [])
            ->assertSessionHasErrors('terms');

        $this->assertSame(0, Reservation::count());
        Http::assertNothingSent();
    }

    public function test_checkout_failure_cancels_the_reservation_and_returns_the_stock(): void
    {
        $this->fakeCheckout(500);
        $this->actingAs($this->customer)->post(route('account.cart.add'), ['item_id' => $this->item->id, 'quantity' => 2]);

        $this->actingAs($this->customer)->post(route('account.reservations.store'), ['terms' => '1'])
            ->assertRedirect(route('account.cart'))
            ->assertSessionHas('error');

        $reservation = Reservation::sole();
        $this->assertSame(ReservationStatus::Cancelled, $reservation->status);
        $this->assertSame(3, $this->item->fresh()->availableQuantity());
        $this->assertSame(2, (int) StockMovement::where('type', MovementType::Released)->sum('quantity'));
        $this->assertStockReconciles();
    }

    public function test_cart_quantity_is_capped_by_the_setting(): void
    {
        $this->actingAs($this->customer)
            ->post(route('account.cart.add'), ['item_id' => $this->item->id, 'quantity' => 11])
            ->assertSessionHasErrors('quantity');
    }

    public function test_cart_preview_shows_server_prices_and_the_live_terms(): void
    {
        $this->actingAs($this->customer)->post(route('account.cart.add'), ['item_id' => $this->item->id, 'quantity' => 1]);

        $this->actingAs($this->customer)->get(route('account.cart'))
            ->assertInertia(fn ($page) => $page
                ->component('Account/Cart')
                ->where('cart.total', '1500.00')
                ->where('cart.downpayment', '450.00')
                ->where('cart.balance', '1050.00')
                ->where('terms.pickupDays', 3)
                ->where('terms.forfeitRefundPercent', 50)
                ->where('canReserve', true));
    }

    public function test_resume_payment_redirects_to_the_stored_checkout_while_still_payable(): void
    {
        $reservation = $this->pendingReservation($this->customer);
        $reservation->forceFill(['checkout_url' => 'https://checkout.paymongo.com/cs_x'])->save();

        $this->actingAs($this->customer)->post(route('account.reservations.pay', $reservation))
            ->assertRedirect('https://checkout.paymongo.com/cs_x');

        $this->travel(31)->minutes();

        $this->actingAs($this->customer)->post(route('account.reservations.pay', $reservation))
            ->assertRedirect(route('account.reservations.show', $reservation))
            ->assertSessionHas('error');
    }

    public function test_an_unpaid_reservation_expires_and_its_stock_returns(): void
    {
        $reservation = $this->pendingReservation($this->customer, $this->item, 2);
        $this->assertSame(1, $this->item->fresh()->availableQuantity());

        $this->travel(29)->minutes();
        $this->artisan('reservations:expire')->expectsOutput('Expired 0 reservation(s).');

        $this->travel(2)->minutes();
        $this->artisan('reservations:expire')->expectsOutput('Expired 1 reservation(s).');

        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
        $this->assertSame(3, $this->item->fresh()->availableQuantity());
        $this->assertStockReconciles();
    }

    public function test_the_expire_job_is_scheduled_every_minute_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'reservations:expire'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
