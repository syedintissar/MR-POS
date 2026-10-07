<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact decimal arithmetic on strings (bcmath). Stock quantities and money are never handled as floats,
 * because 0.1 + 0.2 != 0.3 in floating point and a shop's books must add up to the paisa.
 */
final class Decimal
{
    private const WORK_SCALE = 10;

    /** Normalise anything numeric-looking to a plain decimal string ("12.50", "-3", "0"). */
    public static function of(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (is_float($value)) {
            $value = rtrim(rtrim(number_format($value, self::WORK_SCALE, '.', ''), '0'), '.');
            $value = $value === '' || $value === '-' ? '0' : $value;
        }

        $value = trim((string) $value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Not a decimal number: [{$value}]");
        }

        return $value;
    }

    public static function isNumber(mixed $value): bool
    {
        return (is_string($value) || is_int($value) || is_float($value)) && preg_match('/^-?\d+(\.\d+)?$/', trim((string) $value)) === 1;
    }

    public static function add(string|int|float|null $a, string|int|float|null $b, int $scale = 4): string
    {
        return self::round(bcadd(self::of($a), self::of($b), self::WORK_SCALE), $scale);
    }

    public static function sub(string|int|float|null $a, string|int|float|null $b, int $scale = 4): string
    {
        return self::round(bcsub(self::of($a), self::of($b), self::WORK_SCALE), $scale);
    }

    public static function mul(string|int|float|null $a, string|int|float|null $b, int $scale = 4): string
    {
        return self::round(bcmul(self::of($a), self::of($b), self::WORK_SCALE), $scale);
    }

    public static function div(string|int|float|null $a, string|int|float|null $b, int $scale = 4): string
    {
        if (self::isZero($b)) {
            throw new \DivisionByZeroError('Division by zero.');
        }

        return self::round(bcdiv(self::of($a), self::of($b), self::WORK_SCALE), $scale);
    }

    /** -1, 0 or 1 */
    public static function cmp(string|int|float|null $a, string|int|float|null $b): int
    {
        return bccomp(self::of($a), self::of($b), self::WORK_SCALE);
    }

    public static function isZero(string|int|float|null $a): bool
    {
        return self::cmp($a, '0') === 0;
    }

    public static function isNegative(string|int|float|null $a): bool
    {
        return self::cmp($a, '0') < 0;
    }

    public static function isPositive(string|int|float|null $a): bool
    {
        return self::cmp($a, '0') > 0;
    }

    public static function abs(string|int|float|null $a, int $scale = 4): string
    {
        $a = self::of($a);

        return self::round(ltrim($a, '-'), $scale);
    }

    public static function neg(string|int|float|null $a, int $scale = 4): string
    {
        return self::round(bcmul(self::of($a), '-1', self::WORK_SCALE), $scale);
    }

    /** max(a, 0) */
    public static function nonNegative(string|int|float|null $a, int $scale = 4): string
    {
        return self::isNegative($a) ? self::round('0', $scale) : self::round(self::of($a), $scale);
    }

    /** Round half away from zero to $scale decimals and return a string with exactly that many decimals. */
    public static function round(string|int|float|null $a, int $scale = 4): string
    {
        $a = self::of($a);
        $half = '0.'.str_repeat('0', $scale).'5';
        $rounded = bccomp($a, '0', self::WORK_SCALE) >= 0
            ? bcadd($a, $half, $scale)
            : bcsub($a, $half, $scale);

        // avoid "-0.00"
        return bccomp($rounded, '0', $scale) === 0 ? bcadd('0', '0', $scale) : $rounded;
    }

    /** True when the number has no fractional part ("5", "5.000"). */
    public static function isWhole(string|int|float|null $a): bool
    {
        return bccomp(bcmod(self::of($a), '1', self::WORK_SCALE), '0', self::WORK_SCALE) === 0;
    }

    /** Remove trailing zeros for display: "12.500" -> "12.5", "3.000" -> "3". */
    public static function trim(string|int|float|null $a): string
    {
        $a = self::of($a);

        return str_contains($a, '.') ? rtrim(rtrim($a, '0'), '.') : $a;
    }
}
