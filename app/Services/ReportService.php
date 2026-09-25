<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\ReservationStatus;
use App\Enums\ReservationType;
use App\Models\Item;
use App\Models\Reservation;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Read-only admin reports. Each report is one aggregate query (no per-row queries).
 */
class ReportService
{
    /**
     * Completed sales per day (reservations picked up + walk-ins), voided walk-ins excluded.
     *
     * @return list<array{date: string, reservations: string, walk_ins: string, total: string, count: int}>
     */
    public function salesPerDay(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = Reservation::query()
            ->where('status', ReservationStatus::Completed)
            ->whereBetween('completed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('DATE(completed_at) as day')
            ->selectRaw('SUM(CASE WHEN type = ? THEN total ELSE 0 END) as reservations', [ReservationType::Reservation->value])
            ->selectRaw('SUM(CASE WHEN type = ? THEN total ELSE 0 END) as walk_ins', [ReservationType::WalkIn->value])
            ->selectRaw('SUM(total) as total, COUNT(*) as count')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return $rows->map(fn ($r) => [
            'date' => (string) $r->day,
            'reservations' => Money::of((string) $r->reservations),
            'walk_ins' => Money::of((string) $r->walk_ins),
            'total' => Money::of((string) $r->total),
            'count' => (int) $r->count,
        ])->all();
    }

    /**
     * Forfeited reservations and how much of each downpayment the shop kept.
     *
     * @return array{rows: list<array<string, mixed>>, kept_total: string}
     */
    public function forfeitedKept(CarbonInterface $from, CarbonInterface $to): array
    {
        $paid = DB::table('payments')
            ->selectRaw('COALESCE(SUM(amount), 0)')
            ->whereColumn('payments.reservation_id', 'reservations.id')
            ->where('type', PaymentType::Downpayment->value)
            ->where('status', PaymentStatus::Paid->value);

        // Only refunds of the paid downpayment count (not refunds of a separate payment held for review).
        $refunded = DB::table('payments as refunds')
            ->join('payments as originals', 'originals.id', '=', 'refunds.parent_id')
            ->selectRaw('COALESCE(SUM(refunds.amount), 0)')
            ->whereColumn('refunds.reservation_id', 'reservations.id')
            ->where('refunds.type', PaymentType::Refund->value)
            ->where('refunds.status', '!=', PaymentStatus::RefundFailed->value)
            ->where('originals.type', PaymentType::Downpayment->value)
            ->where('originals.status', PaymentStatus::Paid->value);

        $rows = Reservation::query()
            ->where('status', ReservationStatus::Forfeited)
            ->whereBetween('forfeited_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->select('reference_code', 'forfeited_at')
            ->selectSub($paid, 'paid')
            ->selectSub($refunded, 'refunded')
            ->orderByDesc('forfeited_at')
            ->get()
            ->map(fn ($r) => [
                'reference_code' => $r->reference_code,
                'forfeited_at' => $r->forfeited_at?->toIso8601String(),
                'paid' => Money::of((string) $r->paid),
                'refunded' => Money::of((string) $r->refunded),
                'kept' => Money::sub((string) $r->paid, (string) $r->refunded),
            ])
            ->all();

        return [
            'rows' => $rows,
            'kept_total' => Money::add(...array_column($rows, 'kept')),
        ];
    }

    /**
     * Items still in stock that have not been reserved or sold for $days days (or ever).
     *
     * @return list<array<string, mixed>>
     */
    public function slowMoving(int $days): array
    {
        $lastSale = DB::table('stock_movements')
            ->selectRaw('MAX(created_at)')
            ->whereColumn('stock_movements.item_id', 'items.id')
            ->whereIn('type', [MovementType::Reserved->value, MovementType::Sold->value]);

        $cutoff = now()->subDays($days);

        return Item::query()
            ->withAvailable()
            ->selectSub($lastSale, 'last_sale_at')
            ->where('active', true)
            ->whereExists(fn ($q) => $q->from('stock_batches')->whereColumn('stock_batches.item_id', 'items.id')->where('quantity_remaining', '>', 0))
            ->where(fn ($q) => $q
                ->whereNotExists(fn ($m) => $m->from('stock_movements')->whereColumn('stock_movements.item_id', 'items.id')
                    ->whereIn('type', [MovementType::Reserved->value, MovementType::Sold->value])
                    ->where('created_at', '>=', $cutoff)))
            ->orderByRaw('last_sale_at IS NOT NULL, last_sale_at')
            ->limit(200)
            ->get()
            ->map(fn (Item $item) => [
                'name' => $item->name,
                'slug' => $item->slug,
                'sku' => $item->sku,
                'available' => $item->availableQuantity(),
                'last_sale_at' => $item->last_sale_at ? (string) $item->last_sale_at : null,
                'days_since_sale' => $item->last_sale_at ? (int) now()->diffInDays($item->last_sale_at, true) : null,
            ])
            ->all();
    }
}
