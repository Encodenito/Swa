<?php

namespace Tests\Concerns;

use App\Models\Item;
use App\Models\StockBatch;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;

trait BuildsStock
{
    /**
     * Add a batch through the ledger (never insert batches directly).
     */
    protected function stockBatch(Item $item, int $quantity, ?string $salePrice = null, ?string $receivedAt = null): StockBatch
    {
        return app(StockService::class)->addBatch($item, $quantity, $salePrice, $receivedAt ? now()->parse($receivedAt) : now());
    }

    /**
     * Ledger invariant: batches hold exactly what the movements say, per item.
     */
    protected function assertStockReconciles(): void
    {
        $batches = DB::table('stock_batches')->selectRaw('item_id, SUM(quantity_remaining) q')->groupBy('item_id')->pluck('q', 'item_id');
        $moves = DB::table('stock_movements')->selectRaw('item_id, SUM(quantity) q')->groupBy('item_id')->pluck('q', 'item_id');

        $this->assertEquals($moves->map('intval')->sortKeys()->all(), $batches->map('intval')->sortKeys()->all(), 'Stock batches do not match the movement ledger.');
    }
}
