<?php

namespace Tests\Feature\Admin;

use App\Enums\MovementType;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\User;
use App\Services\ReportService;
use App\Services\ReservationService;
use App\Services\StockService;
use App\Services\WalkInService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\MakesReservations;
use Tests\TestCase;

class ReportsAndAuditTest extends TestCase
{
    use MakesReservations, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);
        Notification::fake();
        Http::fake(['api.paymongo.com/v1/refunds' => Http::response(['data' => ['id' => 'ref_r']])]);
        $this->admin = User::factory()->admin()->create();
        $this->travelTo('2026-09-24 10:00:00');
    }

    public function test_sales_per_day_counts_pickups_and_walk_ins_but_not_voids(): void
    {
        $staff = User::factory()->staff()->create();
        $reservation = $this->confirmedReservation(); // total 1000.00
        app(ReservationService::class)->complete($reservation, '700.00', $staff);

        $item = Item::factory()->create(['price' => '250.00']);
        $this->stockBatch($item, 5);
        $walkIns = app(WalkInService::class);
        $walkIns->sell([$item->id => 2], null, null, $staff);                // 500.00
        $voided = $walkIns->sell([$item->id => 1], null, null, $staff);     // 250.00, voided below
        $walkIns->void($voided, 'Rang up twice', $this->admin);

        $rows = app(ReportService::class)->salesPerDay(now()->subDay(), now());

        $this->assertCount(1, $rows);
        $this->assertSame('1000.00', $rows[0]['reservations']);
        $this->assertSame('500.00', $rows[0]['walk_ins']);
        $this->assertSame('1500.00', $rows[0]['total']);
        $this->assertSame(2, $rows[0]['count']);
    }

    public function test_forfeited_report_shows_what_the_shop_kept(): void
    {
        $reservation = $this->confirmedReservation(); // downpayment 300.00
        $this->travelTo('2026-09-28 01:00:00');
        $this->artisan('reservations:forfeit');

        $report = app(ReportService::class)->forfeitedKept(now()->subDay(), now());

        $this->assertSame($reservation->reference_code, $report['rows'][0]['reference_code']);
        $this->assertSame('300.00', $report['rows'][0]['paid']);
        $this->assertSame('150.00', $report['rows'][0]['refunded']);
        $this->assertSame('150.00', $report['rows'][0]['kept']);
        $this->assertSame('150.00', $report['kept_total']);
    }

    public function test_slow_moving_items_are_in_stock_without_recent_sales(): void
    {
        $slow = Item::factory()->create(['name' => 'Old Scanner']);
        $this->stockBatch($slow, 2);
        $recent = $this->pendingReservation(); // its item sold just now

        $rows = app(ReportService::class)->slowMoving(30);

        $this->assertSame(['Old Scanner'], array_column($rows, 'name'));
        $this->assertNull($rows[0]['last_sale_at']);
        $this->assertNotEmpty($recent->lines);
    }

    public function test_reports_page_is_admin_only(): void
    {
        $this->actingAs(User::factory()->staff()->create())->get(route('admin.reports'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.reports'))->assertOk();
    }

    public function test_sensitive_actions_are_audit_logged(): void
    {
        $item = Item::factory()->create(['price' => '100.00']);
        $batch = $this->stockBatch($item, 5);
        $reservation = $this->confirmedReservation();

        $this->actingAs($this->admin)->post(route('admin.items.update', $item->slug), [
            'category_id' => $item->category_id, 'name' => $item->name, 'price' => '120.00', 'low_stock_threshold' => 1, 'active' => '1',
        ]);
        $this->actingAs($this->admin)->put(route('admin.batches.price', $batch), ['sale_price' => '90.00']);
        app(StockService::class)->adjust($batch->fresh(), -1, MovementType::Damaged, 'Dropped', $this->admin);
        $this->actingAs($this->admin)->post(route('staff.reservations.extend', $reservation), ['pickup_by' => '2026-10-01', 'reason' => 'Out of town']);
        $this->actingAs($this->admin)->put(route('admin.settings.update'), [
            'downpayment_percent' => 40, 'pickup_days' => 3, 'forfeit_refund_percent' => 50,
            'pending_minutes' => 30, 'max_unpaid_reservations' => 2, 'max_qty_per_item' => 10,
        ]);
        $this->actingAs($this->admin)->post(route('staff.reservations.cancel', $reservation), ['refund_percent' => 100, 'reason' => 'Defective', 'expected_status' => $reservation->fresh()->status->value]);

        $actions = AuditLog::orderBy('id')->pluck('action')->all();

        foreach (['item.price_changed', 'batch.clearance_changed', 'stock.adjusted', 'reservation.deadline_extended', 'settings.changed', 'refund.created', 'reservation.cancelled', 'refund.sent'] as $expected) {
            $this->assertContains($expected, $actions, "Missing audit entry {$expected}");
        }

        $price = AuditLog::where('action', 'item.price_changed')->sole();
        $this->assertSame(['price' => '100.00'], $price->before);
        $this->assertSame(['price' => '120.00'], $price->after);
        $this->assertSame($this->admin->id, $price->user_id);

        $this->actingAs($this->admin)->get(route('admin.audit-log', ['action' => 'reservation.cancelled']))->assertOk();
    }

    public function test_unchanged_price_or_settings_write_no_audit_entry(): void
    {
        $item = Item::factory()->create(['price' => '100.00']);

        $this->actingAs($this->admin)->post(route('admin.items.update', $item->slug), [
            'category_id' => $item->category_id, 'name' => 'Renamed only', 'price' => '100', 'low_stock_threshold' => 1, 'active' => '1',
        ]);

        $this->assertSame(0, AuditLog::where('action', 'item.price_changed')->count());
    }

    public function test_the_audit_log_is_admin_only(): void
    {
        $this->actingAs(User::factory()->staff()->create())->get(route('admin.audit-log'))->assertForbidden();
    }
}
