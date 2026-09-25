<?php

namespace App\Policies;

use App\Models\ChatbotFaq;
use App\Models\User;

/**
 * Spec B: only admins edit the FAQ.
 */
class ChatbotFaqPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, ChatbotFaq $faq): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, ChatbotFaq $faq): bool
    {
        return $user->isAdmin();
    }
}
