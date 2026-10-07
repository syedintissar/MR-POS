<?php

namespace Tests\Unit;

use App\Services\Purchasing\PurchaseCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PurchaseCalculatorTest extends TestCase
{
    private PurchaseCalculator $c;

    protected function setUp(): void
    {
        $this->c = new PurchaseCalculator;
    }

    private function line(string $qty, string $cost, string $discount = '0', string $tax = '0'): array
    {
        return ['quantity' => $qty, 'unit_cost' => $cost, 'discount_amount' => $discount, 'tax_amount' => $tax];
    }

    public function test_line_totals_subtotal_and_grand_total(): void
    {
        $t = $this->c->totals([$this->line('10', '100'), $this->line('4', '50.50', '2.00', '1.00')], '20', '5', '15');

        $this->assertSame(['1000.00', '201.00'], $t['lines']);   // 4 × 50.50 = 202.00 − 2.00 + 1.00
        $this->assertSame('1201.00', $t['subtotal']);
        $this->assertSame('1201.00', $t['grand_total']);        // 1201 − 20 + 5 + 15
    }

    public function test_quantities_with_decimals_round_to_the_paisa(): void
    {
        $t = $this->c->totals([$this->line('2.5', '4.35')]);   // 10.875 → 10.88

        $this->assertSame(['10.88'], $t['lines']);
        $this->assertSame('10.88', $t['grand_total']);
    }

    public function test_discounts_larger_than_the_goods_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->c->totals([$this->line('1', '100', '150')]);
    }

    public function test_an_invoice_discount_larger_than_the_lines_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->c->totals([$this->line('1', '100')], '101');
    }

    public function test_a_purchase_needs_lines(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->c->totals([]);
    }

    public function test_without_extras_the_landed_cost_is_the_unit_cost(): void
    {
        $costs = $this->c->landedUnitCosts([$this->line('10', '100'), $this->line('4', '50')], '0', '0', '0', true);

        $this->assertSame(['100.0000', '50.0000'], $costs);
    }

    public function test_a_line_discount_lowers_the_landed_cost_of_that_line_only(): void
    {
        $costs = $this->c->landedUnitCosts([$this->line('10', '100', '100'), $this->line('4', '50')], '0', '0', '0', true);

        $this->assertSame(['90.0000', '50.0000'], $costs);
    }

    public function test_freight_is_shared_in_proportion_to_the_value_of_each_line(): void
    {
        // lines worth 1000 and 500; freight 150 → 100 and 50
        $costs = $this->c->landedUnitCosts([$this->line('10', '100'), $this->line('5', '100')], '0', '0', '150', true);

        $this->assertSame(['110.0000', '110.0000'], $costs);   // (1000+100)/10 and (500+50)/5
    }

    public function test_an_invoice_discount_is_shared_out_too(): void
    {
        // 1000 and 3000; 200 discount → 50 and 150 off
        $costs = $this->c->landedUnitCosts([$this->line('10', '100'), $this->line('30', '100')], '200', '0', '0', true);

        $this->assertSame(['95.0000', '95.0000'], $costs);
    }

    public function test_tax_is_part_of_the_cost_only_when_the_shop_does_not_claim_it_back(): void
    {
        $lines = [$this->line('10', '100', '0', '180')];

        $this->assertSame(['118.0000'], $this->c->landedUnitCosts($lines, '0', '0', '0', true));
        $this->assertSame(['100.0000'], $this->c->landedUnitCosts($lines, '0', '0', '0', false), 'Claimed tax is not a cost.');
        $this->assertSame(['138.0000'], $this->c->landedUnitCosts($lines, '0', '200', '0', true), 'Invoice-level tax follows the same rule: (1000 goods + 180 line tax + 200 invoice tax) / 10 units');
        $this->assertSame(['100.0000'], $this->c->landedUnitCosts($lines, '0', '200', '0', false), 'Neither tax is a cost when it is claimed back.');
    }

    public function test_the_shares_add_up_to_the_pool_within_the_precision_of_a_stored_unit_cost(): void
    {
        // three equal lines share 100 of freight (33.333... each); unit costs are stored to 4 decimals, so the total can
        // differ from the exact 130.00 by at most half of 0.0001 per unit bought, and never by more.
        $lines = [$this->line('1', '10'), $this->line('1', '10'), $this->line('1', '10')];
        $costs = $this->c->landedUnitCosts($lines, '0', '0', '100', true);

        $total = '0';
        foreach ($costs as $i => $unit) {
            $total = bcadd($total, bcmul($unit, $lines[$i]['quantity'], 4), 4);
        }
        $this->assertLessThanOrEqual(0.00015 + 1e-9, abs((float) $total - 130.0), "Total came out as {$total}");

        // with many units per line the same rounding error stays tiny relative to the money involved
        $big = [$this->line('1000', '10'), $this->line('1000', '10'), $this->line('1000', '10')];
        $costs = $this->c->landedUnitCosts($big, '0', '0', '100', true);
        $exact = 30100.0;
        $got = 0.0;
        foreach ($costs as $unit) {
            $got += (float) $unit * 1000;
        }
        $this->assertLessThanOrEqual(0.2, abs($got - $exact), 'Rounding error is at most 0.00005 per unit');
    }

    public function test_the_pool_is_distributed_exactly_before_unit_costs_are_rounded(): void
    {
        // a pool that divides evenly leaves no remainder at all
        $costs = $this->c->landedUnitCosts([$this->line('2', '10'), $this->line('2', '10')], '0', '0', '8', true);

        $this->assertSame(['12.0000', '12.0000'], $costs);
    }

    public function test_a_bill_with_no_value_shares_freight_by_quantity(): void
    {
        $costs = $this->c->landedUnitCosts([$this->line('3', '0'), $this->line('1', '0')], '0', '0', '40', true);

        $this->assertSame(['10.0000', '10.0000'], $costs);   // 40 over 4 units
    }

    public function test_a_unit_cost_can_never_come_out_negative(): void
    {
        // a huge invoice discount on a nearly-free line cannot produce a negative cost
        $costs = $this->c->landedUnitCosts([$this->line('1', '1000'), $this->line('1', '0.01')], '1000', '0', '0', true);

        foreach ($costs as $c) {
            $this->assertGreaterThanOrEqual(0, (float) $c);
        }
    }

    public function test_fractional_quantities_get_the_right_unit_cost(): void
    {
        // 2.5 g bought for 10.00 in total plus 5.00 freight → 6.00 per g
        $costs = $this->c->landedUnitCosts([$this->line('2.5', '4')], '0', '0', '5', true);

        $this->assertSame(['6.0000'], $costs);
    }
}
