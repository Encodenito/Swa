<?php

namespace Tests\Feature\Concurrency;

use App\Enums\ReservationStatus;
use App\Models\Item;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ConfirmPaymentService;
use App\Services\PayMongo\PaidCheckoutEvent;
use App\Services\ReservationService;
use App\Support\Money;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

/**
 * Spec Phase 2: "pressing Start processing while a customer cancels lets exactly one of them win".
 * Two separate PHP processes (separate DB connections) race on the same confirmed reservation.
 */
class CancelVersusProcessingRaceTest extends TestCase
{
    use BuildsStock, DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->beforeApplicationDestroyed(function () {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            foreach (['audit_logs', 'stock_movements', 'payments', 'reservation_items', 'reservations', 'stock_batches', 'items', 'categories', 'users', 'settings'] as $table) {
                DB::table($table)->truncate();
            }
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        });
    }

    public function test_exactly_one_of_cancel_and_start_processing_wins(): void
    {
        $customer = User::factory()->create();
        $staff = User::factory()->staff()->create();
        $item = Item::factory()->create(['price' => '1000.00']);
        $this->stockBatch($item, 10);

        $outcomes = [];

        for ($round = 0; $round < 4; $round++) {
            $reservation = app(ReservationService::class)->create($customer, [$item->id => 1], true);
            app(ConfirmPaymentService::class)->handlePaid(new PaidCheckoutEvent(
                $reservation->reference_code, "pay_race{$round}", Money::toCentavos($reservation->downpayment_amount), null,
            ));
            $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);

            $startAt = sprintf('%.4F', microtime(true) + 3.0);
            $env = ['APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync'];
            $script = base_path('tests/Concurrency/cancel_or_process.php');

            $cancel = Process::env($env)->start([PHP_BINARY, $script, 'cancel', $reservation->id, $customer->id, $startAt]);
            $process = Process::env($env)->start([PHP_BINARY, $script, 'process', $reservation->id, $staff->id, $startAt]);
            $cancelRun = $cancel->wait();
            $processRun = $process->wait();
            // Last output line only, so a stray warning printed first cannot hide the result.
            $last = fn (string $out) => trim((string) last(preg_split('/\R/', trim($out))));
            $results = ['cancel' => $last($cancelRun->output()), 'process' => $last($processRun->output())];
            $errors = trim($cancelRun->errorOutput().' '.$processRun->errorOutput());

            $wins = array_keys(array_filter($results, fn ($r) => $r === 'OK'));
            $this->assertCount(1, $wins, 'Expected exactly one winner: '.json_encode($results).' stderr: '.$errors);

            $final = Reservation::find($reservation->id);
            $expected = $wins[0] === 'cancel' ? ReservationStatus::Cancelled : ReservationStatus::Processing;
            $this->assertSame($expected, $final->status);
            $outcomes[] = $wins[0];
        }

        $this->assertStockReconciles();
    }
}
