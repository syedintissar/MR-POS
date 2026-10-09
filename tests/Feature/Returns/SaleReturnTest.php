<?php

namespace Tests\Feature\Returns;

use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\CreateSaleReturn;
use App\Actions\Sales\VoidSale;
use App\Actions\Sales\VoidSaleReturn;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Customers\CustomerAccountService;
use App\Services\Sales\SaleAccountService;
use App\Services\SettingsService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/** Taking goods back against an invoice: what each unit is worth, where the money goes, the stock, and undoing a return. */
class SaleReturnTest extends TestCase
{
    use ActsAsRoles;
    use MakesStock;
    use RefreshesSeededDatabase;

    private User $manager;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->withRole('Manager')->create();
        $this->cashier = User::factory()->withRole('Cashier')->create();
    }

    private function method(string $code = 'cash'): PaymentMethod
    {
        return PaymentMethod::query()->where('code', $code)->firstOrFail();
    }

    private function item(string $price = '150', string $qty = '50', string $cost = '100', array $attrs = []): Product
    {
        return $this->stocked($qty, $cost, ['sale_price' => $price] + $attrs);
    }

    private function named(): Customer
    {
        return Customer::factory()->create(['allow_credit' => true]);
    }

    /** @param  list<array<string, mixed>>  $lines  @param  list<array{0: string, 1: string}>  $pay [method code, amount]  null pay = all cash */
    private function sell(array $lines, ?array $pay = null, ?Customer $c = null, array $over = []): Sale
    {
        $total = '100000';
        $payments = $pay === null
            ? [['payment_method_id' => $this->method()->id, 'amount' => $total]]
            : array_map(fn ($x) => ['payment_method_id' => $this->method($x[0])->id, 'amount' => $x[1]], $pay);

        return app(CompleteSale::class)->execute($over + [
            'idempotency_key' => (string) Str::uuid(), 'customer_id' => $c?->id, 'lines' => $lines, 'payments' => $payments,
        ], $this->manager);
    }

    private function line(Product $p, string $qty = '1'): array
    {
        return ['product_id' => $p->id, 'quantity' => $qty];
    }

    /** @param  array<int, array{quantity: string, condition?: string, restock?: bool}>  $perItem  keyed by the position of the line on the invoice */
    private function giveBack(Sale $sale, array $perItem, array $settle = [], string $reason = 'Faulty', ?User $by = null): SaleReturn
    {
        $items = $sale->items()->orderBy('id')->get();
        $lines = [];
        foreach ($perItem as $pos => $l) {
            $lines[] = ['sale_item_id' => $items[$pos]->id] + $l;
        }

        return app(CreateSaleReturn::class)->execute($sale->fresh(), $lines, $settle, $reason, $by ?? $this->manager);
    }

    private function balance(Customer $c): string
    {
        return (string) $c->fresh()->getRawOriginal('balance');
    }

    private function assertBooksAgree(): void
    {
        $this->assertSame([], app(CustomerAccountService::class)->findDrift());
        $this->assertSame([], app(SaleAccountService::class)->findDrift());
        $this->assertSame([], $this->inventory()->findDrift());
    }

    // ---------------------------------------------------------------- what a returned unit is worth

    public function test_a_returned_item_is_worth_what_the_customer_paid_after_the_invoice_discount(): void
    {
        $a = $this->item('600');
        $b = $this->item('300');
        $sale = $this->sell([$this->line($a), $this->line($b)], null, null, ['discount_amount' => '45']);   // shares 30 + 15
        $this->assertSame('570.00', $sale->items[0]->net_amount);

        $return = $this->giveBack($sale, [0 => ['quantity' => '1']]);

        $this->assertSame('570.00', $return->total, 'Not the 600 list price: the 30 discount share stays with the customer.');
        $this->assertSame('570.00', $return->refund_amount);
        $this->assertSame('SRN-000001', $return->return_no);
        $this->assertSame('0.00', $sale->fresh()->due_amount, 'The invoice was paid in full, so nothing is owed.');
        $this->assertSame('570.00', $sale->fresh()->returned_amount);
        $this->assertSame('1.000', $sale->items[0]->fresh()->returned_quantity);
        $this->assertBooksAgree();
    }

    public function test_the_last_units_of_a_line_take_what_is_left_so_nothing_is_lost_to_rounding(): void
    {
        $p = $this->item('100');
        $sale = $this->sell([$this->line($p, '3')], null, null, ['discount_amount' => '10']);
        $this->assertSame('290.00', $sale->items->sole()->net_amount);

        $totals = [];
        foreach (range(1, 3) as $i) {
            $totals[] = $this->giveBack($sale, [0 => ['quantity' => '1']])->total;
        }

        $this->assertSame(['96.67', '96.67', '96.66'], $totals);
        $this->assertSame('290.00', $sale->fresh()->returned_amount, 'The three returns add up to exactly what was paid.');
        $this->assertBooksAgree();
    }

    public function test_tax_is_returned_with_the_goods(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('tax.enabled', true);
        $settings->set('tax.default_rate', '17');
        $p = $this->item('1000');
        $sale = $this->sell([$this->line($p, '2')]);
        $this->assertSame('2340.00', $sale->grand_total);

        $return = $this->giveBack($sale, [0 => ['quantity' => '1']]);

        $this->assertSame('1000.00', $return->net_total);
        $this->assertSame('170.00', $return->tax_total);
        $this->assertSame('1170.00', $return->total);
        $this->assertBooksAgree();
    }

    public function test_more_than_was_sold_or_than_is_left_is_refused(): void
    {
        $p = $this->item('100');
        $sale = $this->sell([$this->line($p, '3')]);
        $this->giveBack($sale, [0 => ['quantity' => '2']]);

        foreach (['2', '1.5', '999'] as $qty) {
            try {
                $this->giveBack($sale, [0 => ['quantity' => $qty]]);
                $this->fail("Returned {$qty} when only 1 was left.");
            } catch (DomainException $e) {
                $this->assertStringContainsString('only 1 can still be returned', $e->getMessage());
            }
        }
        $this->assertSame(1, SaleReturn::query()->count());
        $this->assertSame('2.000', $sale->items->sole()->fresh()->returned_quantity);
    }

    public function test_bad_returns_are_refused_and_leave_nothing_behind(): void
    {
        $p = $this->item('100');
        $sale = $this->sell([$this->line($p, '2')]);
        $other = $this->sell([$this->line($p, '1')]);

        $bad = [
            'nothing chosen' => fn () => $this->giveBack($sale, []),
            'zero quantity' => fn () => $this->giveBack($sale, [0 => ['quantity' => '0']]),
            'no reason' => fn () => $this->giveBack($sale, [0 => ['quantity' => '1']], [], '  '),
            'unknown condition' => fn () => $this->giveBack($sale, [0 => ['quantity' => '1', 'condition' => 'shiny']]),
            'item of another invoice' => fn () => app(CreateSaleReturn::class)->execute($sale, [['sale_item_id' => $other->items->first()->id, 'quantity' => '1']], [], 'x', $this->manager),
            'same item twice' => fn () => app(CreateSaleReturn::class)->execute($sale, [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1'], ['sale_item_id' => $sale->items->first()->id, 'quantity' => '1']], [], 'x', $this->manager),
        ];
        foreach ($bad as $why => $try) {
            try {
                $try();
                $this->fail("Accepted: {$why}");
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, SaleReturn::query()->count());
        $this->assertSame('0.00', $sale->fresh()->returned_amount);
        $this->assertBooksAgree();
    }

    public function test_a_cancelled_invoice_cannot_have_goods_returned(): void
    {
        $sale = $this->sell([$this->line($this->item('100'))]);
        app(VoidSale::class)->execute($sale, 'Mistake', $this->manager);

        $this->expectException(DomainException::class);
        $this->giveBack($sale, [0 => ['quantity' => '1']]);
    }

    // ---------------------------------------------------------------- stock

    public function test_resellable_goods_go_back_on_the_shelf_at_the_cost_on_the_invoice(): void
    {
        $p = $this->item('150', '10', '100');
        $sale = $this->sell([$this->line($p, '4')]);
        $this->inventory()->move($p, '10', 'purchase', ['unit_cost' => '200']);   // the average cost moves on after the sale
        $this->assertNotSame('100.0000', $p->fresh()->average_cost);

        $return = $this->giveBack($sale, [0 => ['quantity' => '3']]);

        $this->assertSame('19.000', $p->fresh()->current_stock);   // 10 - 4 + 10 + 3
        $move = StockMovement::query()->where('movement_type', 'sale_return')->sole();
        $this->assertSame('3.000', $move->quantity);
        $this->assertSame('100.0000', $move->unit_cost, 'Back in at what it cost when it left, not at today\'s average.');
        $this->assertSame($return->id, $move->source_id);
        $this->assertTrue($return->items->sole()->restock);
        $this->assertBooksAgree();
    }

    public function test_damaged_goods_are_not_put_back_and_are_a_write_off(): void
    {
        $p = $this->item('150', '10', '100');
        $sale = $this->sell([$this->line($p, '2')]);

        $return = $this->giveBack($sale, [0 => ['quantity' => '2', 'condition' => 'damaged']]);

        $this->assertSame('8.000', $p->fresh()->current_stock, 'Nothing came back to the shelf.');
        $this->assertSame(0, StockMovement::query()->where('movement_type', 'sale_return')->count());
        $row = $return->items->sole();
        $this->assertFalse($row->restock);
        $this->assertSame('damaged', $row->item_condition);
        $this->assertSame('300.00', $return->refund_amount, 'The customer is still given their money.');
        $this->assertBooksAgree();
    }

    public function test_a_resellable_line_can_still_be_kept_off_the_shelf_and_a_service_item_never_goes_on_it(): void
    {
        $p = $this->item('150', '10', '100');
        $svc = Product::factory()->service()->create(['sale_price' => '500']);
        $sale = $this->sell([$this->line($p), $this->line($svc)]);

        $return = $this->giveBack($sale, [0 => ['quantity' => '1', 'restock' => false], 1 => ['quantity' => '1', 'restock' => true]]);

        $this->assertSame([false, false], $return->items->pluck('restock')->all());
        $this->assertSame(0, StockMovement::query()->where('movement_type', 'sale_return')->count());
        $this->assertSame('650.00', $return->total);
        $this->assertBooksAgree();
    }

    // ---------------------------------------------------------------- where the money goes

    public function test_a_walk_in_customer_always_gets_the_money_back(): void
    {
        $sale = $this->sell([$this->line($this->item('150'), '2')]);
        $before = CustomerPayment::query()->count();

        $return = $this->giveBack($sale, [0 => ['quantity' => '1']], ['remainder' => 'credit']);   // store credit is ignored for the walk-in customer

        $this->assertSame('150.00', $return->refund_amount);
        $this->assertSame('0.00', $return->store_credit_amount);
        $this->assertSame('0.00', $return->applied_to_due);
        $refund = CustomerPayment::query()->orderByDesc('id')->first();
        $this->assertSame($before + 1, CustomerPayment::query()->count());
        $this->assertSame('refund', $refund->type);
        $this->assertSame('150.00', $refund->amount);
        $this->assertSame(0, CustomerLedgerEntry::query()->count(), 'The walk-in customer has no account.');
        $this->assertBooksAgree();
    }

    public function test_a_return_first_reduces_what_the_customer_still_owes(): void
    {
        $c = $this->named();
        $sale = $this->sell([$this->line($this->item('150'), '2')], [], $c);   // 300 on account
        $this->assertSame('300.00', $this->balance($c));

        $return = $this->giveBack($sale, [0 => ['quantity' => '1']]);

        $this->assertSame('150.00', $return->applied_to_due);
        $this->assertSame('0.00', $return->refund_amount);
        $this->assertSame('0.00', $return->store_credit_amount);
        $this->assertSame('150.00', $sale->fresh()->due_amount);
        $this->assertSame('150.00', $this->balance($c));
        $entry = CustomerLedgerEntry::query()->where('entry_type', 'sale_return')->sole();
        $this->assertSame('150.00', $entry->credit);
        $this->assertSame($return->id, $entry->source_id);
        $this->assertSame(0, CustomerPayment::query()->count(), 'No money moved: the debt just got smaller.');
        $this->assertBooksAgree();
    }

    public function test_what_is_left_after_the_debt_is_handed_back_as_money_when_chosen(): void
    {
        $c = $this->named();
        $sale = $this->sell([$this->line($this->item('150'), '2')], [['cash', '100']], $c);   // 200 still owed
        $this->assertSame('200.00', $this->balance($c));

        $return = $this->giveBack($sale, [0 => ['quantity' => '2']], ['remainder' => 'refund', 'refund_method_id' => $this->method()->id]);

        $this->assertSame('300.00', $return->total);
        $this->assertSame('200.00', $return->applied_to_due);
        $this->assertSame('100.00', $return->refund_amount);
        $this->assertSame('0.00', $sale->fresh()->due_amount);
        $this->assertSame('0.00', $this->balance($c), 'Owed 200, goods worth 300, 100 handed back: square.');
        $this->assertSame(['sale', 'payment', 'sale_return', 'refund'], CustomerLedgerEntry::query()->orderBy('id')->pluck('entry_type')->all());
        $this->assertSame('refund', $return->refundPayment->type);
        $this->assertBooksAgree();
    }

    public function test_what_is_left_can_stay_as_store_credit_which_the_customer_can_spend(): void
    {
        $c = $this->named();
        $sale = $this->sell([$this->line($this->item('150'), '2')], [['cash', '100']], $c);

        $return = $this->giveBack($sale, [0 => ['quantity' => '2']], ['remainder' => 'credit']);

        $this->assertSame('100.00', $return->store_credit_amount);
        $this->assertSame('-100.00', $this->balance($c), 'We owe them 100.');
        $this->assertSame('100.00', app(CustomerAccountService::class)->advanceFor($c->id));
        $credit = $return->creditPayment;
        $this->assertSame('customer_credit', $credit->method->kind);
        $this->assertSame(0, CustomerLedgerEntry::query()->where('source_type', 'customer_payment')->where('source_id', $credit->id)->count(), 'The return already credited the account.');
        $this->assertBooksAgree();

        // and they can spend it on the next sale
        $next = $this->sell([$this->line($this->item('80'), '1')], [['customer_credit', '80']], $c);
        $this->assertSame('paid', $next->payment_status);
        $this->assertSame('20.00', app(CustomerAccountService::class)->advanceFor($c->id));
        $this->assertBooksAgree();
    }

    public function test_something_must_be_chosen_for_the_money_left_over_and_store_credit_can_be_switched_off(): void
    {
        $c = $this->named();
        $sale = $this->sell([$this->line($this->item('150'), '2')], [['cash', '300']], $c);   // paid in full: all of the return is left over

        try {
            $this->giveBack($sale, [0 => ['quantity' => '1']]);
            $this->fail('Money was moved without being asked.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('money back or store credit', $e->getMessage());
        }

        app(SettingsService::class)->set('sales.store_credit_enabled', false);
        try {
            $this->giveBack($sale, [0 => ['quantity' => '1']], ['remainder' => 'credit']);
            $this->fail('Store credit was given while switched off.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('switched off', $e->getMessage());
        }
        $this->assertSame(0, SaleReturn::query()->count());

        $this->assertSame('150.00', $this->giveBack($sale, [0 => ['quantity' => '1']], ['remainder' => 'refund'])->refund_amount);
    }

    public function test_only_people_who_may_refund_can_hand_money_back(): void
    {
        $sale = $this->sell([$this->line($this->item('150'), '2')]);
        $this->asWithOnly(['sale_returns.create']);
        $limited = auth()->user();

        try {
            $this->giveBack($sale, [0 => ['quantity' => '1']], [], 'Faulty', $limited);
            $this->fail('Money was handed back without the permission.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('refunds', $e->getMessage());
        }

        $this->expectException(AuthorizationException::class);   // and a cashier cannot take goods back at all
        $this->giveBack($sale, [0 => ['quantity' => '1']], [], 'Faulty', $this->cashier);
    }

    public function test_a_return_is_audited(): void
    {
        $sale = $this->sell([$this->line($this->item('150'))]);
        $return = $this->giveBack($sale, [0 => ['quantity' => '1', 'condition' => 'defective']], [], 'Does not switch on');

        $log = AuditLog::query()->where('event', 'sale_return_created')->sole();
        $this->assertSame($return->id, $log->auditable_id);
        $this->assertSame('defective', $log->new_values['lines'][0]['condition']);
        $this->assertSame('Does not switch on', $log->new_values['reason']);
    }

    // ---------------------------------------------------------------- cancelling a return

    public function test_cancelling_a_return_puts_everything_back_as_it_was(): void
    {
        $c = $this->named();
        $p = $this->item('150', '10', '100');
        $sale = $this->sell([$this->line($p, '2')], [['cash', '100']], $c);
        $return = $this->giveBack($sale, [0 => ['quantity' => '2']], ['remainder' => 'refund', 'refund_method_id' => $this->method()->id]);
        $this->assertSame('10.000', $p->fresh()->current_stock);

        $void = app(VoidSaleReturn::class)->execute($return, 'Entered by mistake', $this->manager);

        $this->assertTrue($void->isVoided());
        $this->assertSame('8.000', $p->fresh()->current_stock, 'The units left the shelf again.');
        $this->assertSame('200.00', $this->balance($c));
        $this->assertSame('200.00', $sale->fresh()->due_amount);
        $this->assertSame('0.00', $sale->fresh()->returned_amount);
        $this->assertSame('0.000', $sale->items->sole()->fresh()->returned_quantity);
        $this->assertSame('voided', $return->refundPayment->fresh()->status);
        $this->assertSame(['sale_return', 'sale_return_void'], StockMovement::query()->whereIn('movement_type', ['sale_return', 'sale_return_void'])->orderBy('id')->pluck('movement_type')->all());
        $this->assertSame(1, AuditLog::query()->where('event', 'sale_return_voided')->count());
        $this->assertBooksAgree();

        // the same goods can now be returned again
        $this->assertSame('SRN-000002', $this->giveBack($sale, [0 => ['quantity' => '2']], ['remainder' => 'credit'])->return_no);
        $this->assertBooksAgree();

        $this->expectException(DomainException::class);   // never twice
        app(VoidSaleReturn::class)->execute($return, 'again', $this->manager);
    }

    public function test_a_return_cannot_be_cancelled_once_its_store_credit_is_spent(): void
    {
        $c = $this->named();
        $sale = $this->sell([$this->line($this->item('150'), '2')], [['cash', '300']], $c);
        $return = $this->giveBack($sale, [0 => ['quantity' => '2']], ['remainder' => 'credit']);
        $this->sell([$this->line($this->item('100'), '1')], [['customer_credit', '100']], $c);   // spends 100 of the 300

        try {
            app(VoidSaleReturn::class)->execute($return, 'Oops', $this->manager);
            $this->fail('A return with spent store credit was cancelled.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('already been spent', $e->getMessage());
        }
        $this->assertFalse($return->fresh()->isVoided());
        $this->assertBooksAgree();
    }

    public function test_cancelling_needs_a_reason_and_the_permission(): void
    {
        $sale = $this->sell([$this->line($this->item('150'))]);
        $return = $this->giveBack($sale, [0 => ['quantity' => '1']]);

        try {
            app(VoidSaleReturn::class)->execute($return, '  ', $this->manager);
            $this->fail('Cancelled without a reason.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(AuthorizationException::class);
        app(VoidSaleReturn::class)->execute($return, 'x', $this->cashier);
    }

    public function test_an_invoice_with_a_return_cannot_be_cancelled(): void
    {
        $sale = $this->sell([$this->line($this->item('150'))]);
        $this->giveBack($sale, [0 => ['quantity' => '1']]);

        $this->expectException(DomainException::class);
        app(VoidSale::class)->execute($sale->fresh(), 'Mistake', $this->manager);
    }

    // ---------------------------------------------------------------- the integrity check sees tampering

    public function test_the_integrity_check_notices_a_return_that_does_not_match_its_invoice(): void
    {
        $c = $this->named();
        $sale = $this->sell([$this->line($this->item('150'), '2')], [['cash', '300']], $c);
        $return = $this->giveBack($sale, [0 => ['quantity' => '1']], ['remainder' => 'refund']);
        $this->assertBooksAgree();

        DB::table('sales')->where('id', $sale->id)->update(['returned_amount' => '10.00']);
        $this->assertNotSame([], app(SaleAccountService::class)->findDrift());
        DB::table('sales')->where('id', $sale->id)->update(['returned_amount' => $return->total]);
        $this->assertBooksAgree();

        DB::table('sale_items')->where('sale_id', $sale->id)->update(['returned_quantity' => '2.000']);
        $this->assertNotSame([], app(SaleAccountService::class)->findDrift());
        DB::table('sale_items')->where('sale_id', $sale->id)->update(['returned_quantity' => '1.000']);

        DB::table('sale_return_items')->where('sale_return_id', $return->id)->update(['net_amount' => '1.00']);
        $this->assertNotSame([], app(SaleAccountService::class)->findDrift());
    }

    // ---------------------------------------------------------------- the pages

    public function test_the_pages_work_end_to_end(): void
    {
        $p = $this->item('150', '10', '100');
        $sale = $this->sell([$this->line($p, '2')]);

        $this->as('Manager');
        $this->get('/sale-returns')->assertOk();
        $this->get('/sale-returns/create')->assertOk()->assertSee('Which invoice');
        $this->get('/sale-returns/create?invoice='.$sale->invoice_no)->assertOk()->assertSee($sale->invoice_no)->assertSee($p->name);
        $this->get('/sale-returns/create?invoice=INV-999999')->assertRedirect();
        $this->get('/sale-returns/create?sale='.$sale->id)->assertOk()->assertSee('Record the return');

        $item = $sale->items->sole();
        $this->post("/sale-returns/sales/{$sale->id}", ['reason' => 'Faulty', 'remainder' => 'refund', 'lines' => [$item->id => ['quantity' => '1', 'condition' => 'resellable', 'restock' => '1']]])
            ->assertRedirect();
        $return = SaleReturn::query()->sole();
        $this->assertSame('150.00', $return->total);
        $this->assertSame('9.000', $p->fresh()->current_stock);

        $this->get("/sale-returns/{$return->id}")->assertOk()->assertSee($return->return_no)->assertSee('Cancel this return')->assertSee('put back');
        $this->get('/sale-returns?q='.$return->return_no.'&status=all&from=2020-01-01&to=2099-01-01')->assertOk()->assertSee($return->return_no);
        $this->get("/sales/{$sale->id}")->assertOk()->assertSee('Return items')->assertSee($return->return_no);

        // the original receipt only opens through the signed link; a reprint is marked and audited
        $this->get("/sale-returns/{$return->id}/print")->assertForbidden();
        $this->get(URL::temporarySignedRoute('sale-returns.print', now()->addMinutes(5), ['saleReturn' => $return->id]))->assertOk()->assertSee('RETURN RECEIPT')->assertDontSee('DUPLICATE');
        $this->get("/sale-returns/{$return->id}/reprint")->assertOk()->assertSee('DUPLICATE');
        $this->assertSame(1, AuditLog::query()->where('event', 'sale_return_reprinted')->count());

        // too much is refused with a message, not an error page
        $this->post("/sale-returns/sales/{$sale->id}", ['reason' => 'x', 'lines' => [$item->id => ['quantity' => '5']]])->assertSessionHas('error');
        $this->post("/sale-returns/sales/{$sale->id}", ['reason' => '', 'lines' => [$item->id => ['quantity' => '1']]])->assertSessionHasErrors('reason');
        $this->assertSame(1, SaleReturn::query()->count());

        // cancelling asks for the password and a reason
        $this->post("/sale-returns/{$return->id}/void", ['reason' => 'Entered by mistake']);
        $this->assertFalse($return->fresh()->isVoided(), 'the password was not asked for');
        $this->withSession($this->confirmed())->post("/sale-returns/{$return->id}/void", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->withSession($this->confirmed())->post("/sale-returns/{$return->id}/void", ['reason' => 'Entered by mistake'])->assertRedirect();
        $this->assertTrue($return->fresh()->isVoided());
        $this->get("/sale-returns/{$return->id}")->assertOk()->assertSee('Cancelled')->assertSee('Entered by mistake');
        $this->assertBooksAgree();
    }

    public function test_the_customer_page_lists_the_goods_they_brought_back(): void
    {
        $c = $this->named();
        $sale = $this->sell([$this->line($this->item('150'), '2')], [['cash', '300']], $c);
        $return = $this->giveBack($sale, [0 => ['quantity' => '1']], ['remainder' => 'refund']);

        $this->as('Manager');
        $this->get("/customers/{$c->id}")->assertOk()->assertSee('Goods brought back')->assertSee($return->return_no)->assertSee($sale->invoice_no);
        $this->as('Cashier');
        $this->get("/customers/{$c->id}")->assertOk()->assertSee($return->return_no);

        app(VoidSaleReturn::class)->execute($return, 'Mistake', $this->manager);
        $this->as('Manager')->get("/customers/{$c->id}")->assertOk()->assertSee($return->return_no)->assertSee('Cancelled');
    }

    public function test_the_return_form_shows_serial_numbers_and_the_state_of_the_warranty(): void
    {
        $this->as('Manager');
        $covered = $this->item('150', '50', '100', ['warranty_months' => 12]);
        $plain = $this->item('90');
        $sale = $this->sell([['product_id' => $covered->id, 'quantity' => '1', 'serial_number' => 'SN-777'], $this->line($plain)]);

        $page = $this->get("/sale-returns/create?sale={$sale->id}")->assertOk();
        $page->assertSee('S/N SN-777')->assertSee('Under warranty until');

        DB::table('sale_items')->where('id', $sale->items()->orderBy('id')->first()->id)->update(['warranty_until' => now()->subDay()->toDateString()]);
        $this->get("/sale-returns/create?sale={$sale->id}")->assertOk()->assertSee('Warranty ended');
    }

    public function test_a_cashier_can_look_at_returns_but_not_make_or_cancel_them(): void
    {
        $sale = $this->sell([$this->line($this->item('150'), '2')]);
        $return = $this->giveBack($sale, [0 => ['quantity' => '1']]);
        $item = $sale->items->sole();

        $this->as('Cashier');
        $this->get('/sale-returns')->assertOk();
        $this->get("/sale-returns/{$return->id}")->assertOk();
        $this->get('/sale-returns/create')->assertForbidden();
        $this->post("/sale-returns/sales/{$sale->id}", ['reason' => 'x', 'lines' => [$item->id => ['quantity' => '1']]])->assertForbidden();
        $this->withSession($this->confirmed())->post("/sale-returns/{$return->id}/void", ['reason' => 'x'])->assertForbidden();

        $this->as('Technician');
        $this->get('/sale-returns')->assertForbidden();
        $this->get("/sale-returns/{$return->id}")->assertForbidden();
        $this->get("/sale-returns/{$return->id}/reprint")->assertForbidden();
    }
}
