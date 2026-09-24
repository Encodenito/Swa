<?php

namespace App\Notifications;

use App\Enums\SettingKey;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;

class ReservationProcessing extends ReservationNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->reservation;
        $percent = setting(SettingKey::ForfeitRefundPercent);

        return $this->viewAction($this->base('We are preparing your order')
            ->line('We have started preparing your order, so it can no longer be cancelled.')
            ->line("Please pick up by **{$this->deadline()}** and bring your reference code **{$r->reference_code}**.")
            ->line('Balance to pay at pickup: '.Money::format($r->balance).'.')
            ->line("If you miss the pickup deadline, only {$percent}% of your downpayment is refunded."));
    }
}
