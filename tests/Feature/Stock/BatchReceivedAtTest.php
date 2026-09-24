<?php

namespace Tests\Feature\Stock;

use App\Models\Item;
use App\Models\User;
use App\Services\ReservationService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

/**
 * Regression: the database itself must never rewrite received_at when a batch's quantity changes.
 * Uses the real clock on purpose; travelTo() would hide a database-side NOW().
 */
class BatchReceivedAtTest extends TestCase
{
    use BuildsStock, RefreshDatabase;

    public function test_received_at_survives_quantity_changes(): void
    {
        $this->seed(SettingsSeeder::class);
        $item = Item::factory()->create();
        $old = $this->stockBatch($item, 3, null, '2026-01-01 09:00:00');
        $new = $this->stockBatch($item, 3, null, '2026-06-01 09:00:00');

        app(ReservationService::class)->create(User::factory()->create(), [$item->id => 1], true);

        $this->assertSame('2026-01-01 09:00:00', (string) DB::table('stock_batches')->where('id', $old->id)->value('received_at'));

        // The older batch still sells first on the next reservation.
        $second = app(ReservationService::class)->create(User::factory()->create(), [$item->id => 1], true);
        $this->assertSame($old->id, $second->lines()->sole()->batch_id);
        $this->assertSame(3, $new->fresh()->quantity_remaining);
    }

    public function test_no_business_timestamp_auto_updates(): void
    {
        $autoUpdating = DB::table('information_schema.columns')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->where('extra', 'like', '%on update%')
            ->get(['table_name', 'column_name'])
            ->map(fn ($c) => "{$c->table_name}.{$c->column_name}")
            ->all();

        $this->assertSame([], $autoUpdating, 'Columns with ON UPDATE CURRENT_TIMESTAMP: '.implode(', ', $autoUpdating));
    }
}
