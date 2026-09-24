<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Peso amounts as decimal strings with 2 places ("1234.50"), computed with bcmath.
 * Never use floats for money. Convert to integer centavos only at the PayMongo boundary.
 */
final class Money
{
    private const SCALE = 2;

    public static function of(string|int $amount): string
    {
        $amount = (string) $amount;

        if (! preg_match('/^-?\d+(\.\d+)?$/', $amount)) {
            throw new InvalidArgumentException("Not a money amount: [{$amount}]");
        }

        return self::round($amount);
    }

    public static function zero(): string
    {
        return '0.00';
    }

    public static function add(string ...$amounts): string
    {
        $sum = '0';

        foreach ($amounts as $amount) {
            $sum = bcadd($sum, self::of($amount), self::SCALE);
        }

        return self::of($sum);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub(self::of($a), self::of($b), self::SCALE);
    }

    public static function mul(string $amount, int $quantity): string
    {
        return bcmul(self::of($amount), (string) $quantity, self::SCALE);
    }

    /**
     * $percent of $amount, rounded half up to the centavo.
     */
    public static function percent(string $amount, int $percent): string
    {
        return self::round(bcdiv(bcmul(self::of($amount), (string) $percent, 6), '100', 6));
    }

    public static function equals(string $a, string $b): bool
    {
        return bccomp(self::of($a), self::of($b), self::SCALE) === 0;
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp(self::of($a), self::of($b), self::SCALE);
    }

    public static function toCentavos(string $amount): int
    {
        return (int) bcmul(self::of($amount), '100', 0);
    }

    public static function fromCentavos(int $centavos): string
    {
        return bcdiv((string) $centavos, '100', self::SCALE);
    }

    /**
     * Round half away from zero to 2 places.
     */
    private static function round(string $value): string
    {
        $offset = str_starts_with($value, '-') ? '-0.005' : '0.005';

        $rounded = bcadd($value, $offset, self::SCALE);

        return $rounded === '-0.00' ? '0.00' : $rounded;
    }
}
