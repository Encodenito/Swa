<?php

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Exceptions\StateConflictException;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryService
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * @param  array<int, array{item_id: int, quantity_ordered: int, unit_cost?: string|null, sale_price?: string|null}>  $lines
     */
    public function create(int $supplierId, ?string $reference, ?string $expectedAt, array $lines, User $by): Delivery
    {
        return DB::transaction(function () use ($supplierId, $reference, $expectedAt, $lines, $by) {
            $delivery = new Delivery(['supplier_id' => $supplierId, 'reference' => $reference, 'expected_at' => $expectedAt]);
            $delivery->forceFill(['created_by' => $by->id, 'status' => DeliveryStatus::Ordered])->save();

            foreach ($lines as $line) {
                $delivery->lines()->create($line);
            }

            return $delivery;
        });
    }

    /**
     * Receive a delivery once: each line with a received quantity becomes a stock batch plus a
     * `received` movement.
     *
     * @param  array<int, int>  $received  delivery_item_id => quantity received (0 allowed)
     *
     * @throws StateConflictException|ValidationException
     */
    public function receive(Delivery $delivery, array $received, User $by): void
    {
        DB::transaction(function () use ($delivery, $received, $by) {
            $locked = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== DeliveryStatus::Ordered) {
                throw new StateConflictException('This delivery was already '.$locked->status->value.'.');
            }

            $lines = $locked->lines()->with('item')->get();

            if (array_sum(array_map('intval', $received)) < 1) {
                throw ValidationException::withMessages(['received' => 'Enter the quantity received for at least one line.']);
            }

            $now = now();

            foreach ($lines as $line) {
                /** @var DeliveryItem $line */
                $quantity = max(0, (int) ($received[$line->id] ?? 0));
                $line->forceFill(['quantity_received' => $quantity])->save();

                if ($quantity > 0) {
                    $this->stock->addBatch($line->item, $quantity, $line->sale_price, $now, $line, $by);
                }
            }

            $locked->forceFill([
                'status' => DeliveryStatus::Received,
                'received_at' => $now,
                'received_by' => $by->id,
            ])->save();
        }, 3);

        $delivery->refresh();
    }
}
