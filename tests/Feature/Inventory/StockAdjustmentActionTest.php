<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\PostStockAdjustment;
use App\Actions\Inventory\VoidStockAdjustment;
use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

class StockAdjustmentActionTest extends TestCase
{
    use MakesStock;
    use RefreshesSeededDatabase;

    private function adjust(array $data, ?User $by = null): StockAdjustment
    {
        return app(PostStockAdjustment::class)->execute($data, $by ?? User::factory()->create());
    }

    public function test_posting_changes_stock_numbers_the_document_and_links_every_movement(): void
    {
        $user = User::factory()->create();
        $a = $this->stocked('10', '100');
        $b = $this->stocked('4', '50');

        $adj = $this->adjust([
            'reason' => 'count_correction',
            'notes' => 'Monthly count',
            'lines' => [
                ['product_id' => $a->id, 'quantity' => '-3'],
                ['product_id' => $b->id, 'quantity' => '2', 'unit_cost' => '60'],
            ],
        ], $user);

        $this->assertSame('ADJ-000001', $adj->adjustment_no);
        $this->assertSame('posted', $adj->status);
        $this->assertSame($user->id, $adj->created_by);
        $this->assertSame('7.000', $a->fresh()->current_stock);
        $this->assertSame('6.000', $b->fresh()->current_stock);

        $movements = StockMovement::query()->where('source_type', 'stock_adjustment')->where('source_id', $adj->id)->orderBy('product_id')->get();
        $this->assertCount(2, $movements);
        $this->assertSame(['adjustment_out', 'adjustment_in'], $movements->pluck('movement_type')->all());
        $this->assertSame($user->id, $movements[0]->user_id);
        $this->assertStringContainsString('ADJ-000001', $movements[0]->notes);

        // the line stores the cost that applied: removals at the average, additions at the entered cost
        $items = $adj->items()->orderBy('product_id')->get();
        $this->assertSame('100.0000', $items[0]->unit_cost);
        $this->assertSame('60.0000', $items[1]->unit_cost);
    }

    public function test_added_stock_defaults_to_the_current_average_cost(): void
    {
        $a = $this->stocked('10', '100');

        $this->adjust(['reason' => 'found', 'lines' => [['product_id' => $a->id, 'quantity' => '5']]]);

        $a->refresh();
        $this->assertSame('15.000', $a->current_stock);
        $this->assertSame('100.0000', $a->average_cost, 'Found stock valued at the average does not move the average.');
    }

    public function test_the_action_is_audited(): void
    {
        $user = User::factory()->create();
        $a = $this->stocked('10');

        $adj = $this->adjust(['reason' => 'damage', 'lines' => [['product_id' => $a->id, 'quantity' => '-1']]], $user);

        $row = DB::table('audit_logs')->where('event', 'stock_adjusted')->latest('id')->first();
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame($adj->id, (int) $row->auditable_id);
        $this->assertSame('stock_adjustment', $row->auditable_type);
        $new = json_decode($row->new_values, true);
        $this->assertSame('damage', $new['reason']);
        $this->assertSame('10', $new['lines'][0]['from']);
        $this->assertSame('9', $new['lines'][0]['to']);
    }

    public function test_one_bad_line_cancels_the_whole_document(): void
    {
        $a = $this->stocked('10');
        $b = $this->stocked('2');
        $movements = StockMovement::count();
        $adjustments = StockAdjustment::count();

        try {
            $this->adjust(['reason' => 'loss', 'lines' => [
                ['product_id' => $a->id, 'quantity' => '-4'],
                ['product_id' => $b->id, 'quantity' => '-5'],   // only 2 in stock
            ]]);
            $this->fail('Expected the adjustment to be refused.');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString($b->name, $e->getMessage());
        }

        $this->assertSame('10.000', $a->fresh()->current_stock, 'The first line must be rolled back too.');
        $this->assertSame('2.000', $b->fresh()->current_stock);
        $this->assertSame($movements, StockMovement::count());
        $this->assertSame($adjustments, StockAdjustment::count());
        $this->assertDatabaseMissing('audit_logs', ['event' => 'stock_adjusted']);
    }

    public function test_a_failed_posting_gives_its_document_number_back(): void
    {
        $a = $this->stocked('1');

        try {
            $this->adjust(['reason' => 'loss', 'lines' => [['product_id' => $a->id, 'quantity' => '-5']]]);
        } catch (InsufficientStockException) {
        }

        $ok = $this->adjust(['reason' => 'found', 'lines' => [['product_id' => $a->id, 'quantity' => '1']]]);
        $this->assertSame('ADJ-000001', $ok->adjustment_no);
    }

