<?php

namespace Tests\Feature\Customers;

use App\Actions\Customers\PostCustomerPayment;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\VoidSale;
use App\Exceptions\ApprovalRequiredException;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\CustomerPayment;
use App\Models\DocumentSequence;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\Customers\CustomerAccountService;
use App\Services\Sales\PosApprovalService;
use App\Services\Sales\SaleAccountService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/** Selling to a named customer: credit, part payment, credit limits, customer credit, and cancelling it all again. */
class CreditSaleTest extends TestCase
{
    use MakesStock;
    use RefreshesSeededDatabase;

    private User $cashier;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->withRole('Cashier')->create();
        $this->manager = User::factory()->withRole('Manager')->create();
    }

    private function method(string $code = 'cash'): PaymentMethod
    {
        return PaymentMethod::query()->where('code', $code)->firstOrFail();
    }

    private function credit(array $attrs = []): Customer
    {
        return Customer::factory()->create($attrs + ['allow_credit' => true]);
    }

    private function item(string $price = '150', string $qty = '50', string $cost = '100', array $attrs = []): Product
    {
        return $this->stocked($qty, $cost, ['sale_price' => $price] + $attrs);
    }

    /** @param  list<array{0: string, 1: string}>  $pay  [method code, amount] */
    private function sell(Customer $c, Product $p, string $qty, array $pay = [], array $over = [], ?User $by = null): Sale
    {
        return app(CompleteSale::class)->execute($over + [
            'idempotency_key' => (string) Str::uuid(),
            'customer_id' => $c->id,
            'lines' => [['product_id' => $p->id, 'quantity' => $qty]],
            'payments' => array_map(fn ($x) => ['payment_method_id' => $this->method($x[0])->id, 'amount' => $x[1]], $pay),
        ], $by ?? $this->cashier);
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

    // ---------------------------------------------------------------- selling to a named customer

    public function test_a_named_customer_paying_in_full_leaves_their_account_at_zero_with_every_line_on_the_ledger(): void
    {
        $c = $this->credit();

        $sale = $this->sell($c, $this->item('150'), '2', [['cash', '300']]);

        $this->assertSame($c->id, $sale->customer_id);
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame('0.00', $this->balance($c));
        $entries = CustomerLedgerEntry::query()->orderBy('id')->get();
        $this->assertSame(['sale', 'payment'], $entries->pluck('entry_type')->all());
        $this->assertSame(['300.00', '0.00'], $entries->pluck('debit')->all());
        $this->assertSame(['0.00', '300.00'], $entries->pluck('credit')->all());
        $this->assertSame([$sale->id, CustomerPayment::query()->sole()->id], $entries->pluck('source_id')->all());
        $this->assertSame($sale->id, CustomerPayment::query()->sole()->sale_id, 'The receipt knows which sale it was taken for.');
        $this->assertBooksAgree();
    }

    public function test_a_credit_sale_leaves_the_whole_invoice_on_the_customers_account(): void
    {
        $c = $this->credit();

        $sale = $this->sell($c, $this->item('150'), '2');

        $this->assertSame('300.00', $sale->due_amount);
        $this->assertSame('0.00', $sale->paid_amount);
        $this->assertSame('unpaid', $sale->payment_status);
        $this->assertSame('300.00', $this->balance($c));
        $this->assertSame(0, CustomerPayment::query()->count(), 'No money was taken.');
        $this->assertBooksAgree();
    }

    public function test_a_part_payment_leaves_the_rest_on_the_account(): void
    {
        $c = $this->credit();

        $sale = $this->sell($c, $this->item('150'), '2', [['cash', '100']]);

        $this->assertSame('200.00', $sale->due_amount);
        $this->assertSame('partial', $sale->payment_status);
        $this->assertSame('200.00', $this->balance($c));
        $this->assertSame('100.00', $sale->amount_tendered);
        $this->assertBooksAgree();
    }

    public function test_a_customer_who_is_not_allowed_credit_must_pay_in_full(): void
    {
        $c = Customer::factory()->create(['allow_credit' => false]);
        $p = $this->item('150');

        try {
            $this->sell($c, $p, '2', [['cash', '100']]);
            $this->fail('Credit was given to a customer who is not allowed it.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('not allowed credit', $e->getMessage());
        }

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame('50.000', $p->fresh()->current_stock);
        $this->assertSame('0.00', $this->balance($c));
    }

    public function test_inactive_and_unknown_customers_cannot_buy(): void
    {
        $inactive = $this->credit(['is_active' => false]);
        $p = $this->item();

        foreach ([$inactive->id, 999999] as $id) {
            try {
                app(CompleteSale::class)->execute([
                    'idempotency_key' => (string) Str::uuid(), 'customer_id' => $id,
                    'lines' => [['product_id' => $p->id, 'quantity' => '1']], 'payments' => [['payment_method_id' => $this->method()->id, 'amount' => '150']],
                ], $this->cashier);
                $this->fail("Customer {$id} was served.");
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, Sale::query()->count());
    }

    public function test_the_walk_in_customer_still_has_to_pay_in_full(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('walk-in customer must pay in full');
        app(CompleteSale::class)->execute([
            'idempotency_key' => (string) Str::uuid(), 'lines' => [['product_id' => $this->item()->id, 'quantity' => '1']],
            'payments' => [['payment_method_id' => $this->method()->id, 'amount' => '50']],
        ], $this->cashier);
    }

    // ---------------------------------------------------------------- credit permission and limit

    public function test_selling_on_credit_needs_the_credit_permission_or_a_managers_approval(): void
    {
        $c = $this->credit();
        $p = $this->item('150');
        $noCredit = User::factory()->create();
        $noCredit->givePermissionTo(['pos.access', 'sales.create']);

        try {
            $this->sell($c, $p, '1', [], by: $noCredit);
            $this->fail('A user without the credit permission sold on credit.');
        } catch (ApprovalRequiredException $e) {
            $this->assertSame(['credit_sale'], array_column($e->reasons, 'code'));
            $this->assertSame(['sales.credit'], $e->permissions());
        }
        $this->assertSame(0, Sale::query()->count());

        $grant = app(PosApprovalService::class)->grant($noCredit, $this->manager->username, 'password');
        $sale = $this->sell($c, $p, '1', [], ['approval_token' => $grant['token']], $noCredit);
        $this->assertSame($this->manager->id, $sale->approved_by);
        $this->assertSame('150.00', $this->balance($c));
    }

    public function test_the_credit_limit_boundary_is_exact(): void
    {
        $c = $this->credit(['credit_limit' => '1000']);
        $p = $this->item('100');

        $this->sell($c, $p, '10');                     // exactly 1000: allowed
        $this->assertSame('1000.00', $this->balance($c));

        try {
            $this->sell($c, $p, '1');                  // 1100: over
            $this->fail('A cashier went over the credit limit.');
        } catch (ApprovalRequiredException $e) {
            $this->assertSame(['credit_limit'], array_column($e->reasons, 'code'));
            $this->assertSame(['sales.override_credit_limit'], $e->permissions());
            $this->assertStringContainsString('1100.00', $e->getMessage());
        }
        $this->assertSame('1000.00', $this->balance($c));
        $this->assertSame(1, Sale::query()->count());

        $manager = $this->sell($c, $p, '1', [], [], $this->manager);
        $this->assertSame('1100.00', $this->balance($c), 'A manager may exceed the limit.');
        $this->assertNull($manager->approved_by);
    }

    public function test_paying_part_now_counts_towards_the_limit(): void
    {
        $c = $this->credit(['credit_limit' => '500']);
        $p = $this->item('100');

        // 800 invoice, 400 paid now: the customer would owe 400, inside the limit
        $this->sell($c, $p, '8', [['cash', '400']]);
        $this->assertSame('400.00', $this->balance($c));

        $this->expectException(ApprovalRequiredException::class);
        $this->sell($c, $p, '2');                      // 400 + 200 = 600 > 500
    }

    public function test_no_limit_means_unlimited_credit(): void
    {
        $c = $this->credit(['credit_limit' => null]);

        $this->sell($c, $this->item('1000', '100'), '90');

        $this->assertSame('90000.00', $this->balance($c));
    }

    // ---------------------------------------------------------------- price list

    public function test_a_wholesale_customer_gets_wholesale_prices_without_anyone_approving(): void
    {
        $wholesale = $this->credit(['price_tier' => 'wholesale']);
        $retail = $this->credit();
        $p = $this->item('150', '50', '100', ['wholesale_price' => '130']);

        $sale = $this->sell($wholesale, $p, '1', [['cash', '130']]);
        $this->assertSame('130.00', $sale->grand_total);
        $this->assertSame('wholesale', $sale->price_tier);

        $this->assertSame('150.00', $this->sell($retail, $p, '1', [['cash', '150']])->grand_total);

        $this->expectException(ApprovalRequiredException::class);   // a cashier choosing the wholesale list for a RETAIL customer
        $this->sell($retail, $p, '1', [['cash', '130']], ['price_tier' => 'wholesale']);
    }

    // ---------------------------------------------------------------- customer credit (advance)

    private function advance(Customer $c, string $amount): CustomerPayment
    {
        return app(PostCustomerPayment::class)->execute(['customer_id' => $c->id, 'payment_method_id' => $this->method()->id, 'amount' => $amount, 'allocation' => 'advance'], $this->manager);
    }

    public function test_money_paid_in_advance_can_be_spent_as_customer_credit(): void
    {
        $c = $this->credit();
        $receipt = $this->advance($c, '500');
        $this->assertSame('-500.00', $this->balance($c));

        $sale = $this->sell($c, $this->item('150'), '2', [['customer_credit', '300']]);

        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame('-200.00', $this->balance($c), 'Only the invoice was added: the customer\'s money was already on the account.');
        $this->assertSame('200.00', app(CustomerAccountService::class)->advanceFor($c->id));
        $this->assertSame(1, CustomerPayment::query()->count(), 'No new receipt: the advance receipt is simply allocated.');
        $alloc = SalePayment::query()->where('sale_id', $sale->id)->sole();
        $this->assertSame($receipt->id, $alloc->customer_payment_id);
        $this->assertSame('300.00', $alloc->amount);
        $this->assertSame(['payment', 'sale'], CustomerLedgerEntry::query()->orderBy('id')->pluck('entry_type')->all());
        $this->assertBooksAgree();
    }

    public function test_customer_credit_can_be_combined_with_cash_and_with_the_rest_on_account(): void
    {
        $c = $this->credit();
        $this->advance($c, '100');
        $p = $this->item('150');

        // 450 invoice: 100 customer credit + 150 cash, 200 left on account
        $sale = $this->sell($c, $p, '3', [['customer_credit', '100'], ['cash', '150']]);

        $this->assertSame('200.00', $sale->due_amount);
        $this->assertSame('partial', $sale->payment_status);
        $this->assertSame('200.00', $this->balance($c), '-100 + 450 - 150');
        $this->assertSame('0.00', app(CustomerAccountService::class)->advanceFor($c->id));
        $this->assertBooksAgree();
    }

    public function test_change_comes_out_of_the_cash_when_customer_credit_is_also_used(): void
    {
        $c = $this->credit();
        $this->advance($c, '100');

        $sale = $this->sell($c, $this->item('150'), '2', [['customer_credit', '100'], ['cash', '250']]);   // 300 due: 100 credit + 200 cash + 50 change

        $this->assertSame('50.00', $sale->change_amount);
        $this->assertSame('200.00', CustomerPayment::query()->where('sale_id', $sale->id)->sole()->amount);
        $this->assertBooksAgree();
    }

    public function test_more_customer_credit_than_the_customer_has_is_refused(): void
    {
        $c = $this->credit();
        $this->advance($c, '100');

        try {
            $this->sell($c, $this->item('150'), '2', [['customer_credit', '150'], ['cash', '150']]);
            $this->fail('The customer spent credit they do not have.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('only 100.00 of credit', $e->getMessage());
        }
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame('100.00', app(CustomerAccountService::class)->advanceFor($c->id));
    }

    public function test_the_walk_in_customer_has_no_credit_to_spend(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('named customer');
        app(CompleteSale::class)->execute([
            'idempotency_key' => (string) Str::uuid(), 'lines' => [['product_id' => $this->item()->id, 'quantity' => '1']],
            'payments' => [['payment_method_id' => $this->method('customer_credit')->id, 'amount' => '150']],
        ], $this->cashier);
    }

    public function test_credit_is_spent_oldest_receipt_first(): void
    {
        $c = $this->credit();
        $first = $this->advance($c, '100');
        $second = $this->advance($c, '300');

        $sale = $this->sell($c, $this->item('150'), '2', [['customer_credit', '250']]);

        $this->assertEquals(
            [$first->id => '100.00', $second->id => '150.00'],
            SalePayment::query()->where('sale_id', $sale->id)->orderBy('customer_payment_id')->pluck('amount', 'customer_payment_id')->all(),
        );
        $this->assertSame('150.00', app(CustomerAccountService::class)->advanceFor($c->id));
    }

    // ---------------------------------------------------------------- cancelling

    public function test_cancelling_a_credit_sale_takes_it_off_the_account_again(): void
    {
        $c = $this->credit();
        $p = $this->item('150');
        $sale = $this->sell($c, $p, '2', [['cash', '100']]);
        $this->assertSame('200.00', $this->balance($c));

        $void = app(VoidSale::class)->execute($sale, 'Customer returned the same day', $this->manager);

        $this->assertSame('voided', $void->status);
        $this->assertSame('0.00', $this->balance($c));
        $this->assertSame('50.000', $p->fresh()->current_stock);
        $this->assertSame(['sale', 'payment', 'sale_void', 'payment_void'], CustomerLedgerEntry::query()->orderBy('id')->pluck('entry_type')->all());
        $this->assertSame('voided', CustomerPayment::query()->sole()->status);
        $this->assertBooksAgree();
    }

    public function test_cancelling_a_sale_that_spent_customer_credit_gives_the_credit_back(): void
    {
        $c = $this->credit();
        $this->advance($c, '500');
        $sale = $this->sell($c, $this->item('150'), '2', [['customer_credit', '300']]);
        $this->assertSame('200.00', app(CustomerAccountService::class)->advanceFor($c->id));

        app(VoidSale::class)->execute($sale, 'x', $this->manager);

        $this->assertSame('-500.00', $this->balance($c));
        $this->assertSame('500.00', app(CustomerAccountService::class)->advanceFor($c->id), 'The customer has their 500 again.');
        $this->assertSame('posted', CustomerPayment::query()->sole()->status, 'The advance receipt itself is not cancelled.');
        $this->assertBooksAgree();
    }

    public function test_cancelling_an_invoice_that_a_later_payment_settled_keeps_that_payment_as_credit(): void
    {
        $c = $this->credit();
        $sale = $this->sell($c, $this->item('150'), '2');      // owes 300
        $collected = app(PostCustomerPayment::class)->execute(['customer_id' => $c->id, 'payment_method_id' => $this->method()->id, 'amount' => '300'], $this->manager);
        $this->assertSame('0.00', $this->balance($c));
        $this->assertSame('paid', $sale->fresh()->payment_status);

        app(VoidSale::class)->execute($sale->fresh(), 'wrong customer', $this->manager);

        $this->assertSame('posted', $collected->fresh()->status, 'Money that was collected stays collected.');
        $this->assertSame('-300.00', $this->balance($c), 'The shop now holds 300 of the customer\'s money.');
        $this->assertSame('300.00', app(CustomerAccountService::class)->advanceFor($c->id));
        $this->assertBooksAgree();
    }

    // ---------------------------------------------------------------- rollback and audit

    public function test_a_failure_in_the_middle_of_posting_leaves_the_account_untouched(): void
    {
        $c = $this->credit();
        $p = $this->item('150');
        DocumentSequence::query()->where('doc_type', 'customer_payment')->delete();   // breaks the receipt AFTER the sale and its ledger line were written
        $audits = AuditLog::query()->count();

        try {
            $this->sell($c, $p, '2', [['cash', '100']]);
            $this->fail('The injected failure did not happen.');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, CustomerLedgerEntry::query()->count());
        $this->assertSame('0.00', $this->balance($c));
        $this->assertSame('50.000', $p->fresh()->current_stock);
        $this->assertSame($audits, AuditLog::query()->count());
    }

    public function test_the_audit_trail_names_the_customer_and_what_was_left_on_account(): void
    {
        $c = $this->credit(['name' => 'Ahmed Traders']);

        $sale = $this->sell($c, $this->item('150'), '2', [['cash', '100']]);

        $new = AuditLog::query()->where('event', 'sale_completed')->sole()->new_values;
        $this->assertSame('Ahmed Traders', $new['customer']);
        $this->assertSame('200.00', $new['on_account']);
        $this->assertSame($sale->invoice_no, $new['invoice']);
    }

    // ---------------------------------------------------------------- the integrity check

    public function test_the_integrity_check_catches_a_hand_edited_balance_and_a_missing_ledger_line(): void
    {
        $c = $this->credit();
        $sale = $this->sell($c, $this->item('150'), '2', [['cash', '100']]);
        $this->assertSame([], app(CustomerAccountService::class)->findDrift());

        \DB::table('customers')->where('id', $c->id)->update(['balance' => '5.00']);
        $this->assertStringContainsString($c->code, implode(' ', app(CustomerAccountService::class)->findDrift()));
        \DB::table('customers')->where('id', $c->id)->update(['balance' => '200.00']);

        \DB::table('sales')->where('id', $sale->id)->update(['grand_total' => '999.00']);   // the invoice no longer matches what is on the account
        $this->assertStringContainsString($sale->invoice_no, implode(' ', app(CustomerAccountService::class)->findDrift()));
    }

    public function test_the_customer_ledger_cannot_be_edited_or_deleted_and_never_takes_both_sides(): void
    {
        $c = $this->credit();
        $this->sell($c, $this->item('150'), '1');

        foreach ([fn () => \DB::table('customer_ledger_entries')->update(['debit' => '1.00']), fn () => \DB::table('customer_ledger_entries')->delete(),
            fn () => \DB::table('customer_ledger_entries')->insert(['customer_id' => $c->id, 'entry_date' => now()->toDateString(), 'entry_type' => 'balance_adjustment', 'debit' => 5, 'credit' => 5, 'description' => 'x', 'created_at' => now()])] as $i => $attempt) {
            try {
                $attempt();
                $this->fail("Attempt {$i} was accepted.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('150.00', CustomerLedgerEntry::query()->sole()->debit);
    }
}
