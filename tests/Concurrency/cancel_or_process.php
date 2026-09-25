<?php

/**
 * Worker for CancelVersusProcessingRaceTest. Boots the app on the test database, fakes every
 * outside service, waits for a shared start time, then either cancels (as the customer) or
 * starts processing (as staff). Prints "OK" or "CONFLICT".
 *
 * Usage: php tests/Concurrency/cancel_or_process.php <cancel|process> <reservation_id> <user_id> <start_unix_float>
 */

use App\Exceptions\StateConflictException;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! str_ends_with((string) config('database.connections.'.config('database.default').'.database'), '_test')) {
    fwrite(STDERR, "Refusing to run outside a *_test database.\n");
    exit(2);
}

// This child process must never reach PayMongo or Resend.
Http::preventStrayRequests();
Http::fake(['api.paymongo.com/v1/refunds' => Http::response(['data' => ['id' => 'ref_race']])]);
Notification::fake();

[, $action, $reservationId, $userId, $startAt] = $argv;

$reservation = Reservation::findOrFail($reservationId);
$user = User::findOrFail($userId);
$service = app(ReservationService::class);

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    $action === 'cancel'
        ? $service->cancel($reservation, $user, 100, 'Race test')
        : $service->startProcessing($reservation);
    echo 'OK';
} catch (StateConflictException) {
    echo 'CONFLICT';
}