    public function test_opening_stock_can_only_add_and_uses_the_opening_movement_type(): void
    {
        $a = Product::factory()->create();

        $adj = $this->adjust(['reason' => 'opening', 'lines' => [['product_id' => $a->id, 'quantity' => '12', 'unit_cost' => '35']]]);

        $this->assertSame('opening', StockMovement::query()->where('source_id', $adj->id)->value('movement_type'));
        $a->refresh();
        $this->assertSame('12.000', $a->current_stock);
        $this->assertSame('35.0000', $a->average_cost);
        $this->assertSame('35.0000', $a->last_purchase_cost);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Opening stock can only add');
        $this->adjust(['reason' => 'opening', 'lines' => [['product_id' => $a->id, 'quantity' => '-1']]]);
    }

    public function test_bad_documents_are_refused(): void
    {
        $a = $this->stocked('5');
        $service = Product::factory()->service()->create();

        $cases = [
            'unknown reason' => ['reason' => 'because', 'lines' => [['product_id' => $a->id, 'quantity' => '1']]],
            'no lines' => ['reason' => 'other', 'lines' => []],
            'duplicate product' => ['reason' => 'other', 'lines' => [['product_id' => $a->id, 'quantity' => '1'], ['product_id' => $a->id, 'quantity' => '2']]],
            'zero quantity' => ['reason' => 'other', 'lines' => [['product_id' => $a->id, 'quantity' => '0']]],
            'service item' => ['reason' => 'other', 'lines' => [['product_id' => $service->id, 'quantity' => '1']]],
        ];

        foreach ($cases as $name => $data) {
            try {
                $this->adjust($data);
                $this->fail("{$name} should have been refused.");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame('5.000', $a->fresh()->current_stock);
        $this->assertSame(0, StockAdjustment::count());
    }

    public function test_voiding_writes_the_opposite_movements_and_restores_stock_and_average_cost(): void
    {
        $admin = User::factory()->create();
        $a = $this->stocked('10', '100');
        $adj = $this->adjust(['reason' => 'found', 'lines' => [['product_id' => $a->id, 'quantity' => '10', 'unit_cost' => '120']]]);
        $this->assertSame('110.0000', $a->fresh()->average_cost);

        $voided = app(VoidStockAdjustment::class)->execute($adj, 'Entered by mistake', $admin);

        $a->refresh();
        $this->assertSame('10.000', $a->current_stock);
        $this->assertSame('100.0000', $a->average_cost, 'Undoing stock that came in also undoes its effect on the average cost.');
        $this->assertSame('voided', $voided->status);
        $this->assertSame($admin->id, $voided->voided_by);
        $this->assertSame('Entered by mistake', $voided->void_reason);
        $this->assertNotNull($voided->voided_at);

        // Nothing was deleted: the original movement is still there, followed by its reversal.
        $this->assertSame(['adjustment_in', 'adjustment_out'], StockMovement::query()->where('source_id', $adj->id)->orderBy('id')->pluck('movement_type')->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'stock_adjustment_voided', 'auditable_id' => $adj->id]);
    }

    public function test_voiding_a_removal_puts_the_stock_back(): void
    {
        $a = $this->stocked('10', '100');
        $adj = $this->adjust(['reason' => 'damage', 'lines' => [['product_id' => $a->id, 'quantity' => '-4']]]);
        $this->assertSame('6.000', $a->fresh()->current_stock);

        app(VoidStockAdjustment::class)->execute($adj, 'Found them', User::factory()->create());

        $this->assertSame('10.000', $a->fresh()->current_stock);
    }

    public function test_an_adjustment_can_only_be_voided_once_and_needs_a_reason(): void
    {
        $admin = User::factory()->create();
        $a = $this->stocked('10');
        $adj = $this->adjust(['reason' => 'found', 'lines' => [['product_id' => $a->id, 'quantity' => '2']]]);
        $void = app(VoidStockAdjustment::class);

        try {
            $void->execute($adj, '   ', $admin);
            $this->fail('A reason is required.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('reason', $e->getMessage());
        }

        $void->execute($adj, 'Mistake', $admin);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('already been cancelled');
        $void->execute($adj->fresh(), 'Again', $admin);
    }

    public function test_voiding_is_refused_when_the_stock_needed_to_undo_it_has_been_sold(): void
    {
        $a = $this->stocked('0');
        $adj = $this->adjust(['reason' => 'found', 'lines' => [['product_id' => $a->id, 'quantity' => '5', 'unit_cost' => '10']]]);
        $this->inventory()->move($a, '-4', 'sale');   // 4 of the 5 are gone

        try {
            app(VoidStockAdjustment::class)->execute($adj, 'Oops', User::factory()->create());
            $this->fail('Expected the void to be refused.');
        } catch (InsufficientStockException) {
            $this->assertTrue(true);
        }

        $this->assertSame('1.000', $a->fresh()->current_stock);
        $this->assertSame('posted', $adj->fresh()->status, 'The document stays posted when its void fails.');
    }
}
