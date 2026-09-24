<?php

namespace Tests\Feature\Stock;

use App\Enums\MovementType;
use App\Enums\ReservationStatus;
use App\Enums\SettingKey;
use App\Exceptions\StateConflictException;
use App\Models\Item;
use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ReservationService;
use App\Services\SettingsService;
use App\Services\StockService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

class StockAllocationTest extends TestCase
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

    public function test_allocates_oldest_received_batch_first_regardless_of_id(): void
    {
        $item = Item::factory()->create(['price' => '100.00']);
        // Created first (lower id) but received later.
        $newer = $this->stockBatch($item, 5, null, '2026-09-20 09:00:00');
        $older = $this->stockBatch($item, 5, null, '2026-09-10 09:00:00');

        $reservation = $this->reservations()->create(User::factory()->create(), [$item->id => 3], true);

        $lines = $reservation->lines()->get();
        $this->assertCount(1, $lines);
        $this->assertSame($older->id, $lines[0]->batch_id);
        $this->assertSame(2, $this->remaining($older));
        $this->assertSame(5, $this->remaining($newer));
        $this->assertStockReconciles();
    }

    public function test_same_received_at_uses_lower_id_first(): void
    {
        $item = Item::factory()->create(['price' => '100.00']);
        $first = $this->stockBatch($item, 2, null, '2026-09-10 09:00:00');
        $second = $this->stockBatch($item, 2, null, '2026-09-10 09:00:00');
        $this->assertLessThan($second->id, $first->id);

        $reservation = $this->reservations()->create(User::factory()->create(), [$item->id => 3], true);

        $lines = $reservation->lines()->orderBy('id')->get();
        $this->assertSame([$first->id, $second->id], $lines->pluck('batch_id')->all());
        $this->assertSame([2, 1], $lines->pluck('quantity')->all());
        $this->assertSame(0, $this->remaining($first));
        $this->assertSame(1, $this->remaining($second));
        $this->assertStockReconciles();
    }

    public function test_spanning_order_creates_one_line_per_batch_with_clearance_price_and_correct_totals(): void
    {
        $item = Item::factory()->create(['price' => '7.30']);
        $old = $this->stockBatch($item, 1, '5.05', '2026-09-01 09:00:00');
        $new = $this->stockBatch($item, 4, null, '2026-09-15 09:00:00');

        $reservation = $this->reservations()->create(User::factory()->create(), [$item->id => 2], true);
        $reservation->refresh();

        $lines = $reservation->lines()->orderBy('id')->get();
        $this->assertCount(2, $lines);

        $this->assertSame($old->id, $lines[0]->batch_id);
        $this->assertSame(1, $lines[0]->quantity);
        $this->assertSame('5.05', $lines[0]->unit_price);

        $this->assertSame($new->id, $lines[1]->batch_id);
        $this->assertSame(1, $lines[1]->quantity);
        $this->assertSame('7.30', $lines[1]->unit_price);

        // 5.05 + 7.30 = 12.35; 30% = 3.705 -> 3.71 (half up); balance 8.64.
        $this->assertSame('12.35', $reservation->total);
        $this->assertSame('3.71', $reservation->downpayment_amount);
        $this->assertSame('8.64', $reservation->balance);
        $this->assertSame(ReservationStatus::PendingPayment, $reservation->status);

        $this->assertSame(
            [[$old->id, -1], [$new->id, -1]],
            StockMovement::query()->where('reservation_id', $reservation->id)->where('type', MovementType::Reserved)
                ->orderBy('id')->get()->map(fn ($m) => [$m->batch_id, $m->quantity])->all(),
        );
        $this->assertSame(0, $this->remaining($old));
        $this->assertSame(3, $this->remaining($new));
        $this->assertStockReconciles();
    }

    public function test_total_equals_sum_of_lines_for_multiple_items(): void
    {
        $a = Item::factory()->create(['price' => '199.99']);
        $b = Item::factory()->create(['price' => '1000.00']);
        $this->stockBatch($a, 2, '149.50', '2026-09-01 09:00:00');
        $this->stockBatch($a, 5, null, '2026-09-02 09:00:00');
        $this->stockBatch($b, 3, null, '2026-09-02 09:00:00');

        $reservation = $this->reservations()->create(User::factory()->create(), [$b->id => 1, $a->id => 4], true);
        $reservation->refresh();

        $lines = $reservation->lines()->get();
        $this->assertCount(3, $lines);

        $sumCents = $lines->sum(fn (ReservationItem $l) => $l->quantity * (int) str_replace('.', '', $l->unit_price));
        // 2*149.50 + 2*199.99 + 1*1000.00 = 1698.98
        $this->assertSame(169898, $sumCents);
        $this->assertSame('1698.98', $reservation->total);
        // 30% of 1698.98 = 509.694 -> 509.69
        $this->assertSame('509.69', $reservation->downpayment_amount);
        $this->assertSame('1189.29', $reservation->balance);
        $this->assertStockReconciles();
    }

    public function test_insufficient_stock_leaves_nothing_behind(): void
    {
        $a = Item::factory()->create();
        $b = Item::factory()->create();
        $batchA = $this->stockBatch($a, 5);
        $batchB = $this->stockBatch($b, 1);
        $movementsBefore = StockMovement::query()->count();

        try {
            // Item a is allocated first (lower id) and would succeed; item b fails -> whole thing rolls back.
            $this->reservations()->create(User::factory()->create(), [$a->id => 2, $b->id => 2], true);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cart', $e->errors());
        }

        $this->assertSame(0, Reservation::query()->count());
        $this->assertSame(0, ReservationItem::query()->count());
        $this->assertSame($movementsBefore, StockMovement::query()->count());
        $this->assertSame(0, StockMovement::query()->where('type', MovementType::Reserved)->count());
        $this->assertSame(5, $this->remaining($batchA));
        $this->assertSame(1, $this->remaining($batchB));
        $this->assertStockReconciles();
    }

    public function test_last_unit_sequential_second_customer_fails_and_stock_never_negative(): void
    {
        $item = Item::factory()->create();
        $batch = $this->stockBatch($item, 1);

        $first = $this->reservations()->create(User::factory()->create(), [$item->id => 1], true);
        $this->assertSame(1, (int) $first->lines()->sum('quantity'));
        $this->assertSame(0, $this->remaining($batch));

        try {
            $this->reservations()->create(User::factory()->create(), [$item->id => 1], true);
            $this->fail('Expected ValidationException for the second customer');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cart', $e->errors());
        }

        $this->assertSame(1, Reservation::query()->count());
        $this->assertSame(0, $this->remaining($batch));
        $this->assertSame(0, StockBatch::query()->where('quantity_remaining', '<', 0)->count());
        $this->assertSame(0, (int) StockMovement::query()->where('item_id', $item->id)->sum('quantity'));
        $this->assertStockReconciles();
    }

    public function test_inactive_item_cannot_be_allocated(): void
    {
        $item = Item::factory()->inactive()->create();
        $batch = $this->stockBatch($item, 5);

        $this->expectValidationAndNothingReserved(fn () => $this->reservations()->create(User::factory()->create(), [$item->id => 1], true));

        $this->assertSame(5, $this->remaining($batch));
        $this->assertStockReconciles();
    }

    public function test_soft_deleted_item_cannot_be_allocated(): void
    {
        $item = Item::factory()->create();
        $batch = $this->stockBatch($item, 5);
        $item->delete();

        $this->expectValidationAndNothingReserved(fn () => $this->reservations()->create(User::factory()->create(), [$item->id => 1], true));

        $this->assertSame(5, $this->remaining($batch));
        $this->assertStockReconciles();
    }

    public function test_max_qty_per_item_limit(): void
    {
        $item = Item::factory()->create();
        $batch = $this->stockBatch($item, 20);

        $this->expectValidationAndNothingReserved(fn () => $this->reservations()->create(User::factory()->create(), [$item->id => 11], true));
        $this->assertSame(20, $this->remaining($batch));

        $reservation = $this->reservations()->create(User::factory()->create(), [$item->id => 10], true);
        $this->assertSame(10, (int) $reservation->lines()->sum('quantity'));
        $this->assertSame(10, $this->remaining($batch));
        $this->assertStockReconciles();
    }

    public function test_max_qty_per_item_follows_setting(): void
    {
        app(SettingsService::class)->set(SettingKey::MaxQtyPerItem, 3);
        $item = Item::factory()->create();
        $this->stockBatch($item, 20);

        $this->expectValidationAndNothingReserved(fn () => $this->reservations()->create(User::factory()->create(), [$item->id => 4], true));
        $this->assertStockReconciles();
    }

    public function test_max_unpaid_reservations_limit(): void
    {
        $item = Item::factory()->create();
        $batch = $this->stockBatch($item, 10);
        $customer = User::factory()->create();

        $this->reservations()->create($customer, [$item->id => 1], true);
        $this->reservations()->create($customer, [$item->id => 1], true);

        try {
            $this->reservations()->create($customer, [$item->id => 1], true);
            $this->fail('Expected ValidationException for third unpaid reservation');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cart', $e->errors());
        }

        $this->assertSame(2, Reservation::query()->where('user_id', $customer->id)->count());
        $this->assertSame(8, $this->remaining($batch));

        // A different customer is unaffected.
        $this->reservations()->create(User::factory()->create(), [$item->id => 1], true);
        $this->assertSame(7, $this->remaining($batch));

        // Once one expires, the first customer can reserve again.
        $this->travel(31)->minutes();
        $this->reservations()->expireDue();
        $this->reservations()->create($customer, [$item->id => 1], true);
        $this->assertSame(1, Reservation::query()->where('user_id', $customer->id)->where('status', ReservationStatus::PendingPayment)->count());
        $this->assertStockReconciles();
    }

    public function test_terms_must_be_accepted(): void
    {
        $item = Item::factory()->create();
        $this->stockBatch($item, 5);

        $this->expectValidationAndNothingReserved(fn () => $this->reservations()->create(User::factory()->create(), [$item->id => 1], false));
        $this->assertStockReconciles();
    }

    public function test_adjust_damaged_must_be_negative(): void
    {
        $item = Item::factory()->create();
        $batch = $this->stockBatch($item, 5);
        $admin = User::factory()->admin()->create();

        $this->assertThrows(fn () => app(StockService::class)->adjust($batch, 1, MovementType::Damaged, 'Broken', $admin), InvalidArgumentException::class);
        $this->assertThrows(fn () => app(StockService::class)->adjust($batch, 0, MovementType::Adjusted, 'Count', $admin), InvalidArgumentException::class);

        $movement = app(StockService::class)->adjust($batch, -2, MovementType::Damaged, 'Dropped in storage', $admin);

        $this->assertSame(MovementType::Damaged, $movement->type);
        $this->assertSame(-2, $movement->quantity);
        $this->assertSame('Dropped in storage', $movement->reason);
        $this->assertSame($admin->id, $movement->created_by);
        $this->assertSame(3, $this->remaining($batch));
        $this->assertStockReconciles();
    }

    public function test_adjust_requires_reason(): void
    {
        $item = Item::factory()->create();
        $batch = $this->stockBatch($item, 5);
        $admin = User::factory()->admin()->create();

        $this->assertThrows(fn () => app(StockService::class)->adjust($batch, -1, MovementType::Damaged, '   ', $admin), InvalidArgumentException::class);
        $this->assertThrows(fn () => app(StockService::class)->adjust($batch, -1, MovementType::Released, 'x', $admin), InvalidArgumentException::class);

        $this->assertSame(5, $this->remaining($batch));
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertStockReconciles();
    }

    public function test_adjust_cannot_go_below_zero(): void
    {
        $item = Item::factory()->create();
        $batch = $this->stockBatch($item, 2);
        $admin = User::factory()->admin()->create();

        $this->assertThrows(fn () => app(StockService::class)->adjust($batch, -3, MovementType::Damaged, 'Water damage', $admin), StateConflictException::class);
        $this->assertThrows(fn () => app(StockService::class)->adjust($batch, -3, MovementType::Adjusted, 'Recount', $admin), StateConflictException::class);

        $this->assertSame(2, $this->remaining($batch));
        $this->assertSame(1, StockMovement::query()->count());

        app(StockService::class)->adjust($batch, -2, MovementType::Adjusted, 'Recount', $admin);
        $this->assertSame(0, $this->remaining($batch));
        $this->assertStockReconciles();
    }

    public function test_adjusted_may_add_stock(): void
    {
        $item = Item::factory()->create();
        $batch = $this->stockBatch($item, 2);
        $admin = User::factory()->admin()->create();

        $movement = app(StockService::class)->adjust($batch, 4, MovementType::Adjusted, 'Found in back room', $admin);

        $this->assertSame(MovementType::Adjusted, $movement->type);
        $this->assertSame(4, $movement->quantity);
        $this->assertSame($batch->id, $movement->batch_id);
        $this->assertSame(6, $this->remaining($batch));
        $this->assertStockReconciles();
    }

    private function expectValidationAndNothingReserved(callable $callback): void
    {
        $reservationsBefore = Reservation::query()->count();
        $linesBefore = ReservationItem::query()->count();
        $movementsBefore = StockMovement::query()->count();

        try {
            $callback();
            $this->fail('Expected ValidationException');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame($reservationsBefore, Reservation::query()->count());
        $this->assertSame($linesBefore, ReservationItem::query()->count());
        $this->assertSame($movementsBefore, StockMovement::query()->count());
    }
}
