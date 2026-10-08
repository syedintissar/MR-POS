<?php

namespace Tests\Unit;

use App\Services\Sales\SaleCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SaleCalculatorTest extends TestCase
{
    private SaleCalculator $c;

    protected function setUp(): void
    {
        $this->c = new SaleCalculator;
    }

    private function line(string $qty, string $price, array $extra = []): array
    {
        return ['quantity' => $qty, 'unit_price' => $price] + $extra;
    }

    public function test_a_plain_sale_is_quantity_times_price(): void
    {
        $r = $this->c->calculate([$this->line('2', '500'), $this->line('1', '99.50')]);

        $this->assertSame('1099.50', $r['items_total']);
        $this->assertSame('1099.50', $r['grand_total']);
        $this->assertSame('0.00', $r['round_off']);
        $this->assertSame('0.00', $r['tax_total']);
        $this->assertSame(['1000.00', '99.50'], array_column($r['lines'], 'line_total'));
        $this->assertNull($r['invoice_discount_percent']);
    }

    public function test_fractional_quantities_round_to_the_paisa(): void
    {
        $r = $this->c->calculate([$this->line('2.5', '4.35')]);   // 10.875

        $this->assertSame('10.88', $r['grand_total']);
    }

    public function test_a_line_discount_in_percent_or_amount(): void
    {
        $r = $this->c->calculate([
            $this->line('1', '1000', ['discount_percent' => '10']),
            $this->line('1', '200', ['discount_amount' => '25']),
        ]);

        $this->assertSame(['100.00', '25.00'], array_column($r['lines'], 'line_discount'));
        $this->assertSame('125.00', $r['line_discount_total']);
        $this->assertSame('1075.00', $r['grand_total']);   // 900 + 175
    }

    public function test_a_percentage_wins_over_an_amount_on_the_same_line(): void
    {
        $r = $this->c->calculate([$this->line('1', '1000', ['discount_percent' => '5', 'discount_amount' => '400'])]);

        $this->assertSame('50.00', $r['lines'][0]['line_discount']);
    }

    public function test_the_invoice_discount_is_shared_in_proportion_to_the_lines(): void
    {
        $r = $this->c->calculate([$this->line('1', '600'), $this->line('1', '300')], ['discount_amount' => '90']);

        $this->assertSame(['60.00', '30.00'], array_column($r['lines'], 'invoice_discount_share'));
        $this->assertSame('90.00', $r['invoice_discount_amount']);
        $this->assertSame('810.00', $r['grand_total']);
        $this->assertSame(['540.00', '270.00'], array_column($r['lines'], 'net_amount'));
    }

    public function test_the_shares_always_add_up_to_the_discount_to_the_paisa(): void
    {
        // 100.00 over three equal lines cannot be split evenly: 33.34 + 33.33 + 33.33
        $r = $this->c->calculate([$this->line('1', '100'), $this->line('1', '100'), $this->line('1', '100')], ['discount_amount' => '100']);

        $this->assertSame(['33.34', '33.33', '33.33'], array_column($r['lines'], 'invoice_discount_share'));
        $this->assertSame('200.00', $r['grand_total']);
    }

    public function test_the_invoice_discount_percentage_is_taken_from_the_amount_after_line_discounts(): void
    {
        $r = $this->c->calculate([$this->line('1', '1000', ['discount_amount' => '200'])], ['discount_percent' => '10']);

        $this->assertSame('80.00', $r['invoice_discount_amount']);   // 10% of 800
        $this->assertSame('10.00', $r['invoice_discount_percent']);
        $this->assertSame('720.00', $r['grand_total']);
    }

    public function test_a_discount_can_never_make_a_line_or_a_sale_negative(): void
    {
        foreach ([
            [[$this->line('1', '100', ['discount_amount' => '100.01'])], []],
            [[$this->line('1', '100', ['discount_percent' => '100.5'])], []],
            [[$this->line('1', '100')], ['discount_amount' => '100.01']],
            [[$this->line('1', '100')], ['discount_percent' => '101']],
            [[$this->line('1', '100', ['discount_amount' => '-1'])], []],
            [[$this->line('1', '100')], ['discount_amount' => '-5']],
        ] as $i => [$lines, $options]) {
            try {
                $this->c->calculate($lines, $options);
                $this->fail("Case {$i} was accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_whole_100_percent_discount_makes_a_free_sale(): void
    {
        $r = $this->c->calculate([$this->line('3', '50')], ['discount_percent' => '100']);

        $this->assertSame('0.00', $r['grand_total']);
        $this->assertSame('150.00', $r['lines'][0]['invoice_discount_share']);
    }

    public function test_a_sale_needs_lines(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->c->calculate([]);
    }

    public function test_tax_added_on_top(): void
    {
        $r = $this->c->calculate([$this->line('1', '1000', ['tax_rate' => '17'])]);

        $this->assertSame('170.00', $r['tax_total']);
        $this->assertSame('1000.00', $r['net_total']);
        $this->assertSame('1170.00', $r['grand_total']);
    }

    public function test_tax_that_is_already_inside_the_price(): void
    {
        $r = $this->c->calculate([$this->line('1', '1170', ['tax_rate' => '17'])], ['tax_inclusive' => true]);

        $this->assertSame('1000.00', $r['lines'][0]['net_amount']);
        $this->assertSame('170.00', $r['lines'][0]['tax_amount']);
        $this->assertSame('1170.00', $r['grand_total'], 'The customer pays the price shown.');
    }

    public function test_tax_is_calculated_after_the_discounts(): void
    {
        $r = $this->c->calculate([$this->line('1', '1000', ['tax_rate' => '10', 'discount_amount' => '100'])], ['discount_amount' => '100']);

        $this->assertSame('800.00', $r['net_total']);
        $this->assertSame('80.00', $r['tax_total']);
        $this->assertSame('880.00', $r['grand_total']);
    }

    public function test_each_line_uses_its_own_tax_rate(): void
    {
        $r = $this->c->calculate([$this->line('1', '1000', ['tax_rate' => '17']), $this->line('1', '1000', ['tax_rate' => '0']), $this->line('1', '1000')]);

        $this->assertSame(['170.00', '0.00', '0.00'], array_column($r['lines'], 'tax_amount'));
    }

    public function test_inclusive_tax_with_a_zero_rate_changes_nothing(): void
    {
        $r = $this->c->calculate([$this->line('1', '500', ['tax_rate' => '0'])], ['tax_inclusive' => true]);

        $this->assertSame('500.00', $r['lines'][0]['net_amount']);
        $this->assertSame('0.00', $r['tax_total']);
    }

    public function test_round_off_to_the_nearest_rupee_five_or_ten(): void
    {
        $at = fn (string $price, int $step) => $this->c->calculate([$this->line('1', $price)], ['round_to' => $step]);

        $this->assertSame(['100.00', '0.50'], [$at('99.50', 1)['grand_total'], $at('99.50', 1)['round_off']]);   // half goes up
        $this->assertSame(['99.00', '-0.40'], [$at('99.40', 1)['grand_total'], $at('99.40', 1)['round_off']]);
        $this->assertSame(['1230.00', '-4.00'], [$at('1234', 5 + 5)['grand_total'], $at('1234', 10)['round_off']]);
        $this->assertSame(['1240.00', '5.00'], [$at('1235', 10)['grand_total'], $at('1235', 10)['round_off']]);   // exactly half goes up
        $this->assertSame(['1235.00', '1.00'], [$at('1234', 5)['grand_total'], $at('1234', 5)['round_off']]);
        $this->assertSame('1234.00', $at('1234', 0)['grand_total'], 'Rounding off means exact.');
    }

    public function test_round_off_comes_after_tax(): void
    {
        $r = $this->c->calculate([$this->line('1', '1001', ['tax_rate' => '17'])], ['round_to' => 10]);   // 1001 + 170.17 = 1171.17

        $this->assertSame('1171.17', $r['before_round']);
        $this->assertSame('1170.00', $r['grand_total']);
        $this->assertSame('-1.17', $r['round_off']);
    }

    public function test_split_shares_never_exceed_their_weights_and_always_sum_exactly(): void
    {
        mt_srand(20261007);
        for ($n = 0; $n < 400; $n++) {
            $count = mt_rand(1, 7);
            $weights = [];
            for ($i = 0; $i < $count; $i++) {
                $weights[] = number_format(mt_rand(1, 500000) / 100, 2, '.', '');
            }
            $sum = array_reduce($weights, fn ($c, $w) => bcadd($c, $w, 2), '0');
            $amount = number_format(mt_rand(0, (int) bcmul($sum, '100', 0)) / 100, 2, '.', '');

            $shares = $this->c->share($amount, $weights);

            $this->assertSame($amount, array_reduce($shares, fn ($c, $s) => bcadd($c, $s, 2), '0.00'), 'weights '.implode(',', $weights)." amount {$amount}");
            foreach ($shares as $i => $s) {
                $this->assertLessThanOrEqual(0, bccomp($s, $weights[$i], 2), "share {$s} is more than its line {$weights[$i]}");
            }
        }
    }

    public function test_every_line_balances_and_the_header_is_the_sum_of_the_lines(): void
    {
        mt_srand(77);
        for ($n = 0; $n < 200; $n++) {
            $lines = [];
            for ($i = 0, $count = mt_rand(1, 5); $i < $count; $i++) {
                $price = number_format(mt_rand(100, 900000) / 100, 2, '.', '');
                $lines[] = $this->line((string) mt_rand(1, 9), $price, ['tax_rate' => (string) [0, 5, 17, 18][mt_rand(0, 3)], 'discount_percent' => (string) mt_rand(0, 20)]);
            }
            $inclusive = (bool) mt_rand(0, 1);
            $r = $this->c->calculate($lines, ['discount_percent' => (string) mt_rand(0, 15), 'tax_inclusive' => $inclusive, 'round_to' => [0, 1, 5, 10][mt_rand(0, 3)]]);

            $sumTotal = $sumNet = $sumTax = $sumShare = '0.00';
            foreach ($r['lines'] as $l) {
                $this->assertSame($l['line_total'], bcadd($l['net_amount'], $l['tax_amount'], 2), 'net + tax = line total');
                $afterBoth = bcsub(bcsub($l['gross'], $l['line_discount'], 2), $l['invoice_discount_share'], 2);
                $this->assertSame($afterBoth, $inclusive ? $l['line_total'] : $l['net_amount'], 'what the line was worth after both discounts');
                $sumTotal = bcadd($sumTotal, $l['line_total'], 2);
                $sumNet = bcadd($sumNet, $l['net_amount'], 2);
                $sumTax = bcadd($sumTax, $l['tax_amount'], 2);
                $sumShare = bcadd($sumShare, $l['invoice_discount_share'], 2);
            }
            $this->assertSame($sumTotal, $r['before_round']);
            $this->assertSame($sumNet, $r['net_total']);
            $this->assertSame($sumTax, $r['tax_total']);
            $this->assertSame($sumShare, $r['invoice_discount_amount']);
            $this->assertSame(bcadd($r['before_round'], $r['round_off'], 2), $r['grand_total']);
        }
    }
}
