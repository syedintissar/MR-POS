<?php

namespace Tests\Feature\Returns;

use App\Actions\Sales\ExchangeSale;
use App\Actions\Sales\VoidSale;
use App\Actions\Sales\VoidSaleReturn;
use App\Exceptions\InsufficientStockException;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use App\Services\Customers\CustomerAccountService;
use App\Services\SettingsService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\Concerns\ReturnHelpers;
use Tests\TestCase;

/** One-click exchange: the return and the replacement sale as one all-or-nothing step, with the price difference settled. */
class ExchangeTest extends TestCase
{
    use ActsAsRoles;
    use RefreshesSeededDatabase;
    use ReturnHelpers;

    private Product $a;

    private Product $b;

    private Product $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeManager();
        $this->a = $this->item('150', '50', '100');
        $this->b = $this->item('150', '50', '90');
        $this->c = $this->item('400', '50', '250');
    }

    /** @param  array<int, array<string, mixed>>  $perItem  returned lines by position; $new = [[product, qty], ...] */
    private function exchange(Sale $sale, array $perItem, array $new, array $opts = [], ?string $key = null, ?User $by = null): array
    {
        $items = $sale->items()->orderBy('id')->get();
        $lines = [];
        foreach ($perItem as $pos => $l) {
            $lines[] = ['sale_item_id' => $items[$pos]->id] + $l;
        }

        return app(ExchangeSale::class)->execute(
            $sale->fresh(), $lines, array_map(fn ($n) => ['product_id' => $n[0]->id, 'quantity' => $n[1]] + ($n[2] ?? []), $new), $opts, 'Wrong model', $key ?? (string) Str::uuid(), $by ?? $this->manager,
        );
    }

    private function stock(Product $p): string
    {
        return (string) $p->fresh()->current_stock;
    }

    // ---------------------------------------------------------------- the walk-in customer

    public function test_an_even_exchange_for_the_walk_in_customer_records_the_money_out_and_in_and_swaps_the_stock(): void
    {
        $sale = $this->sell([[$this->a, '2']]);   // 300 in cash

        $done = $this->exchange($sale, [0 => ['quantity' => '1']], [[$this->b, '1']]);

        $this->assertSame('150.00', $done['return']->refund_amount);
        $this->assertSame('150.00', $done['sale']->grand_total);
        $this->assertSame('0.00', $done['difference']);
        $this->assertSame($done['sale']->id, $done['return']->exchange_sale_id);
        $this->assertSame(['49.000', '49.000'], [$this->stock($this->a), $this->stock($this->b)], 'the returned unit is back, the new one is gone');
        $this->assertSame(['receipt', 'refund', 'receipt'], CustomerPayment::query()->orderBy('id')->pluck('type')->all());
        $this->assertSame('150.00', $done['sale']->fresh()->paid_amount);
        $this->assertAllBooksAgree();
        $this->assertSame(1, AuditLog::query()->where('event', 'sale_exchanged')->count());
    }

    public function test_the_walk_in_customer_who_takes_dearer_goods_pays_and_gets_change(): void
    {
        $sale = $this->sell([[$this->a, '2']]);

        $done = $this->exchange($sale, [0 => ['quantity' => '1']], [[$this->c, '1']], ['tendered' => '500']);

        $this->assertSame('400.00', $done['sale']->grand_total);
        $this->assertSame(['500.00', '100.00'], [$done['sale']->amount_tendered, $done['sale']->change_amount]);
        $this->assertSame('250.00', $done['difference'], 'the new goods cost 250 more than the return gave');
        $this->assertAllBooksAgree();
    }

    // ---------------------------------------------------------------- a named customer

    public function test_a_named_customer_uses_the_credit_of_the_return_and_keeps_what_is_left_over(): void
    {
        $c = $this->named();
        $cheap = $this->item('100', '50', '60');
        $sale = $this->sell([[$this->a, '2']], [['cash', '300']], $c);

        $done = $this->exchange($sale, [0 => ['quantity' => '1']], [[$cheap, '1']]);

        $this->assertSame('150.00', $done['return']->store_credit_amount);
        $this->assertSame('0.00', $done['return']->refund_amount);
        $this->assertSame(['100.00', '0.00', 'paid'], [$done['sale']->paid_amount, $done['sale']->due_amount, $done['sale']->payment_status]);
        $this->assertSame('50.00', app(CustomerAccountService::class)->advanceFor($c->id), 'the rest of the credit stays the customer\'s');
        $this->assertSame('-50.00', $this->customerBalance($c));
        $this->assertSame(1, CustomerPayment::query()->where('type', 'refund')->count() + 1, 'no money was handed over');
        $this->assertSame(0, CustomerPayment::query()->where('type', 'refund')->count());
        $this->assertAllBooksAgree();
    }

    public function test_a_named_customer_who_takes_dearer_goods_pays_only_the_difference(): void
    {
        $c = $this->named();
        $sale = $this->sell([[$this->a, '2']], [['cash', '300']], $c);

        $done = $this->exchange($sale, [0 => ['quantity' => '1']], [[$this->c, '1']]);

        $this->assertSame('400.00', $done['sale']->paid_amount);
        $cash = CustomerPayment::query()->where('customer_id', $c->id)->where('sale_id', $done['sale']->id)->sole();
        $this->assertSame('250.00', $cash->amount, 'the credit of 150 paid the rest');
        $this->assertSame('0.00', app(CustomerAccountService::class)->advanceFor($c->id));
        $this->assertSame('0.00', $this->customerBalance($c));
        $this->assertAllBooksAgree();
    }

    public function test_what_the_customer_still_owed_on_the_old_invoice_is_reduced_first(): void
    {
        $c = $this->named();
        $sale = $this->sell([[$this->a, '2']], [], $c);   // 300 on account

        $done = $this->exchange($sale, [0 => ['quantity' => '1']], [[$this->b, '1']]);

        $this->assertSame(['150.00', '0.00'], [$done['return']->applied_to_due, $done['return']->store_credit_amount], 'all of the return went off the old debt');
        $this->assertSame('150.00', $sale->fresh()->due_amount);
        $this->assertSame('150.00', $this->customerBalance($c), 'what is still owed on the old invoice; the new items were paid in full');
        $this->assertAllBooksAgree();
    }

    public function test_the_refund_mode_hands_the_money_back_and_charges_the_new_items_in_full(): void
    {
        $c = $this->named();
        $sale = $this->sell([[$this->a, '2']], [['cash', '300']], $c);

        $done = $this->exchange($sale, [0 => ['quantity' => '1']], [[$this->c, '1']], ['mode' => 'refund']);

        $this->assertSame(['150.00', '0.00'], [$done['return']->refund_amount, $done['return']->store_credit_amount]);
        $this->assertSame(['receipt', 'refund', 'receipt'], CustomerPayment::query()->orderBy('id')->pluck('type')->all());
        $this->assertSame('400.00', CustomerPayment::query()->where('sale_id', $done['sale']->id)->sole()->amount);
        $this->assertSame('0.00', $this->customerBalance($c));
        $this->assertAllBooksAgree();
    }

    public function test_tax_and_the_fee_flow_through_the_exchange(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('tax.enabled', true);
        $settings->set('tax.default_rate', '10');
        $settings->set('sales.restocking_fee_enabled', true);
        $settings->set('sales.restocking_fee_percent', '10');
        $c = $this->named();
        $cheap = $this->item('100', '50', '60');
        $sale = $this->sell([[$this->a, '2']], [['cash', '330']], $c);   // 300 + 30 tax

        $done = $this->exchange($sale, [0 => ['quantity' => '1']], [[$cheap, '1']]);

        $this->assertSame(['165.00', '15.00'], [$done['return']->total, $done['return']->restocking_fee], '150 + 15 tax; the fee is 10% of the 150');
        $this->assertSame('150.00', $done['return']->store_credit_amount);
        $this->assertSame('110.00', $done['sale']->grand_total, '100 + 10 tax');
        $this->assertSame('40.00', app(CustomerAccountService::class)->advanceFor($c->id));
        $this->assertAllBooksAgree();
    }

    public function test_the_return_period_and_its_exception_apply_to_an_exchange(): void
    {
        $sale = $this->sell([[$this->a, '2']]);
        DB::table('sales')->where('id', $sale->id)->update(['sale_date' => now()->subDays(20)]);

        try {
            $this->exchange($sale, [0 => ['quantity' => '1']], [[$this->b, '1']]);
            $this->fail('An exchange passed the return period without a reason.');
        } catch (DomainException) {
            $this->assertSame(0, SaleReturn::query()->count());
            $this->assertSame(1, Sale::query()->count());
        }

        $done = $this->exchange($sale, [0 => ['quantity' => '1']], [[$this->b, '1']], ['exception_reason' => 'Faulty on delivery']);
        $this->assertSame('Faulty on delivery', $done['return']->period_exception_reason);
    }

    // ---------------------------------------------------------------- all or nothing

    public function test_when_the_new_items_are_not_in_stock_nothing_at_all_happens(): void
    {
        $c = $this->named();
        $scarce = $this->item('100', '1', '60');
        $sale = $this->sell([[$this->a, '2']], [['cash', '300']], $c);
        $before = [$this->stock($this->a), CustomerPayment::query()->count(), Sale::query()->count(), $this->customerBalance($c)];

        try {
            $this->exchange($sale, [0 => ['quantity' => '1']], [[$scarce, '5']]);
            $this->fail('The exchange went through without the stock.');
        } catch (InsufficientStockException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, SaleReturn::query()->count(), 'the return was undone with the rest');
        $this->assertSame($before, [$this->stock($this->a), CustomerPayment::query()->count(), Sale::query()->count(), $this->customerBalance($c)]);
        $this->assertSame('0.000', (string) $sale->items()->sole()->fresh()->returned_quantity);
        $this->assertSame('SRN-000001', $this->giveBack($sale, [0 => ['quantity' => '1']], ['remainder' => 'credit'])->return_no, 'not even the document number was used up');
        $this->assertAllBooksAgree();
    }

    public function test_a_payment_that_is_refused_undoes_the_return_too(): void
    {
        $sale = $this->sell([[$this->a, '2']]);

        try {
            $this->exchange($sale, [0 => ['quantity' => '1']], [[$this->c, '1']], ['tendered' => '10']);   // 400 to pay with 10 in hand
            $this->fail('An underpaid exchange went through.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([0, 1], [SaleReturn::query()->count(), Sale::query()->count()]);
        $this->assertSame('50.000', (string) $this->c->fresh()->current_stock);
        $this->assertAllBooksAgree();
    }

    public function test_the_replacement_is_always_priced_by_the_server(): void
    {
        $sale = $this->sell([[$this->a, '2']]);

        $done = $this->exchange($sale, [0 => ['quantity' => '1']], [[$this->b, '1', ['unit_price' => '1', 'discount_amount' => '149']]], ['tendered' => '200']);

        $this->assertSame('150.00', $done['sale']->items()->sole()->unit_price, 'a price typed into the request is ignored');
    }

    // ---------------------------------------------------------------- undoing and permissions

    public function test_the_return_of_an_exchange_is_cancelled_only_after_the_replacement_invoice(): void
    {
        $c = $this->named();
        $cheap = $this->item('100', '50', '60');
        $sale = $this->sell([[$this->a, '2']], [['cash', '300']], $c);
        $done = $this->exchange($sale, [0 => ['quantity' => '1']], [[$cheap, '1']]);

        try {
            app(VoidSaleReturn::class)->execute($done['return'], 'Mistake', $this->manager);
            $this->fail('Half an exchange was cancelled.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('part of an exchange', $e->getMessage());
        }

        app(VoidSale::class)->execute($done['sale'], 'Customer changed their mind', $this->manager);
        app(VoidSaleReturn::class)->execute($done['return'], 'Mistake', $this->manager);

        $this->assertSame(['48.000', '50.000'], [$this->stock($this->a), $this->stock($cheap)]);
        $this->assertSame('0.00', $this->customerBalance($c));
        $this->assertAllBooksAgree();
    }

    public function test_an_exchange_needs_both_the_right_to_take_goods_back_and_the_right_to_sell(): void
    {
        $sale = $this->sell([[$this->a, '2']]);

        foreach ([['sale_returns.create', 'sale_returns.refund'], ['sales.create', 'pos.access']] as $permissions) {
            $this->asWithOnly($permissions);
            try {
                $this->exchange($sale, [0 => ['quantity' => '1']], [[$this->b, '1']], [], null, auth()->user());
                $this->fail('An exchange went through with only '.implode(' + ', $permissions));
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, SaleReturn::query()->count());

        $this->expectException(DomainException::class);
        $this->exchange($sale, [0 => ['quantity' => '1']], []);
    }

    // ---------------------------------------------------------------- the pages

    public function test_the_exchange_page_works_end_to_end(): void
    {
        $sale = $this->sell([[$this->a, '2']]);
        $item = $sale->items()->sole();

        $this->as('Manager');
        $this->get("/sale-returns/create?sale={$sale->id}")->assertOk()->assertSee('Exchange for other items instead')->assertDontSee('What does the customer take instead?');
        $this->get("/sale-returns/create?sale={$sale->id}&exchange=1")->assertOk()->assertSee('What does the customer take instead?')->assertSee('Do the exchange')->assertSee('Just a return, no new items');
        $this->get('/sale-returns/products?q='.$this->b->sku)->assertOk()->assertJsonPath('0.price', '150.00');

        $data = ['reason' => 'Wrong model', 'checkout_key' => (string) Str::uuid(), 'lines' => [$item->id => ['quantity' => '1', 'condition' => 'resellable', 'restock' => '1']], 'replacement' => [['product_id' => $this->b->id, 'quantity' => '1']]];
        $response = $this->post("/sale-returns/sales/{$sale->id}/exchange", $data);
        $return = SaleReturn::query()->sole();
        $response->assertRedirect(route('sale-returns.show', $return))->assertSessionHas('success')->assertSessionHas('receipt_url')->assertSessionHas('sale_receipt_url');
        $this->assertNotNull($return->exchange_sale_id);
        $this->get("/sale-returns/{$return->id}")->assertOk()->assertSee('Exchanged for')->assertSee($return->exchangeSale->invoice_no);
        $this->get("/sales/{$return->exchange_sale_id}")->assertOk();

        // bad input is explained, and nothing more is recorded
        $this->post("/sale-returns/sales/{$sale->id}/exchange", array_diff_key($data, ['checkout_key' => 1]))->assertSessionHasErrors('checkout_key');
        $this->post("/sale-returns/sales/{$sale->id}/exchange", array_diff_key($data, ['replacement' => 1]))->assertSessionHasErrors('replacement');
        $this->post("/sale-returns/sales/{$sale->id}/exchange", ['checkout_key' => (string) Str::uuid(), 'lines' => [$item->id => ['quantity' => '9']]] + $data)->assertSessionHas('error');
        $this->assertSame(1, SaleReturn::query()->count());

        foreach (['Cashier', 'Technician'] as $role) {
            $this->as($role)->post("/sale-returns/sales/{$sale->id}/exchange", ['checkout_key' => (string) Str::uuid()] + $data)->assertForbidden();
        }
        $this->asWithOnly(['sale_returns.create'])->get("/sale-returns/create?sale={$sale->id}&exchange=1")->assertOk()->assertDontSee('What does the customer take instead?');
        $this->post("/sale-returns/sales/{$sale->id}/exchange", ['checkout_key' => (string) Str::uuid()] + $data)->assertForbidden();
        $this->assertSame(1, Customer::query()->where('is_walk_in', true)->count());
    }
}
