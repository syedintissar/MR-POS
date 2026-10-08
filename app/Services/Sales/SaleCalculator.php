<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Support\Decimal;
use InvalidArgumentException;

/**
 * Money arithmetic for a sale. Pure functions on exact decimal strings, no database. The browser shows the same figures as a
 * convenience, but this class decides what is charged.
 *
 *   gross          = quantity x unit price (to the paisa)
 *   after line     = gross - line discount
 *   invoice share  = this line's part of the invoice discount (split in proportion to "after line", exact to the paisa)
 *   exclusive tax  : net = after line - share;                  tax = net x rate;        line total = net + tax
 *   inclusive tax  : paid = after line - share (tax is inside); net = paid / (1 + rate); tax = paid - net;  line total = paid
 *   grand total    = sum of the line totals, then optionally rounded to the nearest Rs. 1 / 5 / 10 (the difference is the round-off)
 *
 * The invoice discount is shared with the "largest remainder" method: every line gets the whole paisas it is entitled to and
 * the few paisas left over go to the lines with the biggest fractional parts, so the shares add up to the discount exactly
 * and no share can ever exceed its line.
 */
final class SaleCalculator
{
    /**
     * @param  list<array{quantity: string, unit_price: string, discount_amount?: string|null, discount_percent?: string|null, tax_rate?: string|null}>  $lines
     * @param  array{discount_amount?: string|null, discount_percent?: string|null, tax_inclusive?: bool, round_to?: int|string|null}  $options
     * @return array{
     *     lines: list<array{gross: string, line_discount: string, invoice_discount_share: string, tax_rate: string, net_amount: string, tax_amount: string, line_total: string}>,
     *     items_total: string, line_discount_total: string, invoice_discount_amount: string, invoice_discount_percent: string|null,
     *     net_total: string, tax_total: string, before_round: string, round_off: string, grand_total: string
     * }
     */
    public function calculate(array $lines, array $options = []): array
    {
        if ($lines === []) {
            throw new InvalidArgumentException('A sale needs at least one line.');
        }

        $inclusive = (bool) ($options['tax_inclusive'] ?? false);

        // 1. gross and line discount
        $gross = [];
        $lineDiscount = [];
        $after = [];
        foreach ($lines as $i => $l) {
            $n = $i + 1;
            $g = Decimal::mul($l['quantity'], $l['unit_price'], 2);

            $percent = $l['discount_percent'] ?? null;
            if ($percent !== null && $percent !== '' && Decimal::isPositive($percent)) {
                if (Decimal::cmp($percent, '100') > 0) {
                    throw new InvalidArgumentException("Line {$n}: a discount cannot be more than 100%.");
                }
                $d = Decimal::round(bcdiv(bcmul($g, $percent, 8), '100', 8), 2);
            } else {
                $d = Decimal::round($l['discount_amount'] ?? '0', 2);
            }

            if (Decimal::isNegative($d)) {
                throw new InvalidArgumentException("Line {$n}: a discount cannot be negative.");
            }
            if (Decimal::cmp($d, $g) > 0) {
                throw new InvalidArgumentException("Line {$n}: the discount is larger than the amount of the line.");
            }

            $gross[$i] = $g;
            $lineDiscount[$i] = $d;
            $after[$i] = Decimal::sub($g, $d, 2);
        }
        $sumAfter = array_reduce($after, fn ($c, $a) => Decimal::add($c, $a, 2), '0.00');

        // 2. invoice discount
        $invoicePercent = $options['discount_percent'] ?? null;
        $invoicePercent = ($invoicePercent === null || $invoicePercent === '' || ! Decimal::isPositive($invoicePercent)) ? null : Decimal::round($invoicePercent, 2);
        if ($invoicePercent !== null) {
            if (Decimal::cmp($invoicePercent, '100') > 0) {
                throw new InvalidArgumentException('The invoice discount cannot be more than 100%.');
            }
            $invoiceDiscount = Decimal::round(bcdiv(bcmul($sumAfter, $invoicePercent, 8), '100', 8), 2);
        } else {
            $invoiceDiscount = Decimal::round($options['discount_amount'] ?? '0', 2);
        }
        if (Decimal::isNegative($invoiceDiscount)) {
            throw new InvalidArgumentException('The invoice discount cannot be negative.');
        }
        if (Decimal::cmp($invoiceDiscount, $sumAfter) > 0) {
            throw new InvalidArgumentException('The invoice discount is larger than the total of the lines.');
        }

        $shares = $this->share($invoiceDiscount, $after);

        // 3. tax and totals
        $out = [];
        $itemsTotal = $lineDiscountTotal = $netTotal = $taxTotal = $beforeRound = '0.00';
        foreach ($lines as $i => $l) {
            $rate = Decimal::round($l['tax_rate'] ?? '0', 2);
            $paid = Decimal::sub($after[$i], $shares[$i], 2);   // after both discounts

            if ($inclusive && Decimal::isPositive($rate)) {
                $net = Decimal::round(bcdiv($paid, bcadd('1', bcdiv($rate, '100', 8), 8), 8), 2);
                $tax = Decimal::sub($paid, $net, 2);
            } else {
                $net = $paid;
                $tax = Decimal::round(bcdiv(bcmul($net, $rate, 8), '100', 8), 2);
            }
            $total = Decimal::add($net, $tax, 2);

            $out[] = [
                'gross' => $gross[$i], 'line_discount' => $lineDiscount[$i], 'invoice_discount_share' => $shares[$i], 'tax_rate' => $rate,
                'net_amount' => $net, 'tax_amount' => $tax, 'line_total' => $total,
            ];

            $itemsTotal = Decimal::add($itemsTotal, $gross[$i], 2);
            $lineDiscountTotal = Decimal::add($lineDiscountTotal, $lineDiscount[$i], 2);
            $netTotal = Decimal::add($netTotal, $net, 2);
            $taxTotal = Decimal::add($taxTotal, $tax, 2);
            $beforeRound = Decimal::add($beforeRound, $total, 2);
        }

        // 4. round-off of the grand total
        $step = (int) ($options['round_to'] ?? 0);
        if ($step > 0) {
            $grand = Decimal::mul(Decimal::round(bcdiv($beforeRound, (string) $step, 8), 0), (string) $step, 2);
        } else {
            $grand = $beforeRound;
        }

        return [
            'lines' => $out,
            'items_total' => $itemsTotal,
            'line_discount_total' => $lineDiscountTotal,
            'invoice_discount_amount' => $invoiceDiscount,
            'invoice_discount_percent' => $invoicePercent,
            'net_total' => $netTotal,
            'tax_total' => $taxTotal,
            'before_round' => $beforeRound,
            'round_off' => Decimal::sub($grand, $beforeRound, 2),
            'grand_total' => $grand,
        ];
    }

