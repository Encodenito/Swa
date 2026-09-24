<?php

namespace App\Enums;

enum SettingKey: string
{
    case DownpaymentPercent = 'downpayment_percent';
    case PickupDays = 'pickup_days';
    case ForfeitRefundPercent = 'forfeit_refund_percent';
    case PendingMinutes = 'pending_minutes';
    case MaxUnpaidReservations = 'max_unpaid_reservations';
    case MaxQtyPerItem = 'max_qty_per_item';

    public function default(): int
    {
        return match ($this) {
            self::DownpaymentPercent => 30,
            self::PickupDays => 3,
            self::ForfeitRefundPercent => 50,
            self::PendingMinutes => 30,
            self::MaxUnpaidReservations => 2,
            self::MaxQtyPerItem => 10,
        };
    }

    /**
     * @return array<string, int>
     */
    public static function defaults(): array
    {
        $defaults = [];

        foreach (self::cases() as $case) {
            $defaults[$case->value] = $case->default();
        }

        return $defaults;
    }
}
