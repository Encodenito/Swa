<?php

namespace App\Console\Commands;

use App\Services\ReservationService;
use Illuminate\Console\Command;

class ExpireReservations extends Command
{
    protected $signature = 'reservations:expire';

    protected $description = 'Expire unpaid reservations past their payment window and return their stock';

    public function handle(ReservationService $reservations): int
    {
        $count = $reservations->expireDue();

        $this->info("Expired {$count} reservation(s).");

        return self::SUCCESS;
    }
}
