<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Support\Decimal;

/**
 * Moving weighted-average cost (docs/ARCHITECTURE.md §6.4). Pure functions: no database access.
 *
 * Costs have 4 decimals, quantities 3. S = stock before the movement, avg = average cost before it.
 */
final class CostingService
{
    /**
     * Stock comes in at a known cost (purchase, opening stock, restocked return, ...).
     * avg' = (max(S,0) * avg + q * c) / (max(S,0) + q)
     */
    public function blend(string $stockBefore, string $avgBefore, string $quantityIn, string $unitCost): string
    {
        $base = Decimal::nonNegative($stockBefore, 3);
        $newQty = Decimal::add($base, $quantityIn, 3);

        if (! Decimal::isPositive($newQty)) {
            return Decimal::round($unitCost, 4);
        }

        $value = Decimal::add(Decimal::mul($base, $avgBefore, 8), Decimal::mul($quantityIn, $unitCost, 8), 8);

        return Decimal::div($value, $newQty, 4);
    }

    /**
     * Stock goes back out at the cost it came in (purchase return, void of a purchase).
     * If anything is left: avg' = (S * avg - q * c) / (S - q), never below zero. If nothing is left the average is kept.
     */
    public function unwind(string $stockBefore, string $avgBefore, string $quantityOut, string $unitCost): string
    {
        $remaining = Decimal::sub($stockBefore, $quantityOut, 3);

        if (! Decimal::isPositive($remaining) || ! Decimal::isPositive($stockBefore)) {
            return Decimal::round($avgBefore, 4);
        }

        $value = Decimal::sub(Decimal::mul($stockBefore, $avgBefore, 8), Decimal::mul($quantityOut, $unitCost, 8), 8);

        return Decimal::nonNegative(Decimal::div($value, $remaining, 4), 4);
    }
}
