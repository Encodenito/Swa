<?php

namespace Tests\Feature\Reservations;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\ReservationStatus;
use App\Exceptions\StateConflictException;
use App\Models\Payment;
use App\Models\User;
use App\Services\ReservationService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesReservations;
use Tests\TestCase;

class StaffActionsTest extends TestCase
{
    use MakesReservations, RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);
        $this->travelTo('2026-09-24 10:00:00');
        $this->staff = User::factory()->staff()->create();
    }

    public function test_staff_start_processing_a_confirmed_reservation(): void
    {
        $reservation = $this->confirmedReservation();

        $this->actingAs($this->staff)
            ->post(route('staff.reservations.process', $reservation))
            ->assertRedirect()
            ->assertSessionHas('success');

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Processing, $reservation->status);
        $this->assertNotNull($reservation->processing_at);
    }

    public function test_starting_processing_twice_lets_exactly_one_win(): void
    {
        $reservation = $this->confirmedReservation();
        $service = app(ReservationService::class);

        $service->startProcessing($reservation);

        $this->expectException(StateConflictException::class);
        $service->startProcessing($reservation);
    }

    public function test_a_conflict_is_a_409_for_json_and_a_flash_error_for_pages(): void
    {
        $reservation = $this->pendingReservation();

        $this->actingAs($this->staff)
            ->postJson(route('staff.reservations.process', $reservation))
            ->assertStatus(409);

        $this->actingAs($this->staff)
            ->from(route('staff.reservations.show', $reservation))
            ->post(route('staff.reservations.process', $reservation))
            ->assertRedirect(route('staff.reservations.show', $reservation))
            ->assertSessionHas('error');

        $this->assertSame(ReservationStatus::PendingPayment, $reservation->fresh()->status);
    }

    public function test_staff_extend_the_pickup_deadline_with_a_reason(): void
    {
        $reservation = $this->confirmedReservation();

        $this->actingAs($this->staff)
            ->post(route('staff.reservations.extend', $reservation), ['pickup_by' => '2026-10-01', 'reason' => 'Customer is out of town'])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-10-01 23:59:59', $reservation->fresh()->pickup_by->format('Y-m-d H:i:s'));
    }

    public function test_extension_needs_a_reason_and_a_later_date(): void
    {
        $reservation = $this->confirmedReservation();

        $this->actingAs($this->staff)
            ->post(route('staff.reservations.extend', $reservation), ['pickup_by' => '2026-10-01'])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->staff)
            ->post(route('staff.reservations.extend', $reservation), ['pickup_by' => '2026-09-25', 'reason' => 'Earlier'])
            ->assertSessionHasErrors('pickup_by');
    }

    public function test_pickup_counter_finds_by_reference_phone_and_email(): void
    {
        $reservation = $this->confirmedReservation();
        $customer = $reservation->user;

        foreach ([$reservation->reference_code, strtolower($reservation->reference_code), $customer->phone, $customer->email] as $q) {
            $this->actingAs($this->staff)->get(route('staff.pickup', ['q' => $q]))
                ->assertInertia(fn ($page) => $page->component('Staff/Pickup')->has('results', 1));
        }
    }

    public function test_pickup_completes_only_with_the_exact_balance(): void
    {
        $reservation = $this->confirmedReservation(); // total 1000.00, downpayment 300.00, balance 700.00

        $this->actingAs($this->staff)
            ->post(route('staff.pickup.complete', $reservation), ['amount' => '699.99', 'id_checked' => '1'])
            ->assertSessionHasErrors('amount');

        $this->actingAs($this->staff)
            ->post(route('staff.pickup.complete', $reservation), ['amount' => '700', 'id_checked' => ''])
            ->assertSessionHasErrors('id_checked');

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);

        $this->actingAs($this->staff)
            ->post(route('staff.pickup.complete', $reservation), ['amount' => '700', 'id_checked' => '1'])
            ->assertRedirect(route('staff.pickup'))
            ->assertSessionHas('success');

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Completed, $reservation->status);

        $balance = Payment::where('type', PaymentType::Balance)->sole();
        $this->assertSame('700.00', $balance->amount);
        $this->assertSame(PaymentMethod::Cash, $balance->method);
        $this->assertSame(PaymentStatus::Paid, $balance->status);
        $this->assertSame($this->staff->id, $balance->created_by);

        // Money reconciles: downpayment + balance = total.
        $this->assertSame('1000.00', number_format((float) $reservation->payments()->sum('amount'), 2, '.', ''));
    }

    public function test_a_processing_reservation_can_be_completed_but_not_a_pending_one(): void
    {
        $processing = $this->confirmedReservation();
        app(ReservationService::class)->startProcessing($processing);

        $this->actingAs($this->staff)
            ->post(route('staff.pickup.complete', $processing), ['amount' => '700.00', 'id_checked' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame(ReservationStatus::Completed, $processing->fresh()->status);

        $pending = $this->pendingReservation();

        $this->actingAs($this->staff)
            ->postJson(route('staff.pickup.complete', $pending), ['amount' => '700.00', 'id_checked' => '1'])
            ->assertStatus(409);
    }

    public function test_a_completed_reservation_cannot_be_completed_again(): void
    {
        $reservation = $this->confirmedReservation();

        $this->actingAs($this->staff)->post(route('staff.pickup.complete', $reservation), ['amount' => '700.00', 'id_checked' => '1']);
        $this->actingAs($this->staff)
            ->postJson(route('staff.pickup.complete', $reservation), ['amount' => '700.00', 'id_checked' => '1'])
            ->assertStatus(409);

        $this->assertSame(1, Payment::where('type', PaymentType::Balance)->count());
    }
}
