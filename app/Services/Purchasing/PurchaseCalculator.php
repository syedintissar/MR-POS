<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Support\Decimal;
use InvalidArgumentException;

/**
 * Money arithmetic for a purchase. Pure functions, no database.
 *
 *   line total   = quantity × unit cost (rounded to the paisa) − line discount + line tax
 *   subtotal     = sum of the line totals
 *   grand total  = subtotal − invoice discount + invoice tax + additional cost (freight, loading ...)
 *
 * LANDED cost: what one unit really cost once its share of the invoice discount, the extra costs and (when the shop does not
 * claim tax back) the tax is included. The shares are split in proportion to each line's value, and the last line takes
 * the rounding remainder, so nothing is lost or invented.
 */
final class PurchaseCalculator
{
    /**
     * @param  list<array{quantity: string, unit_cost: string, discount_amount?: string|null, tax_amount?: string|null}>  $lines
     * @return array{lines: list<string>, subtotal: string, grand_total: string}
     */
    public function totals(array $lines, string $invoiceDiscount = '0', string $invoiceTax = '0', string $additionalCost = '0'): array
    {
        if ($lines === []) {
            throw new InvalidArgumentException('A purchase needs at least one line.');
        }

        $lineTotals = [];
        $subtotal = '0.00';

        foreach ($lines as $i => $l) {
            $gross = Decimal::mul($l['quantity'], $l['unit_cost'], 2);
            $discount = Decimal::round($l['discount_amount'] ?? '0', 2);
            $tax = Decimal::round($l['tax_amount'] ?? '0', 2);

            if (Decimal::cmp($discount, $gross) > 0) {
                throw new InvalidArgumentException('Line '.($i + 1).': the discount is larger than the amount of the line.');
            }

            $total = Decimal::add(Decimal::sub($gross, $discount, 2), $tax, 2);
            $lineTotals[] = $total;
            $subtotal = Decimal::add($subtotal, $total, 2);
        }

        $invoiceDiscount = Decimal::round($invoiceDiscount, 2);
        if (Decimal::cmp($invoiceDiscount, $subtotal) > 0) {
            throw new InvalidArgumentException('The invoice discount is larger than the total of the lines.');
        }

        $grand = Decimal::add(Decimal::add(Decimal::sub($subtotal, $invoiceDiscount, 2), $invoiceTax, 2), $additionalCost, 2);

        return ['lines' => $lineTotals, 'subtotal' => $subtotal, 'grand_total' => $grand];
    }

    /**
     * @param  list<array{quantity: string, unit_cost: string, discount_amount?: string|null, tax_amount?: string|null}>  $lines
     * @return list<string> landed cost of ONE unit for each line, 4 decimals
     */
    public function landedUnitCosts(array $lines, string $invoiceDiscount, string $invoiceTax, string $additionalCost, bool $taxInCost): array
    {
        // value of each line before any invoice-level share (kept at 8 decimals, not rounded to the paisa)
        $base = [];
        $sumBase = '0';
        foreach ($lines as $l) {
            $value = Decimal::sub(Decimal::mul($l['quantity'], $l['unit_cost'], 8), Decimal::round($l['discount_amount'] ?? '0', 2), 8);
            if ($taxInCost) {
                $value = Decimal::add($value, Decimal::round($l['tax_amount'] ?? '0', 2), 8);
            }
            $base[] = $value;
            $sumBase = Decimal::add($sumBase, $value, 8);
        }

        // what has to be shared out over the lines
        $pool = Decimal::sub(Decimal::add(Decimal::round($additionalCost, 2), $taxInCost ? Decimal::round($invoiceTax, 2) : '0', 8), Decimal::round($invoiceDiscount, 2), 8);

        // weights: by value, or by quantity when the whole bill has no value
        $weights = Decimal::isPositive($sumBase) ? $base : array_map(fn ($l) => Decimal::round($l['quantity'], 8), $lines);
        $sumWeights = array_reduce($weights, fn ($c, $w) => Decimal::add($c, $w, 8), '0');

        $costs = [];
        $given = '0';
        $last = count($lines) - 1;

        foreach ($lines as $i => $l) {
            if ($i === $last) {
                $share = Decimal::sub($pool, $given, 8);                          // the remainder: nothing lost to rounding
            } else {
                $share = Decimal::isZero($sumWeights) ? '0' : Decimal::div(Decimal::mul($pool, $weights[$i], 8), $sumWeights, 8);
                $given = Decimal::add($given, $share, 8);
            }

            $lineValue = Decimal::add($base[$i], $share, 8);
            $unit = Decimal::div($lineValue, $l['quantity'], 4);
            $costs[] = Decimal::isNegative($unit) ? '0.0000' : $unit;
        }

        return $costs;
    }
}
