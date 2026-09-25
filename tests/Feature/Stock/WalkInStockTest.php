<?php

namespace Tests\Feature\Stock;

use App\Enums\MovementType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\ReservationStatus;
use App\Enums\ReservationType;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\WalkInService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

class WalkInStockTest extends TestCase
{
    use BuildsStock, RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
        $this->seed(SettingsSeeder::class);
        $this->travelTo(now()->parse('2026-09-24 10:00:00'));
        $this->staff = User::factory()->staff()->create();
    }

    private function walkIns(): WalkInService
    {
        return app(WalkInService::class);
    }

    private function remaining(StockBatch $batch): int
    {
        return (int) StockBatch::query()->whereKey($batch->id)->value('quantity_remaining');
    }

    public function test_sale_takes_oldest_batch_first_with_clearance_price_and_writes_sold_movements(): void
    {
        $item = Item::factory()->create(['price' => '1000.00']);
        // Created out of order: the newer batch has the lower id.
        $newer = $this->stockBatch($item, 5, null, '2026-09-20 08:00:00');
        $older = $this->stockBatch($item, 2, '800.00', '2026-09-01 08:00:00');

        $sale = $this->walkIns()->sell([$item->id => 3], 'Juan', '09171234567', $this->staff);

        $this->assertSame(0, $this->remaining($older));
        $this->assertSame(4, $this->remaining($newer));

        $lines = $sale->lines()->orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame($older->id, $lines[0]->batch_id);
        $this->assertSame(2, $lines[0]->quantity);
        $this->assertSame('800.00', (string) $lines[0]->unit_price);
        $this->assertSame($newer->id, $lines[1]->batch_id);
        $this->assertSame(1, $lines[1]->quantity);
        $this->assertSame('1000.00', (string) $lines[1]->unit_price);

        $movements = StockMovement::query()->where('reservation_id', $sale->id)->orderBy('id')->get();
        $this->assertCount(2, $movements);
        foreach ($movements as $movement) {
            $this->assertSame(MovementType::Sold, $movement->type);
            $this->assertSame($this->staff->id, $movement->created_by);
        }
        $this->assertSame([$older->id, $newer->id], $movements->pluck('batch_id')->all());
        $this->assertSame([-2, -1], $movements->pluck('quantity')->map('intval')->all());
        $this->assertSame(0, StockMovement::query()->where('reservation_id', $sale->id)->where('type', MovementType::Reserved)->count());

        $this->assertStockReconciles();
    }

    public function test_sale_reservation_fields_and_total_equal_sum_of_lines(): void
    {
        $a = Item::factory()->create(['price' => '1000.00']);
        $b = Item::factory()->create(['price' => '249.50']);
        $this->stockBatch($a, 1, '750.00', '2026-09-01 08:00:00');
        $this->stockBatch($a, 5, null, '2026-09-02 08:00:00');
        $this->stockBatch($b, 5, null, '2026-09-03 08:00:00');

        $sale = $this->walkIns()->sell([$b->id => 2, $a->id => 2], null, null, $this->staff);
        $sale->refresh();

        $this->assertSame(ReservationType::WalkIn, $sale->type);
        $this->assertSame(ReservationStatus::Completed, $sale->status);
        $this->assertNull($sale->user_id);
        $this->assertNotNull($sale->completed_at);
        $this->assertSame($this->staff->id, $sale->created_by);

        // 750 + 1000 + 2 x 249.50 = 2249.00
        $sum = $sale->lines()->get()->reduce(fn (int $c, ReservationItem $l) => $c + $l->quantity * (int) round((float) $l->unit_price * 100), 0);
        $this->assertSame(224900, $sum);
        $this->assertSame('2249.00', (string) $sale->total);
        $this->assertCount(3, $sale->lines()->get());

        $this->assertStockReconciles();
    }

    public function test_sale_has_exactly_one_full_cash_payment_for_the_total(): void
    {
        $item = Item::factory()->create(['price' => '1250.75']);
        $this->stockBatch($item, 5);

        $sale = $this->walkIns()->sell([$item->id => 2], 'Ana', null, $this->staff);
        $sale->refresh();

        $payments = Payment::query()->where('reservation_id', $sale->id)->get();
        $this->assertCount(1, $payments);
        $payment = $payments->first();
        $this->assertSame(PaymentType::Full, $payment->type);
        $this->assertSame(PaymentMethod::Cash, $payment->method);
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame('2501.50', (string) $payment->amount);
        $this->assertSame((string) $sale->total, (string) $payment->amount);

        $this->assertStockReconciles();
    }

    public function test_insufficient_stock_throws_validation_and_leaves_nothing_behind(): void
    {
        $a = Item::factory()->create();
        $b = Item::factory()->create();
        $batchA = $this->stockBatch($a, 5);
        $batchB = $this->stockBatch($b, 1);

        $counts = fn () => [
            Reservation::count(), ReservationItem::count(), Payment::count(), StockMovement::count(),
        ];
        $before = $counts();

        try {
            $this->walkIns()->sell([$a->id => 2, $b->id => 2], null, null, $this->staff);
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors());
        }

        $this->assertSame($before, $counts());
        $this->assertSame(5, $this->remaining($batchA));
        $this->assertSame(1, $this->remaining($batchB));

        $this->assertStockReconciles();
    }

    public function test_inactive_item_is_rejected(): void
    {
        $item = Item::factory()->create();
        $batch = $this->stockBatch($item, 5);
        $item->forceFill(['active' => false])->save();

        try {
            $this->walkIns()->sell([$item->id => 1], null, null, $this->staff);
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors());
        }

        $this->assertSame(0, Reservation::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(5, $this->remaining($batch));

        $this->assertStockReconciles();
    }
}
