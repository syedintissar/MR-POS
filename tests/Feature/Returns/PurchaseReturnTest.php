<?php

namespace Tests\Feature\Returns;

use App\Actions\Purchasing\CreatePurchaseReturn;
use App\Actions\Purchasing\PostSupplierPayment;
use App\Actions\Purchasing\ReceivePurchase;
use App\Actions\Purchasing\SavePurchaseDraft;
use App\Actions\Purchasing\VoidPurchase;
use App\Actions\Purchasing\VoidPurchaseReturn;
use App\Actions\Purchasing\VoidSupplierPayment;
use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Purchasing\SupplierAccountService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/** Sending goods back to a supplier: what the supplier credits, where the money goes, the stock, and undoing a return. */
class PurchaseReturnTest extends TestCase
{
    use ActsAsRoles;
    use MakesStock;
    use RefreshesSeededDatabase;

    private User $manager;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->withRole('Manager')->create();
        $this->supplier = Supplier::factory()->create();
    }

    private function method(string $code = 'cash'): PaymentMethod
    {
        return PaymentMethod::query()->where('code', $code)->firstOrFail();
    }

    /** One received bill for one fresh product, optionally paid in full on receiving. */
    private function bill(string $qty, string $cost, array $over = [], bool $paid = false, ?Product $product = null): Purchase
    {
        $product ??= Product::factory()->create();
        $draft = app(SavePurchaseDraft::class)->execute($over + [
            'supplier_id' => $this->supplier->id, 'purchase_date' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => $qty, 'unit_cost' => $cost]],
        ], $this->manager);

        $payment = null;
        if ($paid) {
            $payment = ['payment_method_id' => $this->method()->id, 'amount' => (string) $draft->grand_total];
        }

        return app(ReceivePurchase::class)->execute($draft, $this->manager, $payment);
    }

    /** @param  array<int, string>  $qty  quantity to send back, keyed by the position of the line on the bill */
    private function sendBack(Purchase $p, array $qty, array $over = [], ?User $by = null): PurchaseReturn
    {
        $items = $p->items()->orderBy('id')->get();
        $lines = [];
        foreach ($qty as $pos => $q) {
            $lines[] = ['purchase_item_id' => $items[$pos]->id, 'quantity' => $q];
        }

        return app(CreatePurchaseReturn::class)->execute($over + ['purchase_id' => $p->id, 'lines' => $lines, 'reason' => 'Faulty batch'], $by ?? $this->manager);
    }

    private function balance(): string
    {
        return (string) $this->supplier->fresh()->getRawOriginal('balance');
    }

    private function assertBooksAgree(): void
    {
        $this->assertSame([], app(SupplierAccountService::class)->findDrift());
        $this->assertSame([], $this->inventory()->findDrift());
        $this->assertSame(app(SupplierAccountService::class)->balanceFromLedger($this->supplier->id), $this->balance());
    }

    // ---------------------------------------------------------------- against a bill

    public function test_goods_sent_back_leave_stock_at_their_landed_cost_and_reduce_what_we_owe(): void
    {
        $bill = $this->bill('10', '100', ['additional_cost' => '100']);   // 1100 owed, landed cost 110 a unit
        $product = $bill->items->sole()->product;
        $this->assertSame('1100.00', $this->balance());

        $return = $this->sendBack($bill, [0 => '4']);

        $this->assertSame('PRN-000001', $return->return_no);
        $this->assertSame('400.00', $return->total, 'The supplier credits what it billed for those units: 4 x 100. The freight is not credited.');
        $this->assertSame('adjust_balance', $return->settlement);
        $this->assertSame('6.000', $product->fresh()->current_stock);
        $move = StockMovement::query()->where('movement_type', 'purchase_return')->sole();
        $this->assertSame('-4.000', $move->quantity);
        $this->assertSame('110.0000', $move->unit_cost, 'Stock leaves at what it cost to land.');
        $this->assertSame('400.00', $bill->fresh()->returned_amount);
        $this->assertSame('700.00', $bill->fresh()->due_amount);
        $this->assertSame('700.00', $this->balance());
        $entry = SupplierLedgerEntry::query()->where('entry_type', 'purchase_return')->sole();
        $this->assertSame('400.00', $entry->debit);
        $this->assertSame($return->id, $entry->source_id);
        $this->assertSame('4.000', $bill->items->sole()->fresh()->returned_quantity);
        $this->assertBooksAgree();
    }

    public function test_the_last_units_of_a_line_take_what_is_left_after_its_discount(): void
    {
        $bill = $this->bill('3', '100', ['lines' => [['product_id' => Product::factory()->create()->id, 'quantity' => '3', 'unit_cost' => '100', 'discount_amount' => '10']]]);
        $this->assertSame('290.00', $bill->grand_total);

        $totals = [$this->sendBack($bill, [0 => '1'])->total, $this->sendBack($bill, [0 => '1'])->total, $this->sendBack($bill, [0 => '1'])->total];

        $this->assertSame(['96.67', '96.67', '96.66'], $totals);
        $this->assertSame('290.00', $bill->fresh()->returned_amount);
        $this->assertSame('0.00', $this->balance());
        $this->assertBooksAgree();
    }

    public function test_more_than_was_received_or_than_is_left_is_refused(): void
    {
        $bill = $this->bill('5', '100');
        $this->sendBack($bill, [0 => '3']);

        foreach (['3', '2.5', '99'] as $qty) {
            try {
                $this->sendBack($bill, [0 => $qty]);
                $this->fail("Sent back {$qty} when only 2 were left.");
            } catch (DomainException $e) {
                $this->assertStringContainsString('only 2 can still be sent back', $e->getMessage());
            }
        }
        $this->assertSame(1, PurchaseReturn::query()->count());
        $this->assertBooksAgree();
    }

    public function test_goods_that_have_already_been_sold_cannot_be_sent_back(): void
    {
        $bill = $this->bill('10', '100');
        $product = $bill->items->sole()->product;
        $this->inventory()->move($product, '-8', 'sale', ['unit_cost' => '100']);   // only 2 left on the shelf

        try {
            $this->sendBack($bill, [0 => '4']);
            $this->fail('Sent back goods that are no longer there.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Not enough in stock', $e->getMessage());
        }
        $this->assertSame(0, PurchaseReturn::query()->count());
        $this->assertSame('1000.00', $this->balance(), 'Nothing was booked.');
        $this->assertSame('2.000', $product->fresh()->current_stock);

        $this->assertSame('200.00', $this->sendBack($bill, [0 => '2'])->total);
    }

    public function test_bad_returns_are_refused_and_leave_nothing_behind(): void
    {
        $bill = $this->bill('5', '100');
        $other = $this->bill('5', '100');
        $draft = app(SavePurchaseDraft::class)->execute(['supplier_id' => $this->supplier->id, 'purchase_date' => now()->toDateString(), 'lines' => [['product_id' => Product::factory()->create()->id, 'quantity' => '1', 'unit_cost' => '10']]], $this->manager);
        $create = fn (array $data) => fn () => app(CreatePurchaseReturn::class)->execute($data + ['reason' => 'x'], $this->manager);
        $item = $bill->items->first();

        $bad = [
            'no lines' => $create(['purchase_id' => $bill->id, 'lines' => []]),
            'zero quantity' => $create(['purchase_id' => $bill->id, 'lines' => [['purchase_item_id' => $item->id, 'quantity' => '0']]]),
            'no reason' => fn () => app(CreatePurchaseReturn::class)->execute(['purchase_id' => $bill->id, 'lines' => [['purchase_item_id' => $item->id, 'quantity' => '1']], 'reason' => '  '], $this->manager),
            'line of another bill' => $create(['purchase_id' => $bill->id, 'lines' => [['purchase_item_id' => $other->items->first()->id, 'quantity' => '1']]]),
            'line without an item' => $create(['purchase_id' => $bill->id, 'lines' => [['product_id' => $item->product_id, 'quantity' => '1']]]),
            'same item twice' => $create(['purchase_id' => $bill->id, 'lines' => [['purchase_item_id' => $item->id, 'quantity' => '1'], ['purchase_item_id' => $item->id, 'quantity' => '1']]]),
            'a draft' => $create(['purchase_id' => $draft->id, 'lines' => [['purchase_item_id' => $draft->items->first()->id, 'quantity' => '1']]]),
            'unknown way to settle' => $create(['purchase_id' => $bill->id, 'settlement' => 'gift', 'lines' => [['purchase_item_id' => $item->id, 'quantity' => '1']]]),
            'no supplier' => $create(['lines' => [['product_id' => $item->product_id, 'quantity' => '1', 'unit_credit' => '10']]]),
        ];
        foreach ($bad as $why => $try) {
            try {
                $try();
                $this->fail("Accepted: {$why}");
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, PurchaseReturn::query()->count());
        $this->assertBooksAgree();
    }

    public function test_a_cancelled_bill_cannot_have_goods_sent_back(): void
    {
        $bill = $this->bill('5', '100');
        app(VoidPurchase::class)->execute($bill, 'Mistake', $this->manager);

        $this->expectException(DomainException::class);
        $this->sendBack($bill, [0 => '1']);
    }

    // ---------------------------------------------------------------- where the credit goes

    public function test_on_a_paid_bill_the_credit_stays_on_the_suppliers_account_or_comes_back_as_money(): void
    {
        $bill = $this->bill('10', '100', [], paid: true);
        $this->assertSame('0.00', $this->balance());

        $credit = $this->sendBack($bill, [0 => '3']);
        $this->assertSame('adjust_balance', $credit->settlement);
        $this->assertNull($credit->supplier_payment_id);
        $this->assertSame('-300.00', $this->balance(), 'The supplier holds 300 of ours.');
        $this->assertSame('0.00', $bill->fresh()->due_amount);
        $this->assertSame(0, SupplierPayment::query()->where('type', 'refund')->count());
        $this->assertBooksAgree();

        $refund = $this->sendBack($bill, [0 => '2'], ['settlement' => 'refund', 'payment_method_id' => $this->method()->id]);
        $this->assertSame('refund', $refund->settlement);
        $payment = $refund->refundPayment;
        $this->assertSame('refund', $payment->type);
        $this->assertSame('200.00', $payment->amount);
        $this->assertSame('-300.00', $this->balance(), 'Credit 200, money received 200: nothing changes.');
        $this->assertSame(['purchase', 'payment', 'purchase_return', 'purchase_return', 'refund_received'], SupplierLedgerEntry::query()->orderBy('id')->pluck('entry_type')->all());
        $this->assertBooksAgree();
    }

    public function test_the_credit_first_reduces_what_is_still_owed_on_the_bill_and_only_the_rest_is_refunded(): void
    {
        $bill = $this->bill('10', '100');
        app(PostSupplierPayment::class)->execute(['supplier_id' => $this->supplier->id, 'payment_method_id' => $this->method()->id, 'amount' => '800', 'allocation' => 'manual', 'allocations' => [$bill->id => '800']], $this->manager);
        $this->assertSame('200.00', $bill->fresh()->due_amount);

        $return = $this->sendBack($bill, [0 => '5'], ['settlement' => 'refund', 'payment_method_id' => $this->method()->id]);   // worth 500: 200 off the bill, 300 back

        $this->assertSame('500.00', $return->total);
        $this->assertSame('300.00', $return->refundPayment->amount);
        $this->assertSame('0.00', $bill->fresh()->due_amount);
        $this->assertSame('0.00', $this->balance(), 'Owed 1000, paid 800, goods worth 500 back, 300 received: square.');
        $this->assertBooksAgree();
    }

    public function test_when_the_credit_all_goes_off_the_bill_there_is_nothing_to_refund(): void
    {
        $bill = $this->bill('10', '100');   // unpaid

        $return = $this->sendBack($bill, [0 => '3'], ['settlement' => 'refund', 'payment_method_id' => $this->method()->id]);

        $this->assertSame('adjust_balance', $return->settlement);
        $this->assertNull($return->supplier_payment_id);
        $this->assertSame('700.00', $bill->fresh()->due_amount);
        $this->assertSame('700.00', $this->balance());
        $this->assertBooksAgree();
    }

    public function test_a_refund_needs_a_usable_method_a_reference_where_required_and_the_permission(): void
    {
        $bill = $this->bill('10', '100', [], paid: true);

        foreach ([null, 9999, PaymentMethod::query()->where('kind', 'customer_credit')->value('id')] as $bad) {
            try {
                $this->sendBack($bill, [0 => '1'], ['settlement' => 'refund', 'payment_method_id' => $bad]);
                $this->fail('Refunded without a usable method.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, PurchaseReturn::query()->count());

        $this->asWithOnly(['purchase_returns.create']);
        $this->expectException(AuthorizationException::class);
        $this->sendBack($bill, [0 => '1'], ['settlement' => 'refund', 'payment_method_id' => $this->method()->id], auth()->user());
    }

    public function test_a_refund_can_not_be_cancelled_on_its_own(): void
    {
        $bill = $this->bill('10', '100', [], paid: true);
        $refund = $this->sendBack($bill, [0 => '2'], ['settlement' => 'refund', 'payment_method_id' => $this->method()->id])->refundPayment;

        $this->expectException(DomainException::class);
        app(VoidSupplierPayment::class)->execute($refund, 'x', $this->manager);
    }

    // ---------------------------------------------------------------- without a bill

    public function test_goods_can_be_sent_back_without_a_bill_for_the_credit_the_supplier_gives(): void
    {
        $p = $this->stocked('10', '50');

        $return = app(CreatePurchaseReturn::class)->execute([
            'supplier_id' => $this->supplier->id, 'reason' => 'Wrong model', 'lines' => [['product_id' => $p->id, 'quantity' => '2', 'unit_credit' => '60']],
        ], $this->manager);

        $this->assertNull($return->purchase_id);
        $this->assertSame('120.00', $return->total);
        $this->assertSame('8.000', $p->fresh()->current_stock);
        $this->assertSame('50.0000', StockMovement::query()->where('movement_type', 'purchase_return')->sole()->unit_cost, 'Stock leaves at its average cost.');
        $this->assertSame('-120.00', $this->balance());
        $this->assertBooksAgree();
    }

    public function test_free_form_lines_need_a_credit_a_stock_item_and_enough_stock(): void
    {
        $p = $this->stocked('2', '50');
        $service = Product::factory()->service()->create();
        $try = fn (array $line) => fn () => app(CreatePurchaseReturn::class)->execute(['supplier_id' => $this->supplier->id, 'reason' => 'x', 'lines' => [$line]], $this->manager);

        foreach ([
            'no credit' => $try(['product_id' => $p->id, 'quantity' => '1']),
            'negative credit' => $try(['product_id' => $p->id, 'quantity' => '1', 'unit_credit' => '-5']),
            'a service item' => $try(['product_id' => $service->id, 'quantity' => '1', 'unit_credit' => '5']),
            'more than we have' => $try(['product_id' => $p->id, 'quantity' => '3', 'unit_credit' => '5']),
            'unknown product' => $try(['product_id' => 99999, 'quantity' => '1', 'unit_credit' => '5']),
        ] as $why => $fn) {
            try {
                $fn();
                $this->fail("Accepted: {$why}");
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, PurchaseReturn::query()->count());
        $this->assertBooksAgree();
    }

    // ---------------------------------------------------------------- cancelling a return

    public function test_cancelling_a_return_puts_everything_back_as_it_was(): void
    {
        $bill = $this->bill('10', '100', [], paid: true);
        $product = $bill->items->sole()->product;
        $return = $this->sendBack($bill, [0 => '4'], ['settlement' => 'refund', 'payment_method_id' => $this->method()->id]);
        $this->assertSame('6.000', $product->fresh()->current_stock);

        $void = app(VoidPurchaseReturn::class)->execute($return, 'Entered by mistake', $this->manager);

        $this->assertTrue($void->isVoided());
        $this->assertSame('10.000', $product->fresh()->current_stock);
        $this->assertSame('0.00', $this->balance());
        $this->assertSame('0.00', $bill->fresh()->returned_amount);
        $this->assertSame('0.00', $bill->fresh()->due_amount);
        $this->assertSame('0.000', $bill->items->sole()->fresh()->returned_quantity);
        $this->assertSame('voided', $return->refundPayment->fresh()->status);
        $this->assertSame(['purchase_return', 'purchase_return_void'], StockMovement::query()->whereIn('movement_type', ['purchase_return', 'purchase_return_void'])->orderBy('id')->pluck('movement_type')->all());
        $this->assertSame(1, AuditLog::query()->where('event', 'purchase_return_voided')->count());
        $this->assertBooksAgree();

        $this->assertSame('PRN-000002', $this->sendBack($bill, [0 => '10'])->return_no, 'All of it can be sent back now.');

        $this->expectException(DomainException::class);   // never twice
        app(VoidPurchaseReturn::class)->execute($return, 'again', $this->manager);
    }

    public function test_a_bill_with_a_return_cannot_be_cancelled_until_the_return_is(): void
    {
        $bill = $this->bill('10', '100');
        $return = $this->sendBack($bill, [0 => '2']);

        try {
            app(VoidPurchase::class)->execute($bill->fresh(), 'Mistake', $this->manager);
            $this->fail('A bill with a return was cancelled.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Cancel the return first', $e->getMessage());
        }

        app(VoidPurchaseReturn::class)->execute($return, 'Undo', $this->manager);
        $this->assertTrue(app(VoidPurchase::class)->execute($bill->fresh(), 'Mistake', $this->manager)->isVoided());
        $this->assertBooksAgree();
    }

    public function test_cancelling_needs_a_reason_and_the_permission(): void
    {
        $return = $this->sendBack($this->bill('5', '100'), [0 => '1']);

        try {
            app(VoidPurchaseReturn::class)->execute($return, '  ', $this->manager);
            $this->fail('Cancelled without a reason.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(AuthorizationException::class);
        app(VoidPurchaseReturn::class)->execute($return, 'x', User::factory()->withRole('Cashier')->create());
    }

    public function test_only_people_who_may_create_purchase_returns_can(): void
    {
        $bill = $this->bill('5', '100');

        $this->expectException(AuthorizationException::class);
        $this->sendBack($bill, [0 => '1'], [], User::factory()->withRole('Cashier')->create());
    }

    public function test_a_return_is_audited(): void
    {
        $return = $this->sendBack($this->bill('5', '100'), [0 => '2']);

        $log = AuditLog::query()->where('event', 'purchase_return_created')->sole();
        $this->assertSame($return->id, $log->auditable_id);
        $this->assertSame('200.00', $log->new_values['total']);
        $this->assertSame('Faulty batch', $log->new_values['reason']);
    }

    // ---------------------------------------------------------------- the integrity check sees tampering

    public function test_the_integrity_check_notices_a_return_that_does_not_match_its_bill_or_its_account(): void
    {
        $bill = $this->bill('10', '100', [], paid: true);
        $return = $this->sendBack($bill, [0 => '2'], ['settlement' => 'refund', 'payment_method_id' => $this->method()->id]);
        $this->assertBooksAgree();
        $drift = fn () => app(SupplierAccountService::class)->findDrift();

        DB::table('purchases')->where('id', $bill->id)->update(['returned_amount' => '5.00']);
        $this->assertNotSame([], $drift());
        DB::table('purchases')->where('id', $bill->id)->update(['returned_amount' => '200.00']);
        $this->assertSame([], $drift());

        DB::table('purchase_items')->where('purchase_id', $bill->id)->update(['returned_quantity' => '3.000']);
        $this->assertNotSame([], $drift());
        DB::table('purchase_items')->where('purchase_id', $bill->id)->update(['returned_quantity' => '2.000']);
        $this->assertSame([], $drift());

        DB::table('purchase_return_items')->where('purchase_return_id', $return->id)->update(['net_amount' => '1.00']);
        $this->assertNotSame([], $drift());
    }

    // ---------------------------------------------------------------- the pages

    public function test_the_pages_work_end_to_end(): void
    {
        $bill = $this->bill('10', '100', [], paid: true);
        $item = $bill->items->sole();

        $this->as('Manager');
        $this->get('/purchase-returns')->assertOk();
        $this->get('/purchase-returns/create')->assertOk()->assertSee('Which purchase');
        $this->get('/purchase-returns/create?purchase='.$bill->id)->assertOk()->assertSee($bill->purchase_no)->assertSee('Record the return');
        $this->get('/purchase-returns/create?number='.$bill->purchase_no)->assertOk()->assertSee($bill->purchase_no);
        $this->get('/purchase-returns/create?number=PUR-999999')->assertRedirect();
        $this->get('/purchase-returns/create?supplier='.$this->supplier->id)->assertOk()->assertSee('Without a purchase');

        $this->post("/purchase-returns/purchases/{$bill->id}", ['reason' => 'Faulty', 'settlement' => 'refund', 'payment_method_id' => $this->method()->id, 'lines' => [$item->id => ['quantity' => '2']]])->assertRedirect();
        $return = PurchaseReturn::query()->sole();
        $this->assertSame('200.00', $return->total);
        $this->assertSame('8.000', $item->product->fresh()->current_stock);

        $this->post('/purchase-returns/free', ['supplier_id' => $this->supplier->id, 'reason' => 'Wrong model', 'settlement' => 'adjust_balance', 'lines' => [['product_id' => $item->product_id, 'quantity' => '1', 'unit_credit' => '90']]])->assertRedirect();
        $this->assertSame(2, PurchaseReturn::query()->count());

        $this->get("/purchase-returns/{$return->id}")->assertOk()->assertSee($return->return_no)->assertSee('Cancel this return');
        $this->get('/purchase-returns?q='.$return->return_no.'&status=all&from=2020-01-01&to=2099-01-01&supplier_id='.$this->supplier->id)->assertOk()->assertSee($return->return_no);
        $this->get("/purchase-returns/{$return->id}/print")->assertOk()->assertSee($return->return_no);
        $this->get("/purchases/{$bill->id}")->assertOk()->assertSee('Send goods back')->assertSee($return->return_no);

        $this->post("/purchase-returns/purchases/{$bill->id}", ['reason' => 'x', 'lines' => [$item->id => ['quantity' => '50']]])->assertSessionHas('error');
        $this->post("/purchase-returns/purchases/{$bill->id}", ['reason' => '', 'lines' => [$item->id => ['quantity' => '1']]])->assertSessionHasErrors('reason');
        $this->assertSame(2, PurchaseReturn::query()->count());

        $this->post("/purchase-returns/{$return->id}/void", ['reason' => 'Entered by mistake']);
        $this->assertFalse($return->fresh()->isVoided(), 'the password was not asked for');
        $this->withSession($this->confirmed())->post("/purchase-returns/{$return->id}/void", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->withSession($this->confirmed())->post("/purchase-returns/{$return->id}/void", ['reason' => 'Entered by mistake'])->assertRedirect();
        $this->assertTrue($return->fresh()->isVoided());
        $this->get("/purchase-returns/{$return->id}")->assertOk()->assertSee('Cancelled')->assertSee('Entered by mistake');
        $this->assertBooksAgree();
    }

    public function test_a_cashier_cannot_see_or_make_purchase_returns(): void
    {
        $bill = $this->bill('5', '100');
        $return = $this->sendBack($bill, [0 => '1']);

        $this->as('Cashier');
        $this->get('/purchase-returns')->assertForbidden();
        $this->get("/purchase-returns/{$return->id}")->assertForbidden();
        $this->get('/purchase-returns/create')->assertForbidden();
        $this->post("/purchase-returns/purchases/{$bill->id}", ['reason' => 'x', 'lines' => []])->assertForbidden();
        $this->withSession($this->confirmed())->post("/purchase-returns/{$return->id}/void", ['reason' => 'x'])->assertForbidden();
    }
}
