<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Paid = 'paid';
    case NeedsReview = 'needs_review';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';
    case RefundFailed = 'refund_failed';
}
