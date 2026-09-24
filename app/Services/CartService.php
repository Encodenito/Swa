<?php

namespace App\Services;

use App\Enums\SettingKey;
use App\Models\Item;
use App\Models\StockBatch;
use App\Support\Money;
use Illuminate\Contracts\Session\Session;

/**
 * Session cart: item ids and quantities only. Prices shown on the review page are a server-side
 * preview of what allocation would charge now; the real prices are fixed by StockService::allocate.
 */
class CartService
{
    private const KEY = 'cart';

    public function __construct(
        private readonly Session $session,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return array<int, int> item_id => quantity
     */
    public function quantities(): array
    {
        return array_map('intval', (array) $this->session->get(self::KEY, []));
    }

    public function set(Item $item, int $quantity): void
    {
        $cart = $this->quantities();
        $max = $this->settings->int(SettingKey::MaxQtyPerItem);
        $quantity = max(0, min($quantity, $max));

        if ($quantity === 0) {
            unset($cart[$item->id]);
        } else {
            $cart[$item->id] = $quantity;
        }

        $this->session->put(self::KEY, $cart);
    }

    public function add(Item $item, int $quantity): void
    {
        $this->set($item, ($this->quantities()[$item->id] ?? 0) + $quantity);
    }

    public function clear(): void
    {
        $this->session->forget(self::KEY);
    }

    /**
     * Price preview: walks batches oldest first (no locks) exactly like allocation does.
     *
     * @return array{lines: list<array<string, mixed>>, total: string, downpayment: string, balance: string, problems: list<string>}
     */
    public function preview(): array
    {
        $quantities = $this->quantities();
        $items = Item::query()->withAvailable()->whereIn('id', array_keys($quantities))->get()->keyBy('id');
        $batches = StockBatch::query()
            ->whereIn('item_id', array_keys($quantities))
            ->where('quantity_remaining', '>', 0)
            ->orderBy('received_at')
            ->orderBy('id')
            ->get()
            ->groupBy('item_id');

        $lines = [];
        $problems = [];
        $totals = [];

        foreach ($quantities as $itemId => $wanted) {
            $item = $items->get($itemId);

            if (! $item || ! $item->active) {
                $problems[] = 'An item in your cart is no longer available and was skipped.';

                continue;
            }

            $available = $item->availableQuantity();

            if ($available < $wanted) {
                $problems[] = "Only {$available} left of {$item->name}.";
            }

            $remaining = min($wanted, $available);
            $prices = [];

            foreach ($batches->get($itemId, collect()) as $batch) {
                if ($remaining === 0) {
                    break;
                }

                $take = min($remaining, $batch->quantity_remaining);
                $remaining -= $take;
                $price = $batch->sale_price ?? $item->price;
                $prices[] = ['quantity' => $take, 'unit_price' => $price, 'clearance' => $batch->sale_price !== null];
                $totals[] = Money::mul($price, $take);
            }

            $lines[] = [
                'item_id' => $item->id,
                'name' => $item->name,
                'slug' => $item->slug,
                'image_url' => $item->imageUrl(),
                'quantity' => $wanted,
                'available' => $available,
                'prices' => $prices,
                'line_total' => Money::add(...array_map(fn ($p) => Money::mul($p['unit_price'], $p['quantity']), $prices)),
            ];
        }

        $total = Money::add(...$totals);
        $downpayment = Money::percent($total, $this->settings->int(SettingKey::DownpaymentPercent));

        return [
            'lines' => $lines,
            'total' => $total,
            'downpayment' => $downpayment,
            'balance' => Money::sub($total, $downpayment),
            'problems' => $problems,
        ];
    }
}
