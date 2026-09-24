<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case PayMongo = 'paymongo';
    case Cash = 'cash';
}
