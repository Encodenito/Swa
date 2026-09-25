<?php

namespace App\Listeners;

use App\Models\MessageLog;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\ReservationNotification;
use App\Support\Mask;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Messages\MailMessage;
use Throwable;

/**
 * Records every notification that was actually delivered to its channel (spec Phase 3: message
 * log from NotificationSent). Stores the masked recipient and the subject only, never the body.
 */
class LogSentNotification
{
    public function handle(NotificationSent $event): void
    {
        $channel = match ($event->channel) {
            'mail' => 'mail',
            SmsChannel::class => 'sms',
            default => null,
        };

        if ($channel === null) {
            return;
        }

        $notifiable = $event->notifiable;
        $user = $notifiable instanceof User ? $notifiable : null;
        $reservation = $event->notification instanceof ReservationNotification ? $event->notification->reservation : null;

        MessageLog::query()->forceCreate([
            'user_id' => $user?->id,
            'channel' => $channel,
            'notification' => class_basename($event->notification),
            'reservation_id' => $reservation?->id,
            'recipient' => $channel === 'mail' ? Mask::email($notifiable->email ?? null) : Mask::phone($notifiable->phone ?? null),
            'subject' => $channel === 'mail' ? $this->subject($event) : null,
            'sent_at' => now(),
        ]);
    }

    private function subject(NotificationSent $event): ?string
    {
        try {
            $message = method_exists($event->notification, 'toMail') ? $event->notification->toMail($event->notifiable) : null;

            return $message instanceof MailMessage ? mb_substr((string) $message->subject, 0, 255) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
