<?php

namespace Tests\Feature\Stock;

use App\Enums\MovementType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\ReservationStatus;
use App\Enums\ReservationType;
use App\Exceptions\StateConflictException;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\WalkInService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\MakesReservations;
use Tests\TestCase;

class WalkInVoidTest extends TestCase
{
    use MakesReservations, RefreshDatabase;

    private User $staff;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
        $this->seed(SettingsSeeder::class);
        $this->travelTo(now()->parse('2026-09-24 10:00:00'));
        $this->staff = User::factory()->staff()->create();
        $this->admin = User::factory()->admin()->create();
    }

    private function walkIns(): WalkInService
    {
        return app(WalkInService::class);
    }

    private function remaining(StockBatch $batch): int
    {
        return (int) StockBatch::query()->whereKey($batch->id)->value('quantity_remaining');
    }

    /**
     * A sale spanning two batches of one item plus one batch of another.
     *
     * @return array{Reservation, StockBatch, StockBatch, StockBatch}
     */
    private function spanningSale(): array
    {
        $a = Item::factory()->create(['price' => '1000.00']);
        $b = Item::factory()->create(['price' => '300.00']);
        $a1 = $this->stockBatch($a, 2, '800.00', '2026-09-01 08:00:00');
        $a2 = $this->stockBatch($a, 5, null, '2026-09-10 08:00:00');
        $b1 = $this->stockBatch($b, 4, null, '2026-09-05 08:00:00');

        $sale = $this->walkIns()->sell([$a->id => 3, $b->id => 2], 'Walk-in', null, $this->staff);

        $this->assertSame(0, $this->remaining($a1));
        $this->assertSame(4, $this->remaining($a2));
        $this->assertSame(2, $this->remaining($b1));

        return [$sale, $a1, $a2, $b1];
    }

    public function test_void_returns_each_line_to_its_exact_batch_with_adjusted_movements(): void
    {
        [$sale, $a1, $a2, $b1] = $this->spanningSale();

        $this->walkIns()->void($sale, 'Wrong item rung up', $this->admin);

        $this->assertSame(2, $this->remaining($a1));
        $this->assertSame(5, $this->remaining($a2));
        $this->assertSame(4, $this->remaining($b1));

        $adjusted = StockMovement::query()->where('reservation_id', $sale->id)->where('type', MovementType::Adjusted)->get();
        $this->assertCount($sale->lines()->count(), $adjusted);

        foreach ($sale->lines()->get() as $line) {
            $movement = $adjusted->firstWhere('batch_id', $line->batch_id);
            $this->assertNotNull($movement, "No adjusted movement for batch {$line->batch_id}.");
            $this->assertSame($line->quantity, (int) $movement->quantity);
            $this->assertGreaterThan(0, (int) $movement->quantity);
            $this->assertStringContainsString('Wrong item rung up', (string) $movement->reason);
            $this->assertSame($this->admin->id, $movement->created_by);
        }

        $this->assertSame(0, StockMovement::query()->where('reservation_id', $sale->id)->where('type', MovementType::Released)->count());

        $this->assertStockReconciles();
    }

    public function test_void_sets_status_reason_refund_and_audit_log(): void
    {
        [$sale] = $this->spanningSale();
        $full = Payment::query()->where('reservation_id', $sale->id)->where('type', PaymentType::Full)->sole();

        $this->walkIns()->void($sale, 'Customer changed mind', $this->admin);
        $sale->refresh();

        $this->assertSame(ReservationStatus::Voided, $sale->status);
        $this->assertSame('Customer changed mind', $sale->cancel_reason);
        $this->assertSame($this->admin->id, $sale->cancelled_by);
        $this->assertNotNull($sale->cancelled_at);

        $refunds = Payment::query()->where('reservation_id', $sale->id)->where('type', PaymentType::Refund)->get();
        $this->assertCount(1, $refunds);
        $refund = $refunds->first();
        $this->assertSame($full->id, $refund->parent_id);
        $this->assertSame(PaymentStatus::Refunded, $refund->status);
        $this->assertSame(PaymentMethod::Cash, $refund->method);
        $this->assertSame((string) $sale->total, (string) $refund->amount);
        $this->assertSame('3200.00', (string) $refund->amount); // 800x2 + 1000 + 300x2

        $this->assertSame(1, DB::table('audit_logs')
            ->where('action', 'walk_in.voided')
            ->where('subject_id', (string) $sale->id)
            ->where('user_id', $this->admin->id)
            ->count());

        $this->assertStockReconciles();
    }

    public function test_voiding_twice_conflicts_and_does_not_return_stock_twice(): void
    {
        [$sale, $a1, $a2, $b1] = $this->spanningSale();

        $this->walkIns()->void($sale, 'First', $this->admin);

        try {
            $this->walkIns()->void($sale, 'Second', $this->admin);
            $this->fail('Expected StateConflictException.');
        } catch (StateConflictException) {
            // expected
        }

        $this->assertSame(2, $this->remaining($a1));
        $this->assertSame(5, $this->remaining($a2));
        $this->assertSame(4, $this->remaining($b1));
        $this->assertSame(3, StockMovement::query()->where('reservation_id', $sale->id)->where('type', MovementType::Adjusted)->count());
        $this->assertSame(1, Payment::query()->where('reservation_id', $sale->id)->where('type', PaymentType::Refund)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'walk_in.voided')->count());
        $this->assertSame('First', $sale->fresh()->cancel_reason);

        $this->assertStockReconciles();
    }

    public function test_voiding_a_normal_reservation_throws(): void
    {
        $reservation = $this->pendingReservation();
        $this->assertSame(ReservationType::Reservation, $reservation->type);
        $movementsBefore = StockMovement::count();

        $this->expectException(StateConflictException::class);

        try {
            $this->walkIns()->void($reservation, 'Nope', $this->admin);
        } finally {
            $this->assertSame(ReservationStatus::PendingPayment, $reservation->fresh()->status);
            $this->assertSame($movementsBefore, StockMovement::count());
            $this->assertStockReconciles();
        }
    }

    public function test_staff_cannot_void_over_http_but_admin_can(): void
    {
        [$sale, $a1] = $this->spanningSale();

        $this->actingAs($this->staff)
            ->post(route('admin.walk-ins.void', $sale), ['reason' => 'Staff tries'])
            ->assertForbidden();

        $this->assertSame(ReservationStatus::Completed, $sale->fresh()->status);
        $this->assertSame(0, $this->remaining($a1));

        $this->actingAs($this->admin)
            ->from(route('staff.walk-ins.show', $sale))
            ->post(route('admin.walk-ins.void', $sale), ['reason' => 'Admin voids'])
            ->assertRedirect(route('staff.walk-ins.show', $sale))
            ->assertSessionHas('success');

        $sale->refresh();
        $this->assertSame(ReservationStatus::Voided, $sale->status);
        $this->assertSame('Admin voids', $sale->cancel_reason);
        $this->assertSame(2, $this->remaining($a1));

        $this->assertStockReconciles();
    }

    public function test_staff_can_record_a_sale_over_http(): void
    {
        $item = Item::factory()->create(['price' => '450.00']);
        $batch = $this->stockBatch($item, 3);

        $response = $this->actingAs($this->staff)->post(route('staff.walk-ins.store'), [
            'lines' => [['item_id' => $item->id, 'quantity' => 2]],
            'walk_in_name' => 'Pedro',
            'total' => '1.00', // ignored: the server prices the sale
        ]);

        $sale = Reservation::query()->where('type', ReservationType::WalkIn)->sole();
        $response->assertRedirect(route('staff.walk-ins.show', $sale));

        $this->assertSame('900.00', (string) $sale->total);
        $this->assertSame(1, $this->remaining($batch));

        $this->assertStockReconciles();
    }

    public function test_customer_gets_403_on_walk_in_routes(): void
    {
        [$sale] = $this->spanningSale();
        $customer = User::factory()->create();
        $item = Item::factory()->create();
        $this->stockBatch($item, 3);

        $this->actingAs($customer)
            ->post(route('staff.walk-ins.store'), ['lines' => [['item_id' => $item->id, 'quantity' => 1]]])
            ->assertForbidden();

        $this->actingAs($customer)
            ->post(route('admin.walk-ins.void', $sale), ['reason' => 'Customer tries'])
            ->assertForbidden();

        $this->assertSame(1, Reservation::count());
        $this->assertSame(ReservationStatus::Completed, $sale->fresh()->status);

        $this->assertStockReconciles();
    }
}
