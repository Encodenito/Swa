<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\ChatHandoff;
use App\Models\FollowUp;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Staff dashboard figures (spec J: pickups today, expiring reservations, failed refunds, ...).
 * Each card is one count plus a short list; nothing loops per row.
 */
class DashboardService
{
    private const LIST_SIZE = 5;

    public function __construct(private readonly LowStockService $lowStock) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $today = now();

        $pickupsToday = Reservation::query()
            ->whereIn('status', ReservationStatus::awaitingPickup())
            ->whereBetween('pickup_by', [$today->copy()->startOfDay(), $today->copy()->endOfDay()]);

        $overdue = Reservation::query()
            ->whereIn('status', ReservationStatus::awaitingPickup())
            ->where('pickup_by', '<', $today);

        $expiring = Reservation::query()
            ->where('status', ReservationStatus::PendingPayment)
            ->whereBetween('expires_at', [$today, $today->copy()->addMinutes(10)]);

        $review = app(PaymentReviewService::class)->query();

        $followUpsDue = FollowUp::query()->open()->where('due_on', '<=', $today->toDateString());

        $lowStock = $this->lowStock->items(self::LIST_SIZE + 1);

        return [
            'pickups_today' => $this->card($pickupsToday, 'pickup_by'),
            'overdue_pickups' => $this->card($overdue, 'pickup_by'),
            'expiring_soon' => $this->card($expiring, 'expires_at'),
            'payments_to_review' => [
                'count' => (clone $review)->count(),
                'needs_review' => (clone $review)->where('status', PaymentStatus::NeedsReview)->count(),
                'refund_failed' => (clone $review)->where('status', PaymentStatus::RefundFailed)->count(),
                'stuck' => (clone $review)->where('status', PaymentStatus::RefundPending)->count(),
                'orphans' => app(OrphanPaymentService::class)->reviewQuery()->count(),
            ],
            'open_handoffs' => ChatHandoff::query()->where('status', ChatHandoffService::OPEN)->count(),
            'follow_ups_due' => [
                'count' => (clone $followUpsDue)->count(),
                'overdue' => (clone $followUpsDue)->where('due_on', '<', $today->toDateString())->count(),
                'items' => (clone $followUpsDue)->with('customer:id,name')->orderBy('due_on')->limit(self::LIST_SIZE)->get()
                    ->map(fn (FollowUp $f) => [
                        'id' => $f->id,
                        'due_on' => $f->due_on->toDateString(),
                        'note' => $f->note,
                        'customer' => ['id' => $f->user_id, 'name' => $f->customer?->name],
                    ])->all(),
            ],
            'low_stock' => [
                'count' => count($lowStock) > self::LIST_SIZE ? count($this->lowStock->items()) : count($lowStock),
                'items' => array_slice($lowStock, 0, self::LIST_SIZE),
            ],
        ];
    }

    /**
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    private function card(Builder $query, string $dateColumn): array
    {
        return [
            'count' => (clone $query)->count(),
            'items' => (clone $query)->with('user:id,name,phone')->orderBy($dateColumn)->limit(self::LIST_SIZE)->get()
                ->map(fn (Reservation $r) => [
                    'reference_code' => $r->reference_code,
                    'status' => $r->status->value,
                    'status_label' => $r->status->label(),
                    'when' => $r->{$dateColumn}?->toIso8601String(),
                    'balance' => $r->balance,
                    'customer' => $r->user?->name,
                ])->all(),
        ];
    }
}
