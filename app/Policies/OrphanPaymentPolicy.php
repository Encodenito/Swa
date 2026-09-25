<?php

namespace App\Policies;

use App\Models\OrphanPayment;
use App\Models\User;

/**
 * Money with no reservation behind it: every decision is an admin's.
 */
class OrphanPaymentPolicy
{
    /**
     * Staff see orphan payments on Payments to review (read-only); only admins act on them.
     */
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function handle(User $user, OrphanPayment $orphan): bool
    {
        return $user->isAdmin();
    }
}
