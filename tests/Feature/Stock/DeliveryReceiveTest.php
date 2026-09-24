<?php

namespace Tests\Feature\Stock;

use App\Enums\DeliveryStatus;
use App\Enums\MovementType;
use App\Exceptions\StateConflictException;
use App\Models\Delivery;
use App\Models\Item;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DeliveryService;
use App\Services\ReservationService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

class DeliveryReceiveTest extends TestCase
{
    use BuildsStock, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->travelTo(now()->parse('2026-09-24 10:00:00'));
    }

    private function deliveries(): DeliveryService
    {
        return app(DeliveryService::class);
    }

    /**
     * @return array{Delivery, Item, Item, Item, User}
     */
    private function orderedDelivery(): array
    {
        $staff = User::factory()->staff()->create();
        $laptop = Item::factory()->create(['price' => '30000.00']);
        $mouse = Item::factory()->create(['price' => '450.00']);
        $cable = Item::factory()->create(['price' => '150.00']);

        $delivery = $this->deliveries()->create(Supplier::factory()->create()->id, 'PO-001', '2026-09-30', [
            ['item_id' => $laptop->id, 'quantity_ordered' => 5, 'unit_cost' => '25000.00', 'sale_price' => '27999.00'],
            ['item_id' => $mouse->id, 'quantity_ordered' => 10, 'unit_cost' => '300.00', 'sale_price' => null],
            ['item_id' => $cable->id, 'quantity_ordered' => 20, 'unit_cost' => '80.00'],
        ], $staff);

        return [$delivery, $laptop, $mouse, $cable, $staff];
    }

    public function test_receive_creates_one_batch_and_movement_per_line_with_quantity(): void
    {
        [$delivery, $laptop, $mouse, $cable, $staff] = $this->orderedDelivery();
        $lines = $delivery->lines()->get()->keyBy('item_id');

        $this->deliveries()->receive($delivery, [
            $lines[$laptop->id]->id => 4,
            $lines[$mouse->id]->id => 10,
            $lines[$cable->id]->id => 0,
        ], $staff);

        $this->assertSame(DeliveryStatus::Received, $delivery->status);
        $this->assertSame($staff->id, $delivery->received_by);
        $this->assertTrue($delivery->received_at->equalTo(now()));

        $this->assertSame(2, StockBatch::query()->count());

        $laptopBatch = StockBatch::query()->where('item_id', $laptop->id)->sole();
        $this->assertSame(4, $laptopBatch->quantity_remaining);
        $this->assertSame('27999.00', $laptopBatch->sale_price);
        $this->assertSame($lines[$laptop->id]->id, $laptopBatch->delivery_item_id);
        $this->assertTrue($laptopBatch->received_at->equalTo(now()));

        $mouseBatch = StockBatch::query()->where('item_id', $mouse->id)->sole();
        $this->assertSame(10, $mouseBatch->quantity_remaining);
        $this->assertNull($mouseBatch->sale_price);

        $this->assertSame(0, StockBatch::query()->where('item_id', $cable->id)->count());
        $this->assertSame(0, StockMovement::query()->where('item_id', $cable->id)->count());

        $movements = StockMovement::query()->orderBy('id')->get();
        $this->assertCount(2, $movements);
        foreach ($movements as $movement) {
            $this->assertSame(MovementType::Received, $movement->type);
            $this->assertSame($staff->id, $movement->created_by);
        }
        $this->assertSame(4, StockMovement::query()->where('batch_id', $laptopBatch->id)->sole()->quantity);
        $this->assertSame(10, StockMovement::query()->where('batch_id', $mouseBatch->id)->sole()->quantity);

        $this->assertSame(4, $lines[$laptop->id]->fresh()->quantity_received);
        $this->assertSame(10, $lines[$mouse->id]->fresh()->quantity_received);
        $this->assertSame(0, $lines[$cable->id]->fresh()->quantity_received);

        $this->assertStockReconciles();
    }

    public function test_receiving_twice_throws_and_creates_no_extra_batches(): void
    {
        [$delivery, $laptop, , , $staff] = $this->orderedDelivery();
        $laptopLine = $delivery->lines()->where('item_id', $laptop->id)->sole();

        $this->deliveries()->receive($delivery, [$laptopLine->id => 5], $staff);
        $batchesAfterFirst = StockBatch::query()->count();
        $movementsAfterFirst = StockMovement::query()->count();

        $this->assertThrows(
            fn () => $this->deliveries()->receive(Delivery::query()->findOrFail($delivery->id), [$laptopLine->id => 5], $staff),
            StateConflictException::class,
        );

        $this->assertSame($batchesAfterFirst, StockBatch::query()->count());
        $this->assertSame($movementsAfterFirst, StockMovement::query()->count());
        $this->assertSame(5, (int) StockBatch::query()->where('item_id', $laptop->id)->sum('quantity_remaining'));
        $this->assertSame(5, $laptopLine->fresh()->quantity_received);
        $this->assertStockReconciles();
    }

    public function test_receiving_all_zero_is_rejected_and_delivery_stays_ordered(): void
    {
        [$delivery, , , , $staff] = $this->orderedDelivery();
        $ids = $delivery->lines()->pluck('id');

        $this->assertThrows(
            fn () => $this->deliveries()->receive($delivery, $ids->mapWithKeys(fn ($id) => [$id => 0])->all(), $staff),
            ValidationException::class,
        );

        $this->assertSame(DeliveryStatus::Ordered, $delivery->fresh()->status);
        $this->assertSame(0, StockBatch::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertStockReconciles();
    }

    public function test_received_clearance_batch_is_sold_after_older_stock_at_its_sale_price(): void
    {
        [$delivery, $laptop, , , $staff] = $this->orderedDelivery();
        $older = $this->stockBatch($laptop, 1, null, '2026-09-01 09:00:00');
        $laptopLine = $delivery->lines()->where('item_id', $laptop->id)->sole();

        $this->deliveries()->receive($delivery, [$laptopLine->id => 3], $staff);
        $received = StockBatch::query()->where('delivery_item_id', $laptopLine->id)->sole();

        $reservation = app(ReservationService::class)->create(User::factory()->create(), [$laptop->id => 2], true);
        $lines = $reservation->lines()->orderBy('id')->get();

        $this->assertSame([$older->id, $received->id], $lines->pluck('batch_id')->all());
        $this->assertSame(['30000.00', '27999.00'], $lines->pluck('unit_price')->all());
        $this->assertSame('57999.00', $reservation->fresh()->total);
        $this->assertStockReconciles();
    }
}
