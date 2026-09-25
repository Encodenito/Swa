<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $target): bool
    {
        return $user->isAdmin();
    }

    /**
     * CRM: staff see customer profiles (never other staff accounts through this path).
     */
    public function viewCustomers(User $user): bool
    {
        return $user->isStaff();
    }

    public function viewCustomer(User $user, User $customer): bool
    {
        return $user->isStaff() && $customer->isCustomer();
    }
}
