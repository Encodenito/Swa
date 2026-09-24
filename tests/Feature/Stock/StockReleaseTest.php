<?php

namespace Tests\Feature\Stock;

use App\Enums\MovementType;
use App\Enums\ReservationStatus;
use App\Models\Item;
use App\Models\Reservation;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ReservationService;
use App\Services\StockService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

class StockReleaseTest extends TestCase
{
    use BuildsStock, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->travelTo(now()->parse('2026-09-24 10:00:00'));
    }

    private function reservations(): ReservationService
    {
        return app(ReservationService::class);
    }

    private function remaining(StockBatch $batch): int
    {
        return (int) StockBatch::query()->whereKey($batch->id)->value('quantity_remaining');
    }

    /**
     * @return array{Reservation, StockBatch, StockBatch, StockBatch}
     */
    private function spanningReservation(): array
    {
        $a = Item::factory()->create(['price' => '500.00']);
        $b = Item::factory()->create(['price' => '250.00']);
        $a1 = $this->stockBatch($a, 2, '400.00', '2026-09-01 08:00:00');
        $a2 = $this->stockBatch($a, 5, null, '2026-09-10 08:00:00');
        $b1 = $this->stockBatch($b, 4, null, '2026-09-05 08:00:00');

        $reservation = $this->reservations()->create(User::factory()->create(), [$a->id => 3, $b->id => 1], true);

        $this->assertSame(3, $reservation->lines()->count());
        $this->assertSame(0, $this->remaining($a1));
        $this->assertSame(4, $this->remaining($a2));
        $this->assertSame(3, $this->remaining($b1));

        return [$reservation, $a1, $a2, $b1];
    }

    public function test_expire_returns_each_line_to_its_exact_batch_once(): void
    {
        Http::fake();
        [$reservation, $a1, $a2, $b1] = $this->spanningReservation();

        // Not due yet.
        $this->travel(29)->minutes();
        $this->assertSame(0, $this->reservations()->expireDue());
        $this->assertSame(ReservationStatus::PendingPayment, $reservation->fresh()->status);

        $this->travel(2)->minutes();
        $this->assertSame(1, $this->reservations()->expireDue());

        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
        $this->assertSame(2, $this->remaining($a1));
        $this->assertSame(5, $this->remaining($a2));
        $this->assertSame(4, $this->remaining($b1));

        $released = StockMovement::query()
            ->where('reservation_id', $reservation->id)
            ->where('type', MovementType::Released)
            ->orderBy('batch_id')
            ->get();

        $expected = $reservation->lines()->orderBy('batch_id')->get()
            ->map(fn ($line) => [$line->batch_id, $line->quantity])->all();

        $this->assertCount(3, $released);
        $this->assertSame($expected, $released->map(fn ($m) => [$m->batch_id, $m->quantity])->all());
        $this->assertSame('Not paid in time', $released->first()->reason);

        // Second run: nothing more to expire, nothing released again.
        $this->assertSame(0, $this->reservations()->expireDue());
        $this->assertSame(3, StockMovement::query()->where('reservation_id', $reservation->id)->where('type', MovementType::Released)->count());
        $this->assertSame(2, $this->remaining($a1));

        // Calling release() directly again is also a no-op.
        DB::transaction(fn () => app(StockService::class)->release($reservation->fresh()));
        $this->assertSame(3, StockMovement::query()->where('reservation_id', $reservation->id)->where('type', MovementType::Released)->count());
        $this->assertSame(2, $this->remaining($a1));
        $this->assertSame(5, $this->remaining($a2));
        $this->assertSame(4, $this->remaining($b1));

        // Net effect of this reservation on the ledger is zero.
        $this->assertSame(0, (int) StockMovement::query()->where('reservation_id', $reservation->id)->sum('quantity'));
        Http::assertNothingSent();
        $this->assertStockReconciles();
    }

    public function test_expire_calls_paymongo_only_when_checkout_id_is_set(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 'cs_test_1', 'attributes' => ['status' => 'expired']]], 200)]);
        [$withCheckout] = $this->spanningReservation();
        $withCheckout->forceFill(['paymongo_checkout_id' => 'cs_test_1'])->save();

        $item = Item::factory()->create();
        $this->stockBatch($item, 1);
        $without = $this->reservations()->create(User::factory()->create(), [$item->id => 1], true);

        $this->travel(31)->minutes();
        $this->assertSame(2, $this->reservations()->expireDue());

        $this->assertSame(ReservationStatus::Expired, $withCheckout->fresh()->status);
        $this->assertSame(ReservationStatus::Expired, $without->fresh()->status);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'cs_test_1'));
        $this->assertStockReconciles();
    }

    public function test_expire_skips_reservations_that_are_no_longer_pending(): void
    {
        [$reservation, $a1] = $this->spanningReservation();
        $reservation->forceFill(['status' => ReservationStatus::Confirmed])->save();

        $this->travel(31)->minutes();
        $this->assertSame(0, $this->reservations()->expireDue());

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
        $this->assertSame(0, $this->remaining($a1));
        $this->assertSame(0, StockMovement::query()->where('type', MovementType::Released)->count());
        $this->assertStockReconciles();
    }

    public function test_cancel_unpaid_releases_once(): void
    {
        [$reservation, $a1, $a2, $b1] = $this->spanningReservation();

        $this->assertTrue($this->reservations()->cancelUnpaid($reservation, 'Payment page could not be created.'));
        $this->assertFalse($this->reservations()->cancelUnpaid($reservation, 'Again'));

        $this->assertSame(ReservationStatus::Cancelled, $reservation->fresh()->status);
        $this->assertSame(3, StockMovement::query()->where('reservation_id', $reservation->id)->where('type', MovementType::Released)->count());
        $this->assertSame(2, $this->remaining($a1));
        $this->assertSame(5, $this->remaining($a2));
        $this->assertSame(4, $this->remaining($b1));

        // Expiry afterwards must not touch it.
        $this->travel(31)->minutes();
        $this->assertSame(0, $this->reservations()->expireDue());
        $this->assertSame(3, StockMovement::query()->where('reservation_id', $reservation->id)->where('type', MovementType::Released)->count());
        $this->assertStockReconciles();
    }

    public function test_release_twice_directly_is_idempotent(): void
    {
        [$reservation, $a1, $a2, $b1] = $this->spanningReservation();
        $stock = app(StockService::class);

        DB::transaction(fn () => $stock->release($reservation, null, 'first'));
        DB::transaction(fn () => $stock->release($reservation, null, 'second'));

        $this->assertSame(3, StockMovement::query()->where('reservation_id', $reservation->id)->where('type', MovementType::Released)->count());
        $this->assertSame(0, StockMovement::query()->where('reason', 'second')->count());
        $this->assertSame(2, $this->remaining($a1));
        $this->assertSame(5, $this->remaining($a2));
        $this->assertSame(4, $this->remaining($b1));
        $this->assertStockReconciles();
    }

    public function test_released_stock_is_allocated_again_oldest_first(): void
    {
        [$reservation, $a1] = $this->spanningReservation();
        $itemA = $a1->item_id;

        $this->reservations()->cancelUnpaid($reservation, 'changed mind');

        $again = $this->reservations()->create(User::factory()->create(), [$itemA => 1], true);
        $line = $again->lines()->sole();

        $this->assertSame($a1->id, $line->batch_id);
        $this->assertSame('400.00', $line->unit_price);
        $this->assertSame(1, $this->remaining($a1));
        $this->assertStockReconciles();
    }
}
