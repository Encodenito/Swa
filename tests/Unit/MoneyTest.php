<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_arithmetic_is_exact_to_the_centavo(): void
    {
        $this->assertSame('0.30', Money::add('0.10', '0.20'));
        $this->assertSame('1234.50', Money::of('1234.5'));
        $this->assertSame('3703.50', Money::mul('1234.50', 3));
        $this->assertSame('1000.25', Money::sub('1234.50', '234.25'));
    }

    public function test_percent_rounds_half_up(): void
    {
        $this->assertSame('370.35', Money::percent('1234.50', 30)); // 370.350
        $this->assertSame('0.33', Money::percent('1.10', 30));      // 0.330
        $this->assertSame('0.01', Money::percent('0.05', 10));      // 0.005 -> 0.01
        $this->assertSame('0.00', Money::percent('0.04', 10));      // 0.004 -> 0.00
    }

    public function test_centavo_conversion(): void
    {
        $this->assertSame(123450, Money::toCentavos('1234.50'));
        $this->assertSame('1234.50', Money::fromCentavos(123450));
        $this->assertSame(1, Money::toCentavos('0.01'));
    }

    public function test_comparison(): void
    {
        $this->assertTrue(Money::equals('100', '100.00'));
        $this->assertFalse(Money::equals('100.00', '100.01'));
        $this->assertSame(-1, Money::compare('99.99', '100'));
    }

    public function test_rejects_non_numeric_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('1e3');
    }
}
