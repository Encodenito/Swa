<?php

namespace Tests\Feature\Care;

use App\Models\Item;
use App\Models\MessageLog;
use App\Models\User;
use App\Notifications\LowStockAlert;
use App\Notifications\ReservationConfirmed;
use Database\Seeders\SettingsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\MakesReservations;
use Tests\TestCase;

class MessagesAndAlertsTest extends TestCase
{
    use MakesReservations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);
    }

    public function test_a_sent_email_is_logged_with_a_masked_address_and_no_body(): void
    {
        // Real mail pipeline (array mailer), so NotificationSent fires.
        $customer = User::factory()->create(['email' => 'carla.reyes@example.com']);
        $reservation = $this->confirmedReservation($customer);

        $log = MessageLog::query()->where('notification', 'ReservationConfirmed')->sole();
        $this->assertSame($customer->id, $log->user_id);
        $this->assertSame($reservation->id, $log->reservation_id);
        $this->assertSame('mail', $log->channel);
        $this->assertSame('c***@example.com', $log->recipient);
        $this->assertStringContainsString($reservation->reference_code, $log->subject);
        $this->assertArrayNotHasKey('body', $log->getAttributes());
    }

    public function test_nothing_is_logged_when_the_notification_is_not_sent(): void
    {
        Notification::fake();
        $this->confirmedReservation();

        Notification::assertSentTimes(ReservationConfirmed::class, 1);
        $this->assertSame(0, MessageLog::count());
    }

    public function test_low_stock_alert_goes_to_active_admins_only_and_includes_the_threshold_edge(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $inactiveAdmin = User::factory()->admin()->create(['deactivated_at' => now()]);
        $staff = User::factory()->staff()->create();

        $atThreshold = Item::factory()->create(['low_stock_threshold' => 3, 'name' => 'At threshold']);
        $this->stockBatch($atThreshold, 3);
        $plenty = Item::factory()->create(['low_stock_threshold' => 3]);
        $this->stockBatch($plenty, 4);
        $soldOut = Item::factory()->create(['low_stock_threshold' => 2, 'name' => 'Sold out']);

        $this->artisan('stock:low-alert')->assertSuccessful();

        Notification::assertSentTo($admin, LowStockAlert::class, function (LowStockAlert $n) use ($atThreshold, $soldOut, $plenty) {
            $ids = array_column($n->items, 'id');

            return in_array($atThreshold->id, $ids, true) && in_array($soldOut->id, $ids, true) && ! in_array($plenty->id, $ids, true)
                && $n->items[0]['id'] === $soldOut->id; // lowest first
        });
        Notification::assertNotSentTo([$inactiveAdmin, $staff], LowStockAlert::class);
    }

    public function test_no_alert_when_nothing_is_low(): void
    {
        Notification::fake();
        User::factory()->admin()->create();
        $item = Item::factory()->create(['low_stock_threshold' => 1]);
        $this->stockBatch($item, 5);

        $this->artisan('stock:low-alert')->expectsOutput('No items are low on stock.');

        Notification::assertNothingSent();
    }

    public function test_the_alert_is_scheduled_daily_at_eight(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'stock:low-alert'));

        $this->assertSame('0 8 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_the_low_stock_email_renders(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();
        $mail = (new LowStockAlert([['id' => 1, 'name' => 'Ink', 'slug' => 'ink', 'sku' => 'T1', 'available' => 0, 'threshold' => 3]]))->toMail($admin);

        $this->assertSame('Low stock: 1 item(s) need reordering', $mail->subject);
        $this->assertStringContainsString('Ink (T1): sold out', implode("\n", $mail->introLines));
    }
}
