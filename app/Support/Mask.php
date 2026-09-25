<?php

namespace App\Support;

/**
 * Partial masking for contact details in logs and the message log (spec K.14: minimal personal data).
 */
final class Mask
{
    public static function email(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return $email === null ? null : self::phone($email);
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    public static function phone(?string $phone): ?string
    {
        if ($phone === null || strlen($phone) < 6) {
            return $phone === null ? null : '***';
        }

        return substr($phone, 0, 4).str_repeat('*', max(1, strlen($phone) - 6)).substr($phone, -2);
    }
}
