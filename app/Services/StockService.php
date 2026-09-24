<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\StateConflictException;
use App\Models\DeliveryItem;
use App\Models\Item;
use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The only place stock quantities change. Every change writes a stock_movements row, so
 * SUM(stock_batches.quantity_remaining) always equals SUM(stock_movements.quantity) per item.
 *
 * Locking protocol (avoids deadlocks): item rows first, ascending id; then batch rows.
 * Callers wrap in DB::transaction(..., 3) so a deadlock is retried.
 */
class StockService
{
    public function addBatch(
        Item $item,
        int $quantity,
        ?string $salePrice,
        CarbonInterface $receivedAt,
        ?DeliveryItem $deliveryItem = null,
        ?User $by = null,
    ): StockBatch {
        if ($quantity < 1) {
            throw new InvalidArgumentException('A batch needs at least one unit.');
        }

        return DB::transaction(function () use ($item, $quantity, $salePrice, $receivedAt, $deliveryItem, $by) {
            $batch = new StockBatch;
            $batch->forceFill([
                'item_id' => $item->id,
                'delivery_item_id' => $deliveryItem?->id,
                'quantity_remaining' => $quantity,
                'sale_price' => $salePrice,
                'received_at' => $receivedAt,
            ])->save();

            $this->record($batch, MovementType::Received, $quantity, by: $by);

            return $batch;
        });
    }

    /**
     * Take stock for a reservation, oldest batch first, one reservation line per batch used.
     * All-or-nothing: throws InsufficientStockException and the caller's transaction rolls back.
     *
     * @param  array<int, int>  $quantities  item_id => quantity
     */
    public function allocate(Reservation $reservation, array $quantities, ?User $by = null): void
    {
        $this->assertInTransaction();
        ksort($quantities);

        $items = Item::query()
            ->whereIn('id', array_keys($quantities))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($quantities as $itemId => $wanted) {
            $item = $items->get($itemId);

            if (! $item || ! $item->active) {
                throw new InsufficientStockException($itemId, $item->name ?? 'this item', 0);
            }

            $batches = StockBatch::query()
                ->where('item_id', $itemId)
                ->where('quantity_remaining', '>', 0)
                ->orderBy('received_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $available = (int) $batches->sum('quantity_remaining');

            if ($available < $wanted) {
                throw new InsufficientStockException($itemId, $item->name, $available);
            }

            $remaining = $wanted;

            foreach ($batches as $batch) {
                if ($remaining === 0) {
                    break;
                }

                $take = min($remaining, $batch->quantity_remaining);
                $remaining -= $take;

                $batch->forceFill(['quantity_remaining' => $batch->quantity_remaining - $take])->save();

                $line = new ReservationItem;
                $line->forceFill([
                    'reservation_id' => $reservation->id,
                    'item_id' => $itemId,
                    'batch_id' => $batch->id,
                    'quantity' => $take,
                    'unit_price' => $batch->sale_price ?? $item->price,
                ])->save();

                $this->record($batch, MovementType::Reserved, -$take, $reservation, $by);
            }
        }
    }

    /**
     * Return every line's quantity to its exact batch. Runs at most once per reservation.
     */
    public function release(Reservation $reservation, ?User $by = null, ?string $reason = null): void
    {
        $this->assertInTransaction();

        $alreadyReleased = StockMovement::query()
            ->where('reservation_id', $reservation->id)
            ->where('type', MovementType::Released)
            ->exists();

        if ($alreadyReleased) {
            return;
        }

        $lines = $reservation->lines()->get();

        if ($lines->isEmpty()) {
            return;
        }

        Item::query()->whereIn('id', $lines->pluck('item_id')->unique())->orderBy('id')->lockForUpdate()->get();

        $batches = StockBatch::query()
            ->whereIn('id', $lines->pluck('batch_id')->unique())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($lines as $line) {
            $batch = $batches->get($line->batch_id);
            $batch->forceFill(['quantity_remaining' => $batch->quantity_remaining + $line->quantity])->save();

            $this->record($batch, MovementType::Released, $line->quantity, $reservation, $by, $reason);
        }
    }

    /**
     * Manual correction on one batch. `damaged` only removes stock; `adjusted` may add or remove.
     */
    public function adjust(StockBatch $batch, int $delta, MovementType $type, string $reason, User $by): StockMovement
    {
        if (! in_array($type, [MovementType::Damaged, MovementType::Adjusted], true)) {
            throw new InvalidArgumentException('Only damaged or adjusted movements can be recorded by hand.');
        }

        if ($delta === 0 || ($type === MovementType::Damaged && $delta > 0)) {
            throw new InvalidArgumentException('Damaged stock must be a negative quantity; adjustments cannot be zero.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required.');
        }

        return DB::transaction(function () use ($batch, $delta, $type, $reason, $by) {
            Item::query()->whereKey($batch->item_id)->lockForUpdate()->first();
            $locked = StockBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($locked->quantity_remaining + $delta < 0) {
                throw new StateConflictException("This batch has only {$locked->quantity_remaining} left.");
            }

            $locked->forceFill(['quantity_remaining' => $locked->quantity_remaining + $delta])->save();

            return $this->record($locked, $type, $delta, by: $by, reason: $reason);
        }, 3);
    }

    private function record(
        StockBatch $batch,
        MovementType $type,
        int $quantity,
        ?Reservation $reservation = null,
        ?User $by = null,
        ?string $reason = null,
    ): StockMovement {
        $movement = new StockMovement;
        $movement->forceFill([
            'item_id' => $batch->item_id,
            'batch_id' => $batch->id,
            'type' => $type,
            'quantity' => $quantity,
            'reservation_id' => $reservation?->id,
            'reason' => $reason,
            'created_by' => $by?->id,
        ])->save();

        return $movement;
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Stock allocation and release must run inside a database transaction.');
        }
    }
}
