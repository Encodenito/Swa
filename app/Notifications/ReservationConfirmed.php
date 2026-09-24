<?php

namespace App\Notifications;

use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;

class ReservationConfirmed extends ReservationNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->reservation;
        $mail = $this->base('Reservation confirmed')
            ->line('We received your downpayment of '.Money::format($r->downpayment_amount).'. Your items are reserved.')
            ->line("Reference code: **{$r->reference_code}**. Show it at the counter.");

        $this->itemLines($mail);

        return $this->viewAction($mail
            ->line('Balance to pay at pickup: '.Money::format($r->balance).'.')
            ->line("Please pick up by **{$this->deadline()}**.")
            ->line('You can cancel for free (full refund) until we start preparing your order.'));
    }
}
