<?php

namespace App\Policies;

use App\Models\ChatHandoff;
use App\Models\User;

class ChatHandoffPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function claim(User $user, ChatHandoff $handoff): bool
    {
        return $user->isStaff();
    }

    public function close(User $user, ChatHandoff $handoff): bool
    {
        return $user->isStaff();
    }
}
