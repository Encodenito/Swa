<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * A fixed list of named customer segments. Nothing here is built from user input; the request
 * only picks a key from SEGMENTS.
 */
class CustomerSegments
{
    public const SEGMENTS = [
        'recent_pickup' => 'Picked up in the last 90 days',
        'dormant' => 'No reservation in 180 days',
        'forfeited' => 'Forfeited before',
        'open_follow_up' => 'Has an open follow-up',
        'awaiting_pickup' => 'Awaiting pickup now',
    ];

    public function apply(Builder $customers, string $segment): Builder
    {
        return match ($segment) {
            'recent_pickup' => $customers->whereHas('reservations', fn ($r) => $r
                ->where('status', ReservationStatus::Completed)
                ->where('completed_at', '>=', now()->subDays(90))),
            'dormant' => $customers->whereDoesntHave('reservations', fn ($r) => $r
                ->where('created_at', '>=', now()->subDays(180))),
            'forfeited' => $customers->whereHas('reservations', fn ($r) => $r->where('status', ReservationStatus::Forfeited)),
            'open_follow_up' => $customers->whereHas('followUps', fn ($f) => $f->whereNull('completed_at')),
            'awaiting_pickup' => $customers->whereHas('reservations', fn ($r) => $r->whereIn('status', ReservationStatus::awaitingPickup())),
            default => $customers,
        };
    }

    public function baseQuery(): Builder
    {
        return User::query()->where('role', Role::Customer);
    }
}
