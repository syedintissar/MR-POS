<?php

namespace Tests\Feature\Inventory;

use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    use MakesStock;
    use RefreshesSeededDatabase;

    public function test_a_purchase_adds_stock_sets_the_cost_and_writes_a_ledger_row(): void
    {
        $user = User::factory()->create();
        $p = Product::factory()->create();

        $m = $this->inventory()->move($p, '10', 'purchase', ['unit_cost' => '100', 'user' => $user, 'notes' => 'first delivery']);

        $p->refresh();
        $this->assertSame('10.000', $p->current_stock);
        $this->assertSame('100.0000', $p->average_cost);
        $this->assertSame('100.0000', $p->last_purchase_cost);

        $this->assertSame('0.000', $m->qty_before);
        $this->assertSame('10.000', $m->quantity);
        $this->assertSame('10.000', $m->qty_after);
        $this->assertSame('100.0000', $m->unit_cost);
        $this->assertSame('purchase', $m->movement_type);
        $this->assertSame($user->id, $m->user_id);
        $this->assertSame('first delivery', $m->notes);
    }

    public function test_a_second_purchase_blends_the_average_cost(): void
    {
        $p = $this->stocked('10', '100');

        $this->inventory()->move($p, '10', 'purchase', ['unit_cost' => '120']);

        $p->refresh();
        $this->assertSame('20.000', $p->current_stock);
        $this->assertSame('110.0000', $p->average_cost);
        $this->assertSame('120.0000', $p->last_purchase_cost);
    }

    public function test_a_sale_removes_stock_at_the_current_average_and_keeps_the_average(): void
    {
        $p = $this->stocked('20', '110');

        $m = $this->inventory()->move($p, '-5', 'sale');

        $p->refresh();
        $this->assertSame('15.000', $p->current_stock);
        $this->assertSame('110.0000', $p->average_cost);
        $this->assertSame('110.0000', $m->unit_cost, 'The cost of goods sold is snapshotted on the movement.');
        $this->assertSame('20.000', $m->qty_before);
        $this->assertSame('15.000', $m->qty_after);
        $this->assertSame('-5.000', $m->quantity);
    }

    public function test_returning_goods_to_the_supplier_unwinds_the_average(): void
    {
        $p = $this->stocked('20', '110');

        $this->inventory()->move($p, '-5', 'purchase_return', ['unit_cost' => '120']);

        $p->refresh();
        $this->assertSame('15.000', $p->current_stock);
        $this->assertSame('106.6667', $p->average_cost);
    }

    public function test_selling_more_than_is_in_stock_is_refused_and_changes_nothing(): void
    {
        $p = $this->stocked('3', '50');
        $movementsBefore = StockMovement::count();

        try {
            $this->inventory()->move($p, '-4', 'sale');
            $this->fail('Expected an InsufficientStockException.');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('Not enough stock', $e->getMessage());
            $this->assertSame('3', $e->available);
            $this->assertSame('4', $e->requested);
        }

        $this->assertSame('3.000', $p->fresh()->current_stock);
        $this->assertSame($movementsBefore, StockMovement::count());
    }

    public function test_selling_exactly_what_is_there_is_allowed(): void
    {
        $p = $this->stocked('3', '50');

        $this->inventory()->move($p, '-3', 'sale');

        $this->assertSame('0.000', $p->fresh()->current_stock);
    }

    public function test_negative_stock_is_allowed_only_when_the_administrator_turns_it_on(): void
    {
        $p = $this->stocked('2', '100');
        app(SettingsService::class)->set('inventory.allow_negative_stock', true);

        $this->inventory()->move($p, '-5', 'sale');
        $this->assertSame('-3.000', $p->fresh()->current_stock);

        // Buying 10 more: the shortfall is covered and the average is not dragged by the debt.
        $this->inventory()->move($p, '10', 'purchase', ['unit_cost' => '90']);
        $p->refresh();
        $this->assertSame('7.000', $p->current_stock);
        $this->assertSame('90.0000', $p->average_cost);
    }

    public function test_the_default_is_that_negative_stock_is_not_allowed(): void
    {
        $this->assertFalse(app(SettingsService::class)->bool('inventory.allow_negative_stock'));
        $p = $this->stocked('1');

        $this->expectException(InsufficientStockException::class);
        $this->inventory()->move($p, '-2', 'sale');
    }

    public function test_the_direction_must_match_the_movement_type(): void
    {
        $p = $this->stocked('5');

        foreach ([['-1', 'purchase'], ['1', 'sale'], ['1', 'adjustment_out'], ['-1', 'opening']] as [$qty, $type]) {
            try {
                $this->inventory()->move($p, $qty, $type);
                $this->fail("{$type} with {$qty} should be refused.");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame('5.000', $p->fresh()->current_stock);
    }

    public function test_zero_quantity_is_refused(): void
    {
        $p = $this->stocked('5');

        $this->expectException(InvalidArgumentException::class);
        $this->inventory()->move($p, '0', 'adjustment_in');
    }

    public function test_unknown_movement_type_is_refused(): void
    {
        $p = $this->stocked('5');

        $this->expectException(InvalidArgumentException::class);
        $this->inventory()->move($p, '1', 'magic');
    }

    public function test_whole_units_reject_fractions_but_weighed_units_accept_them(): void
    {
        $pieces = Product::factory()->create();
        $grams = Product::factory()->unit('g')->create();

        $this->inventory()->move($grams, '2.5', 'purchase', ['unit_cost' => '4']);
        $this->assertSame('2.500', $grams->fresh()->current_stock);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('whole');
        $this->inventory()->move($pieces, '1.5', 'purchase', ['unit_cost' => '4']);
    }

    public function test_service_items_have_no_stock(): void
    {
        $service = Product::factory()->service()->create();

        $this->expectException(LogicException::class);
        $this->inventory()->move($service, '1', 'adjustment_in');
    }

    public function test_the_ledger_is_append_only_in_the_database_and_in_the_model(): void
    {
        $p = $this->stocked('5');
        $m = StockMovement::query()->where('product_id', $p->id)->firstOrFail();

        foreach ([
            fn () => DB::table('stock_movements')->where('id', $m->id)->update(['notes' => 'tampered']),
            fn () => DB::table('stock_movements')->where('id', $m->id)->delete(),
        ] as $attack) {
            try {
                $attack();
                $this->fail('The database must refuse this.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }

        $this->expectException(LogicException::class);
        $m->forceFill(['notes' => 'x'])->save();
    }

    public function test_the_database_rejects_a_ledger_row_whose_balance_does_not_add_up(): void
    {
        $p = Product::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('stock_movements')->insert([
            'product_id' => $p->id, 'movement_type' => 'purchase', 'quantity' => 5, 'qty_before' => 0, 'qty_after' => 99,
            'unit_cost' => 1, 'avg_cost_after' => 1, 'created_at' => now()->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function test_the_stock_cache_always_equals_the_ledger_and_drift_is_detected(): void
    {
        $p = $this->stocked('10', '100');
        $this->inventory()->move($p, '-3', 'sale');
        $this->inventory()->move($p, '5', 'sale_return', ['unit_cost' => '100']);
        $this->inventory()->move($p, '-1', 'adjustment_out');

        $ledger = StockMovement::query()->where('product_id', $p->id)->sum('quantity');
        $this->assertSame('11.000', number_format((float) $ledger, 3, '.', ''));
        $this->assertSame('11.000', $p->fresh()->current_stock);
        $this->assertSame([], $this->inventory()->findDrift());

        // Someone edits the cache directly (a bug, or a person with database access).
        DB::table('products')->where('id', $p->id)->update(['current_stock' => 99]);

        $drift = $this->inventory()->findDrift();
        $this->assertCount(1, $drift);
        $this->assertSame($p->id, $drift[0]['product_id']);
        $this->assertSame('99.000', $drift[0]['cached']);
    }

    public function test_every_ledger_row_continues_where_the_previous_one_stopped(): void
    {
        $p = $this->stocked('10');
        foreach (['-2', '-3'] as $q) {
            $this->inventory()->move($p, $q, 'sale');
        }
        $this->inventory()->move($p, '4', 'adjustment_in', ['unit_cost' => '100']);

        $rows = StockMovement::query()->where('product_id', $p->id)->orderBy('id')->get();
        $expectedBefore = '0.000';
        foreach ($rows as $row) {
            $this->assertSame($expectedBefore, $row->qty_before);
            $expectedBefore = $row->qty_after;
        }
        $this->assertSame($p->fresh()->current_stock, $expectedBefore);
    }

    public function test_locking_several_products_returns_them_in_id_order(): void
    {
        $a = Product::factory()->create();
        $b = Product::factory()->create();

        $locked = $this->inventory()->lockProducts([$b->id, $a->id, $b->id]);

        $this->assertSame([$a->id, $b->id], $locked->keys()->all());
    }

    public function test_the_cached_product_object_is_updated_for_the_caller(): void
    {
        $p = Product::factory()->create();

        $this->inventory()->move($p, '7', 'purchase', ['unit_cost' => '10']);

        $this->assertSame('7.000', $p->current_stock);
        $this->assertSame('10.0000', $p->average_cost);
    }
}
