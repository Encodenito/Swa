<?php

namespace App\Policies;

use App\Models\OrphanPayment;
use App\Models\User;

/**
 * Money with no reservation behind it: every decision is an admin's.
 */
class OrphanPaymentPolicy
{
    public function handle(User $user, OrphanPayment $orphan): bool
    {
        return $user->isAdmin();
    }
}
