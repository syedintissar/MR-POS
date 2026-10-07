<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;

/** Display formatting for money (never used for arithmetic: all arithmetic is done with Decimal on exact strings). */
final class Money
{
    /** "12,345.50" (or "12,346" when the shop shows no decimals). Negative amounts keep their minus sign. */
    public static function format(string|int|float|null $amount, ?int $decimals = null): string
    {
        $decimals ??= self::decimals();

        return number_format((float) Decimal::round(Decimal::of($amount), $decimals), $decimals, '.', ',');
    }

    /** "Rs. 12,345.50" */
    public static function withSymbol(string|int|float|null $amount, ?int $decimals = null): string
    {
        return self::symbol().' '.self::format($amount, $decimals);
    }

    public static function symbol(): string
    {
        return app(SettingsService::class)->string('currency.symbol') ?: 'Rs.';
    }

    private static function decimals(): int
    {
        return app(SettingsService::class)->string('currency.decimals') === '0' ? 0 : 2;
    }
}
