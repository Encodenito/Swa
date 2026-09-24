<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    case Ordered = 'ordered';
    case Received = 'received';
    case Cancelled = 'cancelled';
}
