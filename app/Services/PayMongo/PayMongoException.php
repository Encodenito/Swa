<?php

namespace App\Services\PayMongo;

use RuntimeException;
use Throwable;

class PayMongoException extends RuntimeException
{
    /**
     * @param  bool  $definite  true when PayMongo answered and refused (a 4xx with an error code), so
     *                          nothing happened on its side. false when the outcome is unknown
     *                          (timeout, connection error, 5xx, unreadable reply): the request may
     *                          have succeeded, so it must not simply be sent again.
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'unknown',
        ?Throwable $previous = null,
        public readonly bool $definite = false,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
