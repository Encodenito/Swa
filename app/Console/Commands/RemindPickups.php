<?php

namespace App\Console\Commands;

use App\Services\ReservationService;
use Illuminate\Console\Command;

class RemindPickups extends Command
{
    protected $signature = 'reservations:remind';

    protected $description = 'Remind customers whose pickup deadline is within the next 24 hours';

    public function handle(ReservationService $reservations): int
    {
        $count = $reservations->remindDue();

        $this->info("Sent {$count} pickup reminder(s).");

        return self::SUCCESS;
    }
}
