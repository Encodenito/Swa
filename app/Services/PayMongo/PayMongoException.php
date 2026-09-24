<?php

namespace App\Services\PayMongo;

use RuntimeException;
use Throwable;

class PayMongoException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'unknown', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
