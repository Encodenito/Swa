<?php

namespace Tests\Feature\Stock;

use App\Enums\MovementType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\ReservationStatus;
use App\Enums\SettingKey;
use App\Jobs\SendRefund;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Services\ReservationService;
use App\Services\SettingsService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\MakesReservations;
use Tests\TestCase;

class ForfeitStockTest extends TestCase
{
    use MakesReservations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
        $this->seed(SettingsSeeder::class);
        $this->travelTo(now()->parse('2026-09-24 10:00:00'));
    }

    private function downpayment(Reservation $reservation): Payment
    {
        return Payment::query()
            ->where('reservation_id', $reservation->id)
            ->where('type', PaymentType::Downpayment)
            ->where('status', PaymentStatus::Paid)
            ->sole();
    }

    /**
     * @return array<int, int> batch_id => quantity_remaining for the reservation's batches
     */
    private function batchLevels(Reservation $reservation): array
    {
        return StockBatch::query()
            ->whereIn('id', $reservation->lines()->pluck('batch_id'))
            ->orderBy('id')
            ->pluck('quantity_remaining', 'id')
            ->map('intval')
            ->all();
    }

    private function travelPastDeadline(Reservation $reservation): void
    {
        $this->travelTo($reservation->fresh()->pickup_by->copy()->addMinute());
    }

    public function test_forfeit_releases_stock_to_exact_batches_and_queues_half_refund(): void
    {
        $reservation = $this->confirmedReservation();
        $lines = $reservation->lines()->get();
        $before = $this->batchLevels($reservation);
        $downpayment = $this->downpayment($reservation);
        $this->assertSame('300.00', (string) $downpayment->amount);

        $this->travelPastDeadline($reservation);
        $this->artisan('reservations:forfeit')->assertSuccessful();

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Forfeited, $reservation->status);
        $this->assertNotNull($reservation->forfeited_at);

        $expected = $before;
        foreach ($lines as $line) {
            $expected[$line->batch_id] += $line->quantity;
        }
        $this->assertSame($expected, $this->batchLevels($reservation));

        $released = StockMovement::query()->where('reservation_id', $reservation->id)->where('type', MovementType::Released)->get();
        $this->assertCount($lines->count(), $released);
        foreach ($lines as $line) {
            $movement = $released->firstWhere('batch_id', $line->batch_id);
            $this->assertNotNull($movement);
            $this->assertSame($line->quantity, (int) $movement->quantity);
        }

        $refunds = Payment::query()->where('reservation_id', $reservation->id)->where('type', PaymentType::Refund)->get();
        $this->assertCount(1, $refunds);
        $refund = $refunds->first();
        $this->assertSame($downpayment->id, $refund->parent_id);
        $this->assertSame('150.00', (string) $refund->amount);
        $this->assertSame(PaymentStatus::RefundPending, $refund->status);

        Queue::assertPushed(SendRefund::class, 1);

        $this->assertStockReconciles();
    }

    public function test_not_forfeited_before_the_deadline(): void
    {
        $reservation = $this->confirmedReservation();
        $before = $this->batchLevels($reservation);

        $this->travelTo($reservation->pickup_by->copy()->subMinute());
        $this->artisan('reservations:forfeit')->assertSuccessful();

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
        $this->assertSame($before, $this->batchLevels($reservation));
        $this->assertSame(0, StockMovement::query()->where('reservation_id', $reservation->id)->where('type', MovementType::Released)->count());
        $this->assertSame(0, Payment::query()->where('type', PaymentType::Refund)->count());
        Queue::assertNotPushed(SendRefund::class);

        $this->assertStockReconciles();
    }

    public function test_processing_reservation_is_forfeited_too(): void
    {
        $reservation = $this->confirmedReservation();
        app(ReservationService::class)->startProcessing($reservation);
        $this->assertSame(ReservationStatus::Processing, $reservation->fresh()->status);
        $before = $this->batchLevels($reservation);

        $this->travelPastDeadline($reservation);
        $this->artisan('reservations:forfeit')->assertSuccessful();

        $this->assertSame(ReservationStatus::Forfeited, $reservation->fresh()->status);
        $this->assertNotSame($before, $this->batchLevels($reservation));
        $this->assertSame($reservation->lines()->count(), StockMovement::query()->where('reservation_id', $reservation->id)->where('type', MovementType::Released)->count());
        $this->assertSame(1, Payment::query()->where('reservation_id', $reservation->id)->where('type', PaymentType::Refund)->count());

        $this->assertStockReconciles();
    }

    public function test_running_forfeit_twice_changes_nothing_more(): void
    {
        $reservation = $this->confirmedReservation();

        $this->travelPastDeadline($reservation);
        $this->artisan('reservations:forfeit')->assertSuccessful();

        $levels = $this->batchLevels($reservation);
        $movements = StockMovement::count();
        $payments = Payment::count();

        $this->travel(2)->hours();
        $this->artisan('reservations:forfeit')->assertSuccessful();
        $this->assertSame(0, app(ReservationService::class)->forfeitDue());

        $this->assertSame(ReservationStatus::Forfeited, $reservation->fresh()->status);
        $this->assertSame($levels, $this->batchLevels($reservation));
        $this->assertSame($movements, StockMovement::count());
        $this->assertSame($payments, Payment::count());
        $this->assertSame(1, StockMovement::query()->where('reservation_id', $reservation->id)->where('type', MovementType::Released)->count());
        Queue::assertPushed(SendRefund::class, 1);

        $this->assertStockReconciles();
    }

    public function test_zero_forfeit_percent_creates_no_refund(): void
    {
        app(SettingsService::class)->set(SettingKey::ForfeitRefundPercent, 0);
        $reservation = $this->confirmedReservation();

        $this->travelPastDeadline($reservation);
        $this->artisan('reservations:forfeit')->assertSuccessful();

        $this->assertSame(ReservationStatus::Forfeited, $reservation->fresh()->status);
        $this->assertSame(0, Payment::query()->where('type', PaymentType::Refund)->count());
        $this->assertSame($reservation->lines()->count(), StockMovement::query()->where('reservation_id', $reservation->id)->where('type', MovementType::Released)->count());
        Queue::assertNotPushed(SendRefund::class);

        $this->assertStockReconciles();
    }
}
