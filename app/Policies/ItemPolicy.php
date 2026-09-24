<?php

namespace App\Policies;

use App\Models\Item;
use App\Models\User;

class ItemPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Item $item): bool
    {
        return $item->active || ($user?->isStaff() ?? false);
    }

    public function viewStock(User $user): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Item $item): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Item $item): bool
    {
        return $user->isAdmin();
    }
}
