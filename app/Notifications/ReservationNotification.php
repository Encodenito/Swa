<?php

namespace App\Notifications;

use App\Models\Reservation;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Shared base for reservation emails: queued, sent after commit, 3 tries with backoff (spec H).
 * Only ever sent to the reservation's own account holder; walk-ins have no account.
 */
abstract class ReservationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public Reservation $reservation)
    {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    protected function base(string $subject): MailMessage
    {
        return (new MailMessage)
            ->subject("{$subject} ({$this->reservation->reference_code})")
            ->greeting("Hi {$this->reservation->user?->name},");
    }

    protected function itemLines(MailMessage $mail): MailMessage
    {
        foreach ($this->reservation->loadMissing('lines.item')->lines as $line) {
            $mail->line("• {$line->quantity} × {$line->item?->name} at ".Money::format($line->unit_price));
        }

        return $mail;
    }

    protected function deadline(): string
    {
        return $this->reservation->pickup_by?->timezone(config('app.timezone'))->format('l, F j, Y g:i A') ?? '—';
    }

    protected function viewAction(MailMessage $mail): MailMessage
    {
        return $mail->action('View your reservation', route('account.reservations.show', $this->reservation));
    }
}
