<?php

namespace App\Console\Commands;

use App\Services\ReservationService;
use Illuminate\Console\Command;

class ForfeitReservations extends Command
{
    protected $signature = 'reservations:forfeit';

    protected $description = 'Forfeit reservations past their pickup deadline, return the stock and refund the forfeit percentage';

    public function handle(ReservationService $reservations): int
    {
        $count = $reservations->forfeitDue();

        $this->info("Forfeited {$count} reservation(s).");

        return self::SUCCESS;
    }
}
