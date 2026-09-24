<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly int $itemId,
        public readonly string $itemName,
        public readonly int $available,
    ) {
        parent::__construct("Only {$available} left of {$itemName}.");
    }
}
