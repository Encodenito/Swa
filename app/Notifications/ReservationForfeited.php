<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Models\Reservation;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;

class ReservationForfeited extends ReservationNotification
{
    public function __construct(Reservation $reservation, public int $refundPercent, public ?Payment $refund = null)
    {
        parent::__construct($reservation);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->reservation;
        $mail = $this->base('Pickup deadline missed')
            ->line("The pickup deadline for **{$r->reference_code}** has passed, so the reservation has ended and the items went back on sale.");

        if ($this->refund) {
            $mail->line("As stated in the reservation terms, {$this->refundPercent}% of your downpayment (".Money::format($this->refund->amount).') is being refunded to your original payment method.')
                ->line('Card refunds can take up to 30 days to appear on your statement.');
        } else {
            $mail->line('As stated in the reservation terms, the downpayment is not refunded.');
        }

        return $this->viewAction($mail);
    }
}
