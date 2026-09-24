<?php

namespace App\Http\Presenters;

use App\Enums\ReservationStatus;
use App\Enums\ReservationType;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Models\User;
use App\Services\ReservationService;

final class ReservationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Reservation $reservation): array
    {
        return [
            'reference_code' => $reservation->reference_code,
            'type' => $reservation->type->value,
            'status' => $reservation->status->value,
            'status_label' => $reservation->status->label(),
            'total' => $reservation->total,
            'downpayment_amount' => $reservation->downpayment_amount,
            'balance' => $reservation->balance,
            'expires_at' => $reservation->expires_at?->toIso8601String(),
            'pickup_by' => $reservation->pickup_by?->toIso8601String(),
            'created_at' => $reservation->created_at?->toIso8601String(),
            'item_count' => $reservation->relationLoaded('lines') ? (int) $reservation->lines->sum('quantity') : null,
            'customer' => $reservation->relationLoaded('user') && $reservation->user ? [
                'name' => $reservation->user->name,
                'email' => $reservation->user->email,
                'phone' => $reservation->user->phone,
            ] : null,
            'walk_in_name' => $reservation->walk_in_name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Reservation $reservation, ?User $viewer = null): array
    {
        $reservation->loadMissing(['lines.item', 'payments', 'user']);
        $isStaff = (bool) $viewer?->isStaff();
        $cancelRules = $viewer ? ReservationService::cancelRules($viewer, $reservation) : ['statuses' => [], 'percents' => []];

        return self::summary($reservation) + [
            'confirmed_at' => $reservation->confirmed_at?->toIso8601String(),
            'processing_at' => $reservation->processing_at?->toIso8601String(),
            'completed_at' => $reservation->completed_at?->toIso8601String(),
            'cancelled_at' => $reservation->cancelled_at?->toIso8601String(),
            'cancel_reason' => $reservation->cancel_reason,
            'forfeited_at' => $reservation->forfeited_at?->toIso8601String(),
            'walk_in_phone' => $isStaff ? $reservation->walk_in_phone : null,
            'can_pay' => $reservation->status === ReservationStatus::PendingPayment
                && $reservation->checkout_url !== null
                && $reservation->expires_at?->isFuture(),
            // Staff reserving for a customer hand this link over; customers use "Continue to payment".
            'checkout_url' => $isStaff && $reservation->status === ReservationStatus::PendingPayment ? $reservation->checkout_url : null,
            // What this viewer may do. Display only; the server re-checks every action.
            'cancel' => [
                'allowed' => $reservation->type === ReservationType::Reservation && in_array($reservation->status, $cancelRules['statuses'], true),
                'percents' => $cancelRules['percents'],
                'reason_required' => $isStaff,
            ],
            'can_void' => (bool) $viewer?->isAdmin()
                && $reservation->type === ReservationType::WalkIn
                && $reservation->status === ReservationStatus::Completed,
            'lines' => $reservation->lines->map(fn (ReservationItem $line) => [
                'id' => $line->id,
                'item_name' => $line->item?->name,
                'item_slug' => $line->item?->slug,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'clearance' => $line->item && $line->unit_price !== $line->item->price,
                'line_total' => $line->lineTotal(),
            ])->values()->all(),
            'payments' => $reservation->payments->sortBy('id')->map(fn (Payment $payment) => self::payment($payment, $isStaff))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function payment(Payment $payment, bool $isStaff): array
    {
        return [
            'id' => $payment->id,
            'parent_id' => $payment->parent_id,
            'type' => $payment->type->value,
            'amount' => $payment->amount,
            'method' => $payment->method->value,
            'status' => $payment->status->value,
            'created_at' => $payment->created_at?->toIso8601String(),
            // Provider ids and internal notes are for staff only.
            'provider_ref' => $isStaff ? $payment->provider_ref : null,
            'note' => $isStaff ? $payment->note : null,
            'reviewed_at' => $isStaff ? $payment->reviewed_at?->toIso8601String() : null,
        ];
    }
}
