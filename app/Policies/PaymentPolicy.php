<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    /**
     * The "Payments to review" screen.
     */
    public function review(User $user): bool
    {
        return $user->isStaff();
    }

    /**
     * Refund a payment that could not be applied (spec D: staff refund manually).
     */
    public function refund(User $user, Payment $payment): bool
    {
        return $user->isStaff();
    }

    /**
     * Retry a failed refund (spec D: a person retries).
     */
    public function retry(User $user, Payment $payment): bool
    {
        return $user->isStaff();
    }

    /**
     * Close a needs_review payment without refunding: a money decision, so admin only.
     */
    public function markReviewed(User $user, Payment $payment): bool
    {
        return $user->isAdmin();
    }
}
