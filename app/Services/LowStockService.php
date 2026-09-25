<?php

namespace App\Services;

use App\Models\Item;

/**
 * Items at or below their low-stock threshold, in one aggregate query.
 */
class LowStockService
{
    /**
     * Active items with available <= low_stock_threshold (out of stock included), lowest first.
     *
     * @return list<array{id: int, name: string, slug: string, sku: string|null, available: int, threshold: int}>
     */
    public function items(int $limit = 200): array
    {
        $available = '(select coalesce(sum(stock_batches.quantity_remaining), 0) from stock_batches where stock_batches.item_id = items.id)';

        return Item::query()
            ->where('active', true)
            ->whereNull('superseded_by_id')
            ->select(['id', 'name', 'slug', 'sku', 'low_stock_threshold'])
            ->selectRaw("{$available} as available")
            ->whereRaw("{$available} <= low_stock_threshold")
            ->orderByRaw($available)
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Item $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'slug' => $item->slug,
                'sku' => $item->sku,
                'available' => (int) $item->available,
                'threshold' => $item->low_stock_threshold,
            ])
            ->all();
    }
}
