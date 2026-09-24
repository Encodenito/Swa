<?php

namespace App\Http\Presenters;

use App\Models\Item;

/**
 * Shapes items for Inertia props. Expects Item::withAvailable() for lists.
 */
final class ItemPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function card(Item $item): array
    {
        $available = $item->availableQuantity();

        return [
            'id' => $item->id,
            'slug' => $item->slug,
            'name' => $item->name,
            'brand' => $item->brand,
            'model' => $item->model,
            'category' => $item->relationLoaded('category') ? $item->category?->name : null,
            'image_url' => $item->imageUrl(),
            'price' => $item->price,
            'current_price' => $item->currentPrice(),
            'clearance' => $item->isOnClearance(),
            'available' => $available,
            'low_stock' => $available > 0 && $available <= $item->low_stock_threshold,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Item $item): array
    {
        return self::card($item) + [
            'sku' => $item->sku,
            'description' => $item->description,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function admin(Item $item): array
    {
        return self::card($item) + [
            'sku' => $item->sku,
            'description' => $item->description,
            'category_id' => $item->category_id,
            'low_stock_threshold' => $item->low_stock_threshold,
            'active' => $item->active,
            'superseded_by_id' => $item->superseded_by_id,
            'deleted_at' => $item->deleted_at?->toIso8601String(),
        ];
    }
}
