<?php

namespace App\Enums;

enum MovementType: string
{
    case Received = 'received';
    case Reserved = 'reserved';
    case Released = 'released';
    case Sold = 'sold';
    case Damaged = 'damaged';
    case Adjusted = 'adjusted';

    /**
     * Direction of the stored quantity: +1 adds stock, -1 removes it, 0 means either (adjusted).
     */
    public function sign(): int
    {
        return match ($this) {
            self::Received, self::Released => 1,
            self::Reserved, self::Sold, self::Damaged => -1,
            self::Adjusted => 0,
        };
    }
}
