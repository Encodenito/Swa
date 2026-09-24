<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case PendingPayment = 'pending_payment';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Forfeited = 'forfeited';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Awaiting payment',
            self::Confirmed => 'Confirmed',
            self::Processing => 'Being prepared',
            self::Completed => 'Picked up',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
            self::Forfeited => 'Forfeited',
            self::Voided => 'Voided',
        };
    }

    /**
     * Allowed transitions from spec section C. Services still lock and re-check the row.
     */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::PendingPayment => [self::Confirmed, self::Expired, self::Cancelled],
            self::Confirmed => [self::Processing, self::Cancelled, self::Completed, self::Forfeited],
            self::Processing => [self::Cancelled, self::Completed, self::Forfeited],
            self::Completed => [self::Voided],
            default => [],
        }, true);
    }

    /**
     * Statuses that still hold stock for a pickup.
     *
     * @return list<self>
     */
    public static function awaitingPickup(): array
    {
        return [self::Confirmed, self::Processing];
    }
}
