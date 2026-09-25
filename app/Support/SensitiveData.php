<?php

namespace App\Support;

/**
 * Detects and masks payment-card numbers in free text (spec K.12: never store card data).
 * Digits may be separated by spaces, dashes, dots or slashes. A Luhn check avoids flagging
 * phone numbers and reference codes.
 */
final class SensitiveData
{
    private const CANDIDATE = '/\d(?:[ .\/-]?\d){12,18}/';

    public static function containsCardNumber(string $text): bool
    {
        return (bool) preg_match_all(self::CANDIDATE, $text, $matches)
            && collect($matches[0])->contains(fn (string $m) => self::luhn(preg_replace('/\D/', '', $m)));
    }

    /**
     * Replace card-like numbers with a masked form that keeps only the last 4 digits.
     */
    public static function maskCardNumbers(string $text): string
    {
        return preg_replace_callback(self::CANDIDATE, function (array $m) {
            $digits = preg_replace('/\D/', '', $m[0]);

            return self::luhn($digits) ? '[card number removed, ends '.substr($digits, -4).']' : $m[0];
        }, $text) ?? $text;
    }

    private static function luhn(string $digits): bool
    {
        $length = strlen($digits);

        if ($length < 13 || $length > 19) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < $length; $i++) {
            $d = (int) $digits[$length - 1 - $i];

            if ($i % 2 === 1) {
                $d *= 2;
                $d = $d > 9 ? $d - 9 : $d;
            }

            $sum += $d;
        }

        return $sum % 10 === 0;
    }
}
