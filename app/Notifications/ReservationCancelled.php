<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Models\Reservation;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;

class ReservationCancelled extends ReservationNotification
{
    public function __construct(Reservation $reservation, public ?Payment $refund = null)
    {
        parent::__construct($reservation);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->reservation;
        $mail = $this->base('Reservation cancelled')
            ->line("Your reservation **{$r->reference_code}** has been cancelled.");

        if ($r->cancel_reason) {
            $mail->line("Reason: {$r->cancel_reason}");
        }

        if ($this->refund) {
            $mail->line('A refund of '.Money::format($this->refund->amount).' is on its way to your original payment method.')
                ->line('GCash refunds usually arrive within a day. Card refunds can take up to 30 days to appear on your statement.');
        } else {
            $mail->line('No payment is being refunded for this reservation.');
        }

        return $this->viewAction($mail);
    }
}
