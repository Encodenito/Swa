<?php

namespace App\Notifications;

use App\Notifications\Channels\SmsChannel;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;

class PickupReminder extends ReservationNotification
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable->phone ? ['mail', SmsChannel::class] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->reservation;

        return $this->viewAction($this->base('Reminder: pick up your reservation')
            ->line("Your reservation **{$r->reference_code}** is waiting for you.")
            ->line("Please pick it up by **{$this->deadline()}**.")
            ->line('Balance to pay at pickup: '.Money::format($r->balance).'.'));
    }

    public function toSms(object $notifiable): string
    {
        return "SWA I.T. Supplies: please pick up {$this->reservation->reference_code} by {$this->deadline()}. Balance "
            .Money::format($this->reservation->balance).'.';
    }
}
