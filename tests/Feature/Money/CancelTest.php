<?php

namespace Tests\Feature\Money;

use App\Enums\MovementType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\ReservationStatus;
use App\Exceptions\StateConflictException;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Models\User;
use App\Notifications\ReservationCancelled;
use App\Services\ReservationService;
use App\Services\WalkInService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\MakesReservations;
use Tests\TestCase;

class CancelTest extends TestCase
{
    use MakesReservations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);
        Notification::fake();
        Http::fake(['api.paymongo.com/v1/refunds' => Http::response(['data' => ['id' => 'ref_cancel']])]);
    }

    private function refund(): ?Payment
    {
        return Payment::query()->where('type', PaymentType::Refund)->first();
    }

    public function test_customer_cancels_a_confirmed_reservation_with_a_full_refund_and_the_stock_returns(): void
    {
        $reservation = $this->confirmedReservation(); // 1 × ₱1,000, downpayment ₱300
        $item = $reservation->lines()->sole()->item;
        $this->assertSame(4, $item->availableQuantity());

        $this->actingAs($reservation->user)
            ->post(route('account.reservations.cancel', $reservation))
            ->assertRedirect(route('account.reservations.show', $reservation))
            ->assertSessionHas('success');

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Cancelled, $reservation->status);
        $this->assertSame($reservation->user_id, $reservation->cancelled_by);

        $refund = $this->refund();
        $this->assertSame('300.00', $refund->amount);
        $this->assertSame(PaymentStatus::Refunded, $refund->status);
        $this->assertSame('ref_cancel', $refund->provider_ref);

        $this->assertSame(5, $item->fresh()->availableQuantity());
        $this->assertSame(1, StockMovement::where('reservation_id', $reservation->id)->where('type', MovementType::Released)->count());
        $this->assertStockReconciles();

        $this->assertDatabaseHas('audit_logs', ['action' => 'reservation.cancelled', 'subject_type' => 'reservation', 'subject_id' => (string) $reservation->id]);
        Notification::assertSentTo($reservation->user, ReservationCancelled::class, fn ($n) => $n->refund?->is($refund));
    }

    public function test_customer_can_cancel_an_unpaid_reservation_with_nothing_to_refund(): void
    {
        $reservation = $this->pendingReservation();

        $this->actingAs($reservation->user)->post(route('account.reservations.cancel', $reservation))->assertSessionHas('success');

        $this->assertSame(ReservationStatus::Cancelled, $reservation->fresh()->status);
        $this->assertNull($this->refund());
        Http::assertNothingSent();
        $this->assertStockReconciles();
    }

    public function test_customer_cannot_cancel_once_processing(): void
    {
        $reservation = $this->confirmedReservation();
        app(ReservationService::class)->startProcessing($reservation);

        $this->actingAs($reservation->user)
            ->postJson(route('account.reservations.cancel', $reservation))
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'We have already started preparing this order, so it can no longer be cancelled online.']);

        $this->assertSame(ReservationStatus::Processing, $reservation->fresh()->status);
        $this->assertNull($this->refund());
        Notification::assertNotSentTo($reservation->user, ReservationCancelled::class);
    }

    public function test_customers_cannot_cancel_someone_elses_reservation(): void
    {
        $reservation = $this->confirmedReservation();

        $this->actingAs(User::factory()->create())->post(route('account.reservations.cancel', $reservation))->assertForbidden();
        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    public function test_staff_cancel_with_a_reason_and_no_refund_even_when_processing(): void
    {
        $reservation = $this->confirmedReservation();
        app(ReservationService::class)->startProcessing($reservation);
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->post(route('staff.reservations.cancel', $reservation), ['refund_percent' => 0, 'expected_status' => $reservation->fresh()->status->value])->assertSessionHasErrors('reason');
        $this->actingAs($staff)->post(route('staff.reservations.cancel', $reservation), ['refund_percent' => 100, 'reason' => 'Defective', 'expected_status' => $reservation->fresh()->status->value])->assertSessionHasErrors('refund_percent');
        $this->actingAs($staff)->post(route('staff.reservations.cancel', $reservation), ['refund_percent' => 0, 'reason' => 'Customer asked at the counter', 'expected_status' => $reservation->fresh()->status->value])->assertSessionHas('success');

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Cancelled, $reservation->status);
        $this->assertSame('Customer asked at the counter', $reservation->cancel_reason);
        $this->assertNull($this->refund());
        $this->assertStockReconciles();
    }

    public function test_staff_cannot_choose_a_refund_even_by_calling_the_service(): void
    {
        $reservation = $this->confirmedReservation();

        $this->expectException(ValidationException::class);
        app(ReservationService::class)->cancel($reservation, User::factory()->staff()->create(), 100, 'Sneaky');
    }

    public function test_admin_chooses_a_fifty_percent_refund(): void
    {
        $reservation = $this->confirmedReservation();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('staff.reservations.cancel', $reservation), ['refund_percent' => 25, 'reason' => 'Wrong price', 'expected_status' => $reservation->fresh()->status->value])->assertSessionHasErrors('refund_percent');
        $this->actingAs($admin)->post(route('staff.reservations.cancel', $reservation), ['refund_percent' => 50, 'reason' => 'Wrong price', 'expected_status' => $reservation->fresh()->status->value])->assertSessionHas('success');

        $this->assertSame('150.00', $this->refund()->amount);
        $this->assertSame(50, AuditLog::where('action', 'reservation.cancelled')->sole()->after['refund_percent']);
    }

    public function test_a_cancelled_reservation_cannot_be_cancelled_again_or_completed(): void
    {
        $reservation = $this->confirmedReservation();
        $service = app(ReservationService::class);
        $service->cancel($reservation, $reservation->user, 100, null);

        try {
            $service->cancel($reservation, User::factory()->admin()->create(), 100, 'Again');
            $this->fail('Second cancel should conflict');
        } catch (StateConflictException) {
        }

        $this->assertSame(1, Payment::where('type', PaymentType::Refund)->count());
        $this->assertSame(1, StockMovement::where('reservation_id', $reservation->id)->where('type', MovementType::Released)->count());
    }

    public function test_cancel_then_start_processing_lets_cancel_win(): void
    {
        $reservation = $this->confirmedReservation();
        $service = app(ReservationService::class);

        $service->cancel($reservation, $reservation->user, 100, null);

        $this->expectException(StateConflictException::class);
        $service->startProcessing($reservation);
    }

    public function test_start_processing_then_cancel_lets_processing_win(): void
    {
        $reservation = $this->confirmedReservation();
        $service = app(ReservationService::class);

        $service->startProcessing($reservation);

        $this->expectException(StateConflictException::class);
        $service->cancel($reservation, $reservation->user, 100, null);
    }

    public function test_walk_ins_are_not_cancelled_through_the_reservation_path(): void
    {
        $item = Item::factory()->create();
        $this->stockBatch($item, 2);
        $sale = app(WalkInService::class)->sell([$item->id => 1], null, null, User::factory()->staff()->create());

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('staff.reservations.cancel', $sale), ['refund_percent' => 0, 'reason' => 'Walk-in attempt', 'expected_status' => $sale->fresh()->status->value])
            ->assertForbidden();
    }
}
