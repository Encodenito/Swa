<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\Item;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MakesReservations;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use MakesReservations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);
    }

    public function test_a_customer_cannot_see_another_customers_reservation(): void
    {
        $reservation = $this->pendingReservation();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->get(route('account.reservations.show', $reservation))->assertForbidden();
        $this->actingAs($intruder)->getJson(route('account.reservations.status', $reservation))->assertForbidden();
        $this->actingAs($intruder)->get(route('account.reservations.return', $reservation))->assertForbidden();
        $this->actingAs($intruder)->post(route('account.reservations.pay', $reservation))->assertForbidden();
    }

    public function test_the_owner_can_see_their_reservation_without_staff_only_payment_details(): void
    {
        $reservation = $this->confirmedReservation();

        $this->actingAs($reservation->user)->get(route('account.reservations.show', $reservation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Account/Reservations/Show')
                ->where('reservation.payments.0.provider_ref', null));
    }

    public function test_customers_cannot_use_staff_or_admin_actions(): void
    {
        $reservation = $this->confirmedReservation();
        $customer = $reservation->user;

        $this->actingAs($customer)->post(route('staff.reservations.process', $reservation))->assertForbidden();
        $this->actingAs($customer)->post(route('staff.pickup.complete', $reservation), ['amount' => '700', 'id_checked' => '1'])->assertForbidden();
        $this->actingAs($customer)->post(route('staff.reservations.extend', $reservation), ['pickup_by' => '2099-01-01', 'reason' => 'x'])->assertForbidden();
        $this->actingAs($customer)->get(route('staff.deliveries.index'))->assertForbidden();
        $this->actingAs($customer)->put(route('admin.settings.update'), [])->assertForbidden();
    }

    public function test_staff_cannot_reserve_or_use_admin_screens(): void
    {
        $staff = User::factory()->staff()->create();
        $item = Item::factory()->create();

        $this->actingAs($staff)->post(route('account.cart.add'), ['item_id' => $item->id, 'quantity' => 1])->assertForbidden();
        $this->actingAs($staff)->post(route('account.reservations.store'), ['terms' => '1'])->assertForbidden();
        $this->actingAs($staff)->get(route('admin.items.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('admin.settings.edit'))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.categories.store'), ['name' => 'X'])->assertForbidden();
    }

    public function test_unverified_customers_cannot_reserve(): void
    {
        $customer = User::factory()->unverified()->create();

        $this->actingAs($customer)->post(route('account.reservations.store'), ['terms' => '1'])->assertRedirect(route('verification.notice'));
    }

    public function test_deactivated_users_are_logged_out_and_cannot_log_in(): void
    {
        $user = User::factory()->create(['deactivated_at' => now()]);

        $this->actingAs($user)->get(route('account.home'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_staff_cannot_set_a_clearance_price_through_stock_receiving(): void
    {
        $staff = User::factory()->staff()->create();
        $item = Item::factory()->create();
        $supplier = Supplier::factory()->create();

        $this->actingAs($staff)->post(route('staff.deliveries.store'), [
            'supplier_id' => $supplier->id,
            'lines' => [['item_id' => $item->id, 'quantity_ordered' => 5, 'sale_price' => '1.00']],
        ])->assertSessionHasNoErrors();

        $this->assertNull(Delivery::sole()->lines()->sole()->sale_price);
    }

    public function test_only_admins_change_batch_prices(): void
    {
        $item = Item::factory()->create();
        $batch = $this->stockBatch($item, 2);

        $this->actingAs(User::factory()->staff()->create())
            ->put(route('admin.batches.price', $batch), ['sale_price' => '500.00'])
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('admin.batches.price', $batch), ['sale_price' => '500.00'])
            ->assertSessionHasNoErrors();

        $this->assertSame('500.00', $batch->fresh()->sale_price);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function guestBlockedRoutes(): array
    {
        return [
            'cart' => ['/account/cart'],
            'staff reservations' => ['/staff/reservations'],
            'pickup' => ['/staff/pickup'],
            'stock' => ['/staff/stock'],
            'admin items' => ['/admin/items'],
            'admin settings' => ['/admin/settings'],
        ];
    }

    #[DataProvider('guestBlockedRoutes')]
    public function test_guests_are_sent_to_login(string $uri): void
    {
        $this->get($uri)->assertRedirect('/login');
    }
}
