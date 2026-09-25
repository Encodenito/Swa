<?php

namespace App\Policies;

use App\Models\CustomerNote;
use App\Models\User;
use App\Services\CrmService;

class CustomerNotePolicy
{
    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    /**
     * Authors may remove their own note shortly after writing it; admins any time.
     */
    public function delete(User $user, CustomerNote $note): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isStaff()
            && $note->created_by === $user->id
            && $note->created_at->greaterThan(now()->subMinutes(CrmService::NOTE_DELETE_MINUTES));
    }
}
