<?php

namespace App\Support;

use App\Models\Reservation;

/**
 * SWA-XXXXXXXX codes: 8 characters from an alphabet without look-alikes (no 0/O, 1/I).
 */
final class ReferenceCode
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function generate(): string
    {
        do {
            $code = 'SWA-';

            for ($i = 0; $i < 8; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (Reservation::query()->where('reference_code', $code)->exists());

        return $code;
    }
}
