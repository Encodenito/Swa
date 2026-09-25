<?php

namespace Tests\Feature\Money;

use App\Exceptions\StateConflictException;
use App\Models\Item;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\PickupReminder;
use App\Notifications\ReservationConfirmed;
use App\Notifications\ReservationForfeited;
use App\Notifications\ReservationNotification;
use App\Notifications\ReservationProcessing;
use App\Services\ReservationService;
use App\Services\WalkInService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\MakesReservations;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use MakesReservations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);
        Notification::fake();
        $this->travelTo('2026-09-24 10:00:00');
    }

    public function test_notifications_are_queued_after_commit_with_retries(): void
    {
        $reservation = $this->pendingReservation();
        $notification = new ReservationConfirmed($reservation);

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertInstanceOf(ReservationNotification::class, $notification);
        $this->assertTrue($notification->afterCommit);
        $this->assertSame(3, $notification->tries);
        $this->assertSame([60, 300], $notification->backoff);
    }

    public function test_confirmation_is_sent_once_and_not_for_duplicates_or_review_payments(): void
    {
        $reservation = $this->pendingReservation();

        $this->postPaidWebhook($reservation, 'pay_1');
        $this->postPaidWebhook($reservation, 'pay_1');          // duplicate delivery
        $this->postPaidWebhook($reservation, 'pay_2');          // second payment → needs_review

        Notification::assertSentToTimes($reservation->user, ReservationConfirmed::class, 1);
    }

    public function test_processing_email_only_when_the_update_happened(): void
    {
        $reservation = $this->confirmedReservation();
        $service = app(ReservationService::class);

        $service->startProcessing($reservation);

        try {
            $service->startProcessing($reservation);
        } catch (StateConflictException) {
        }

        Notification::assertSentToTimes($reservation->user, ReservationProcessing::class, 1);
    }

    public function test_pickup_reminder_is_sent_once_inside_the_24_hour_window(): void
    {
        $reservation = $this->confirmedReservation(); // pickup_by = 2026-09-27 23:59:59

        $this->artisan('reservations:remind')->expectsOutput('Sent 0 pickup reminder(s).');

        $this->travelTo('2026-09-27 08:00:00');
        $this->artisan('reservations:remind')->expectsOutput('Sent 1 pickup reminder(s).');
        $this->artisan('reservations:remind')->expectsOutput('Sent 0 pickup reminder(s).');

        Notification::assertSentToTimes($reservation->user, PickupReminder::class, 1);
    }

    public function test_the_reminder_also_goes_by_sms_when_there_is_a_phone_number(): void
    {
        $reservation = $this->confirmedReservation();

        $channels = (new PickupReminder($reservation))->via($reservation->user);
        $this->assertContains(SmsChannel::class, $channels);

        $reservation->user->forceFill(['phone' => null])->save();
        $this->assertSame(['mail'], (new PickupReminder($reservation))->via($reservation->user->fresh()));
    }

    public function test_forfeit_email_states_the_refund_percent(): void
    {
        Http::fake(['api.paymongo.com/v1/refunds' => Http::response(['data' => ['id' => 'ref_f']])]);
        $reservation = $this->confirmedReservation();

        $this->travelTo('2026-09-28 00:30:00');
        $this->artisan('reservations:forfeit')->expectsOutput('Forfeited 1 reservation(s).');

        Notification::assertSentTo($reservation->user, ReservationForfeited::class, fn ($n) => $n->refundPercent === 50 && $n->refund?->amount === '150.00');
    }

    public function test_walk_ins_never_get_notifications(): void
    {
        $item = Item::factory()->create();
        $this->stockBatch($item, 3);

        app(WalkInService::class)->sell([$item->id => 1], 'Juan', '09171234567', User::factory()->staff()->create());

        Notification::assertNothingSent();
    }

    public function test_emails_render(): void
    {
        $reservation = $this->confirmedReservation();

        $html = (string) (new ReservationConfirmed($reservation))->toMail($reservation->user)->render();

        $this->assertStringContainsString($reservation->reference_code, $html);
        $this->assertStringContainsString('₱700.00', $html);
    }
}
