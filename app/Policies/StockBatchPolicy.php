<?php

namespace App\Policies;

use App\Models\StockBatch;
use App\Models\User;

class StockBatchPolicy
{
    public function adjust(User $user, StockBatch $batch): bool
    {
        return $user->isStaff();
    }

    /**
     * Clearance prices are an admin decision.
     */
    public function updatePrice(User $user, StockBatch $batch): bool
    {
        return $user->isAdmin();
    }
}
