<?php

namespace Tests\Unit;

use App\Services\Inventory\CostingService;
use PHPUnit\Framework\TestCase;

class CostingServiceTest extends TestCase
{
    private CostingService $c;

    protected function setUp(): void
    {
        $this->c = new CostingService;
    }

    public function test_first_purchase_sets_the_average_to_its_cost(): void
    {
        $this->assertSame('100.0000', $this->c->blend('0', '0', '10', '100'));
    }

    public function test_second_purchase_moves_the_average_toward_the_new_cost(): void
    {
        // 10 units at 100 + 10 units at 120 -> 110
        $this->assertSame('110.0000', $this->c->blend('10', '100', '10', '120'));
        // 3 units at 100 + 1 unit at 200 -> 125
        $this->assertSame('125.0000', $this->c->blend('3', '100', '1', '200'));
    }

    public function test_average_is_rounded_to_four_decimals(): void
    {
        // (1 * 100 + 2 * 101) / 3 = 100.6666...
        $this->assertSame('100.6667', $this->c->blend('1', '100', '2', '101'));
    }

    public function test_stock_that_was_negative_does_not_drag_the_average_down(): void
    {
        // 5 units were sold that we did not have (S = -5); buying 10 at 100 gives an average of 100, not a blend with the debt.
        $this->assertSame('100.0000', $this->c->blend('-5', '80', '10', '100'));
    }

    public function test_fractional_quantities_work(): void
    {
        // 2.5 g at 4.00 + 1.5 g at 6.00 -> (10 + 9) / 4 = 4.75
        $this->assertSame('4.7500', $this->c->blend('2.5', '4', '1.5', '6'));
    }

    public function test_returning_stock_to_a_supplier_removes_its_cost_from_the_average(): void
    {
        // 20 at 110 (2200). Return 5 that cost 120 (600) -> 1600 / 15 = 106.6667
        $this->assertSame('106.6667', $this->c->unwind('20', '110', '5', '120'));
    }

    public function test_removing_everything_keeps_the_last_average(): void
    {
        $this->assertSame('110.0000', $this->c->unwind('5', '110', '5', '120'));
        $this->assertSame('110.0000', $this->c->unwind('5', '110', '9', '120'));
        $this->assertSame('80.0000', $this->c->unwind('-2', '80', '1', '90'));
    }

    public function test_unwind_never_produces_a_negative_average(): void
    {
        // Returning goods that were recorded at a higher cost than the whole average could cover.
        $this->assertSame('0.0000', $this->c->unwind('10', '1', '9', '50'));
    }

    public function test_blend_then_unwind_returns_to_the_start(): void
    {
        $avg = $this->c->blend('10', '100', '10', '120');         // 110, stock 20
        $this->assertSame('100.0000', $this->c->unwind('20', $avg, '10', '120'));
    }
}
