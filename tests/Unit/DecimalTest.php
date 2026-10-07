<?php

namespace Tests\Unit;

use App\Support\Decimal;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DecimalTest extends TestCase
{
    public function test_it_avoids_floating_point_errors(): void
    {
        $this->assertNotSame(0.3, 0.1 + 0.2);                       // plain floats get this wrong
        $this->assertSame('0.3000', Decimal::add(0.1, 0.2));
        $this->assertSame('0.3000', Decimal::add('0.1', '0.2'));
        $this->assertSame('0', Decimal::of(0.0));
    }

    public function test_arithmetic_rounds_half_away_from_zero(): void
    {
        $this->assertSame('59.97', Decimal::mul('19.99', '3', 2));
        $this->assertSame('3.3333', Decimal::div('10', '3', 4));
        $this->assertSame('0.67', Decimal::div('2', '3', 2));
        $this->assertSame('2.68', Decimal::round('2.675', 2));      // half-up, unlike round(2.675, 2) in float maths on some builds
        $this->assertSame('3', Decimal::round('2.5', 0));
        $this->assertSame('-3', Decimal::round('-2.5', 0));
        $this->assertSame('0.00', Decimal::round('-0.001', 2), 'no negative zero');
        $this->assertSame('-1.50', Decimal::sub('1.00', '2.50', 2));
    }

    public function test_comparisons_and_signs(): void
    {
        $this->assertSame(0, Decimal::cmp('1.0', '1.000'));
        $this->assertSame(-1, Decimal::cmp('1.9', '2'));
        $this->assertTrue(Decimal::isZero('0.000'));
        $this->assertTrue(Decimal::isNegative('-0.001'));
        $this->assertTrue(Decimal::isPositive('0.001'));
        $this->assertSame('5.000', Decimal::abs('-5', 3));
        $this->assertSame('-5.000', Decimal::neg('5', 3));
        $this->assertSame('0.000', Decimal::nonNegative('-4', 3));
        $this->assertSame('4.000', Decimal::nonNegative('4', 3));
    }

    public function test_whole_number_checks_and_display_trimming(): void
    {
        $this->assertTrue(Decimal::isWhole('5'));
        $this->assertTrue(Decimal::isWhole('5.000'));
        $this->assertFalse(Decimal::isWhole('5.001'));
        $this->assertSame('12.5', Decimal::trim('12.500'));
        $this->assertSame('3', Decimal::trim('3.000'));
        $this->assertSame('0', Decimal::trim('0.000'));
    }

    public function test_bad_input_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Decimal::of('12abc');
    }

    public function test_division_by_zero_is_refused(): void
    {
        $this->expectException(\DivisionByZeroError::class);
        Decimal::div('1', '0');
    }

    public function test_is_number_accepts_only_plain_decimals(): void
    {
        foreach (['1', '1.5', '-2', '0.000'] as $ok) {
            $this->assertTrue(Decimal::isNumber($ok), $ok);
        }
        foreach (['', 'abc', '1,5', '1e3', '--1', null, [], '1.'] as $bad) {
            $this->assertFalse(Decimal::isNumber($bad), var_export($bad, true));
        }
    }
}