    /**
     * Splits $amount over the weights, in whole paisas, so the parts add up to $amount exactly (largest remainder method).
     *
     * @param  array<int, string>  $weights  amounts with two decimals
     * @return array<int, string> same keys, two decimals
     */
    public function share(string $amount, array $weights): array
    {
        $shares = array_fill_keys(array_keys($weights), '0.00');
        $totalCents = bcmul(array_reduce($weights, fn ($c, $w) => bcadd($c, bcmul($w, '100', 0), 0), '0'), '1', 0);

        $amountCents = bcmul(Decimal::round($amount, 2), '100', 0);
        if (bccomp($amountCents, '0', 0) === 0 || bccomp($totalCents, '0', 0) === 0) {
            return $shares;
        }

        $base = [];
        $remainder = [];
        $given = '0';
        foreach ($weights as $key => $w) {
            $product = bcmul($amountCents, bcmul($w, '100', 0), 0);
            $base[$key] = bcdiv($product, $totalCents, 0);       // whole paisas, rounded down
            $remainder[$key] = bcmod($product, $totalCents);     // what was rounded away (kept to rank the lines)
            $given = bcadd($given, $base[$key], 0);
        }

        // hand the leftover paisas, one each, to the biggest remainders (the earlier line wins a tie)
        $left = (int) bcsub($amountCents, $given, 0);
        $order = array_keys($weights);
        usort($order, fn ($a, $b) => bccomp($remainder[$b], $remainder[$a], 0) ?: array_search($a, array_keys($weights), true) <=> array_search($b, array_keys($weights), true));
        foreach ($order as $key) {
            if ($left <= 0) {
                break;
            }
            if (bccomp($remainder[$key], '0', 0) > 0) {
                $base[$key] = bcadd($base[$key], '1', 0);
                $left--;
            }
        }

        foreach ($base as $key => $cents) {
            $shares[$key] = bcdiv($cents, '100', 2);
        }

        return $shares;
    }
}
