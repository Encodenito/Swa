<?php

/**
 * Worker for LastUnitConcurrencyTest: boots the app against the test database, waits for a shared
 * start time, then tries to reserve. Prints "OK <reference>" or "FAIL <message>".
 *
 * Usage: php tests/Concurrency/reserve.php <user_id> <item_id> <quantity> <start_unix_float>
 */

use App\Models\User;
use App\Services\ReservationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! str_ends_with((string) config('database.connections.'.config('database.default').'.database'), '_test')) {
    fwrite(STDERR, "Refusing to run outside a *_test database.\n");
    exit(2);
}

[, $userId, $itemId, $quantity, $startAt] = $argv;

// Line both workers up on the same instant so their transactions overlap.
while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    $reservation = app(ReservationService::class)->create(User::findOrFail($userId), [(int) $itemId => (int) $quantity], true);
    echo "OK {$reservation->reference_code}";
} catch (ValidationException $e) {
    echo 'FAIL '.collect($e->errors())->flatten()->first();
}
