<?php

namespace App\Http\Presenters;

use App\Enums\ReservationStatus;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Models\User;

final class ReservationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Reservation $reservation): array
    {
        return [
            'reference_code' => $reservation->reference_code,
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Reservation $reservation, ?User $viewer = null): array
    {
        $reservation->loadMissing(['lines.item', 'payments', 'user']);

        return self::summary($reservation) + [
            'confirmed_at' => $reservation->confirmed_at?->toIso8601String(),
            'processing_at' => $reservation->processing_at?->toIso8601String(),
            'completed_at' => $reservation->completed_at?->toIso8601String(),
            'cancelled_at' => $reservation->cancelled_at?->toIso8601String(),
            'cancel_reason' => $reservation->cancel_reason,
            'can_pay' => $reservation->status === ReservationStatus::PendingPayment
                && $reservation->checkout_url !== null
                && $reservation->expires_at?->isFuture(),
            'lines' => $reservation->lines->map(fn (ReservationItem $line) => [
                'id' => $line->id,
                'item_name' => $line->item?->name,
                'item_slug' => $line->item?->slug,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'clearance' => $line->item && $line->unit_price !== $line->item->price,
                'line_total' => $line->lineTotal(),
            ])->values()->all(),
            'payments' => $reservation->payments->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'type' => $payment->type->value,
                'amount' => $payment->amount,
                'method' => $payment->method->value,
                'status' => $payment->status->value,
                'created_at' => $payment->created_at?->toIso8601String(),
                // Provider ids and review notes are for staff only.
                'provider_ref' => $viewer?->isStaff() ? $payment->provider_ref : null,
                'note' => $viewer?->isStaff() ? $payment->note : null,
            ])->values()->all(),
        ];
    }
}
