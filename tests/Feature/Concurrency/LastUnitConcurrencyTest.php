<?php

namespace Tests\Feature\Concurrency;

use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

/**
 * Real concurrency: two separate PHP processes (separate DB connections) race for the last unit.
 * Uses committed data (DatabaseTruncation), because RefreshDatabase's transaction is invisible to
 * other connections.
 */
class LastUnitConcurrencyTest extends TestCase
{
    use BuildsStock, DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        // DatabaseTruncation only cleans before a test. Clean after, too, so committed rows never
        // leak into later RefreshDatabase tests.
        $this->beforeApplicationDestroyed(function () {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            foreach (['stock_movements', 'payments', 'reservation_items', 'reservations', 'stock_batches', 'delivery_items', 'deliveries', 'items', 'categories', 'suppliers', 'users', 'settings'] as $table) {
                DB::table($table)->truncate();
            }
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        });
    }

    public function test_two_customers_cannot_both_take_the_last_unit(): void
    {
        $item = Item::factory()->create(['price' => '999.00']);
        $this->stockBatch($item, 1);
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        for ($round = 0; $round < 3; $round++) {
            $startAt = sprintf('%.4F', microtime(true) + 2.0);
            $env = [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => config('database.default'),
                'DB_DATABASE' => config('database.connections.'.config('database.default').'.database'),
                'CACHE_STORE' => 'array',
            ];

            $script = base_path('tests/Concurrency/reserve.php');
            $a = Process::env($env)->start([PHP_BINARY, $script, $alice->id, $item->id, 1, $startAt]);
            $b = Process::env($env)->start([PHP_BINARY, $script, $bob->id, $item->id, 1, $startAt]);

            $outputs = [trim($a->wait()->output()), trim($b->wait()->output())];
            $wins = array_values(array_filter($outputs, fn ($o) => str_starts_with($o, 'OK ')));

            $this->assertCount(1, $wins, 'Expected exactly one winner, got: '.implode(' | ', $outputs));
            $this->assertSame(0, $item->fresh()->availableQuantity());
            $this->assertStockReconciles();

            // Put the unit back for the next round.
            $this->resetStock($item);
        }
    }

    private function resetStock(Item $item): void
    {
        DB::table('stock_movements')->delete();
        DB::table('reservation_items')->delete();
        DB::table('reservations')->delete();
        DB::table('stock_batches')->delete();
        $this->stockBatch($item, 1);
    }
}
