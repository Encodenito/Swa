<?php

namespace App\Policies;

use App\Enums\ReservationType;
use App\Enums\Role;
use App\Models\Reservation;
use App\Models\User;

class ReservationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    /**
     * Customers see only their own reservations; staff see all.
     */
    public function view(User $user, Reservation $reservation): bool
    {
        return $user->isStaff() || ($reservation->user_id !== null && $reservation->user_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->role === Role::Customer && $user->hasVerifiedEmail() && $user->deactivated_at === null;
    }

    public function process(User $user, Reservation $reservation): bool
    {
        return $user->isStaff();
    }

    public function extendDeadline(User $user, Reservation $reservation): bool
    {
        return $user->isStaff();
    }

    public function complete(User $user, Reservation $reservation): bool
    {
        return $user->isStaff();
    }

    /**
     * Coarse check; the exact statuses and refund percentages are enforced in ReservationService::cancel.
     */
    public function cancel(User $user, Reservation $reservation): bool
    {
        if ($reservation->type !== ReservationType::Reservation) {
            return false;
        }

        return $user->isStaff() || ($user->role === Role::Customer && $reservation->user_id === $user->id);
    }

    /**
     * Staff reserve on behalf of a customer (spec B).
     */
    public function createForCustomer(User $user): bool
    {
        return $user->isStaff();
    }

    public function sellWalkIn(User $user): bool
    {
        return $user->isStaff();
    }

    public function void(User $user, Reservation $reservation): bool
    {
        return $user->isAdmin() && $reservation->type === ReservationType::WalkIn;
    }
}
