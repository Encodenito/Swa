<?php

namespace App\Enums;

enum PaymentType: string
{
    case Downpayment = 'downpayment';
    case Balance = 'balance';
    case Full = 'full';
    case Refund = 'refund';
}
