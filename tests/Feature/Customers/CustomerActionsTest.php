<?php

namespace Tests\Feature\Customers;

use App\Actions\Customers\AdjustCustomerBalance;
use App\Actions\Customers\AllocateCustomerAdvance;
use App\Actions\Customers\DeleteCustomer;
use App\Actions\Customers\PostCustomerPayment;
use App\Actions\Customers\SaveCustomer;
use App\Actions\Customers\VoidCustomerPayment;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\VoidSale;
use App\Exceptions\DuplicatePhoneException;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\CustomerPayment;
use App\Models\DocumentSequence;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\Customers\CustomerAccountService;
use App\Services\Sales\SaleAccountService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

class CustomerActionsTest extends TestCase
{
    use MakesStock;
    use RefreshesSeededDatabase;

    private User $admin;

    private User $manager;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->withRole('Admin')->create();
        $this->manager = User::factory()->withRole('Manager')->create();
        $this->cashier = User::factory()->withRole('Cashier')->create();
    }

    private function method(string $code = 'cash'): PaymentMethod
    {
        return PaymentMethod::query()->where('code', $code)->firstOrFail();
    }

    private function credit(array $attrs = []): Customer
    {
        return Customer::factory()->create($attrs + ['allow_credit' => true]);
    }

    /** A credit sale of $amount rupees to the customer, dated $daysAgo days back. */
    private function invoice(Customer $c, string $amount, int $daysAgo = 0): Sale
    {
        $p = $this->stocked('100', '10', ['sale_price' => $amount]);
        $sale = app(CompleteSale::class)->execute([
            'idempotency_key' => (string) Str::uuid(), 'customer_id' => $c->id, 'lines' => [['product_id' => $p->id, 'quantity' => '1']],
        ], $this->cashier);
        if ($daysAgo > 0) {
            DB::table('sales')->where('id', $sale->id)->update(['sale_date' => now()->subDays($daysAgo)]);
        }

        return $sale->fresh();
    }

    private function collect(Customer $c, string $amount, array $over = []): CustomerPayment
    {
        return app(PostCustomerPayment::class)->execute($over + ['customer_id' => $c->id, 'payment_method_id' => $this->method()->id, 'amount' => $amount], $this->cashier);
    }

    private function balance(Customer $c): string
    {
        return (string) $c->fresh()->getRawOriginal('balance');
    }

    private function assertBooksAgree(): void
    {
        $this->assertSame([], app(CustomerAccountService::class)->findDrift());
        $this->assertSame([], app(SaleAccountService::class)->findDrift());
    }

    // ---------------------------------------------------------------- the customer record

    public function test_a_customer_is_created_with_a_code_and_sensible_defaults(): void
    {
        $c = app(SaveCustomer::class)->execute(['name' => '  Ahmed Traders ', 'phone' => '0300-1112223', 'price_tier' => 'wholesale', 'allow_credit' => true, 'credit_limit' => '50000'], $this->cashier);

        $this->assertSame('CUS-000001', $c->code);
        $this->assertSame('Ahmed Traders', $c->name);
        $this->assertSame('wholesale', $c->price_tier);
        $this->assertTrue($c->allow_credit);
        $this->assertSame('50000.00', $c->credit_limit);
        $this->assertSame('0.00', (string) $c->getRawOriginal('balance'));
        $this->assertFalse($c->is_walk_in);
        $this->assertSame($this->cashier->id, $c->created_by);
        $this->assertSame(1, AuditLog::query()->where('event', 'customer_created')->count());
        $this->assertSame('CUS-000002', app(SaveCustomer::class)->execute(['name' => 'Second'], $this->cashier)->code);
    }

    public function test_the_credit_limit_means_nothing_without_credit_and_a_name_is_required(): void
    {
        $c = app(SaveCustomer::class)->execute(['name' => 'Cash customer', 'allow_credit' => false, 'credit_limit' => '9999'], $this->cashier);
        $this->assertNull($c->credit_limit);

        foreach ([['name' => '   '], ['name' => 'X', 'allow_credit' => true, 'credit_limit' => '-5']] as $bad) {
            try {
                app(SaveCustomer::class)->execute($bad, $this->cashier);
                $this->fail('Bad customer data was accepted.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_same_phone_number_is_a_warning_and_can_be_confirmed(): void
    {
        $save = app(SaveCustomer::class);
        $save->execute(['name' => 'Bilal', 'phone' => '0300-1234567'], $this->cashier);

        foreach (['0300 1234567', '03001234567', '+92 300 1234567' === '' ? '' : '0300.123.4567'] as $same) {
            try {
                $save->execute(['name' => 'Someone else', 'phone' => $same], $this->cashier);
                $this->fail("Duplicate phone '{$same}' was accepted without a warning.");
            } catch (DuplicatePhoneException $e) {
                $this->assertSame(['Bilal'], $e->names);
            }
        }
        $this->assertSame(1, Customer::query()->where('is_walk_in', false)->count());

        $other = $save->execute(['name' => 'Bilal\'s brother', 'phone' => '0300-1234567'], $this->cashier, null, true);   // confirmed
        $this->assertSame('CUS-000002', $other->code);

        // editing a customer without changing their number is not a duplicate of themselves
        $this->assertSame('Bilal Khan', $save->execute(['name' => 'Bilal Khan', 'phone' => '0300-1234567'], $this->cashier, Customer::query()->where('name', 'Bilal')->first(), true)->name);
        $this->assertSame('Brother', $save->execute(['name' => 'Brother', 'phone' => '0301-0000000'], $this->cashier, $other)->name);
    }

    public function test_short_numbers_and_empty_phones_never_count_as_duplicates(): void
    {
        $save = app(SaveCustomer::class);
        $save->execute(['name' => 'A', 'phone' => '123'], $this->cashier);
        $save->execute(['name' => 'B', 'phone' => '123'], $this->cashier);
        $save->execute(['name' => 'C'], $this->cashier);
        $save->execute(['name' => 'D'], $this->cashier);

        $this->assertSame(4, Customer::query()->where('is_walk_in', false)->count());
    }

    public function test_editing_is_audited_with_what_changed_and_the_walk_in_customer_cannot_be_edited(): void
    {
        $c = app(SaveCustomer::class)->execute(['name' => 'Old name', 'allow_credit' => false], $this->cashier);

        app(SaveCustomer::class)->execute(['name' => 'New name', 'allow_credit' => true, 'credit_limit' => '100'], $this->manager, $c);

        $log = AuditLog::query()->where('event', 'customer_updated')->sole();
        $this->assertSame('Old name', $log->old_values['name']);
        $this->assertSame('New name', $log->new_values['name']);

        $this->expectException(DomainException::class);
        app(SaveCustomer::class)->execute(['name' => 'Hacked'], $this->manager, Customer::walkIn());
    }

    public function test_only_a_customer_without_history_can_be_deleted(): void
    {
        $unused = $this->credit();
        $used = $this->credit();
        $this->invoice($used, '100');

        app(DeleteCustomer::class)->execute($unused, $this->manager);
        $this->assertNull(Customer::query()->find($unused->id));
        $this->assertSame(1, AuditLog::query()->where('event', 'customer_deleted')->count());

        foreach ([$used, Customer::walkIn()] as $protected) {
            try {
                app(DeleteCustomer::class)->execute($protected, $this->manager);
                $this->fail('A customer that must be kept was deleted.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNotNull(Customer::query()->find($used->id));
    }

    public function test_an_opening_balance_is_posted_once_and_corrections_need_a_reason(): void
    {
        $c = $this->credit();
        $adjust = app(AdjustCustomerBalance::class);

        $c = $adjust->execute($c, 'opening_balance', 'customer_owes', '12500', 'From the paper register', $this->admin);
        $this->assertSame('12500.00', (string) $c->getRawOriginal('balance'));
        $this->assertSame('12500.00', (string) $c->getRawOriginal('opening_balance'));
        $entry = CustomerLedgerEntry::query()->sole();
        $this->assertSame(['opening_balance', '12500.00', '0.00'], [$entry->entry_type, $entry->debit, $entry->credit]);

        try {
            $adjust->execute($c, 'opening_balance', 'customer_owes', '1', 'again', $this->admin);
            $this->fail('A second opening balance was accepted.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('already has an opening balance', $e->getMessage());
        }

        foreach ([['customer_owes', '10', ''], ['sideways', '10', 'why'], ['customer_owes', '0', 'why']] as [$dir, $amt, $why]) {
            try {
                $adjust->execute($c, 'balance_adjustment', $dir, $amt, $why, $this->admin);
                $this->fail('An invalid correction was accepted.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $c = $adjust->execute($c, 'balance_adjustment', 'shop_owes', '500', 'Discount agreed by phone', $this->admin);
        $this->assertSame('12000.00', (string) $c->getRawOriginal('balance'));
        $this->assertBooksAgree();
    }

    public function test_an_opening_balance_can_be_money_the_shop_holds_for_the_customer(): void
    {
        $c = app(AdjustCustomerBalance::class)->execute($this->credit(), 'opening_balance', 'shop_owes', '2000', 'Advance given earlier', $this->admin);

        $this->assertSame('-2000.00', (string) $c->getRawOriginal('balance'));
        $this->assertSame('-2000.00', (string) $c->getRawOriginal('opening_balance'));
        $this->assertSame('0.00', app(CustomerAccountService::class)->advanceFor($c->id), 'An opening credit is a balance, not a receipt to spend at the till.');
    }

    public function test_the_walk_in_customer_has_no_account_to_adjust_or_to_post_to(): void
    {
        $this->expectException(DomainException::class);
        app(AdjustCustomerBalance::class)->execute(Customer::walkIn(), 'balance_adjustment', 'customer_owes', '10', 'x', $this->admin);
    }

    public function test_nothing_can_be_posted_to_the_walk_in_account_even_directly(): void
    {
        $this->expectException(\LogicException::class);
        DB::transaction(fn () => app(CustomerAccountService::class)->postEntry(Customer::walkIn(), 'sale', '10', '0', null, 'x', $this->admin));
    }

    // ---------------------------------------------------------------- collecting payments

    public function test_a_payment_settles_the_oldest_invoices_first(): void
    {
        $c = $this->credit();
        $old = $this->invoice($c, '1000', 10);
        $new = $this->invoice($c, '500', 2);
        $this->assertSame('1500.00', $this->balance($c));

        $payment = $this->collect($c, '1200');

        $this->assertSame('RCP-000001', $payment->receipt_no);
        $this->assertSame('paid', $old->fresh()->payment_status);
        $this->assertSame('partial', $new->fresh()->payment_status);
        $this->assertSame('300.00', $new->fresh()->due_amount);
        $this->assertSame('300.00', $this->balance($c));
        $this->assertSame('0.00', app(CustomerAccountService::class)->advanceFor($c->id));
        $entry = CustomerLedgerEntry::query()->where('entry_type', 'payment')->sole();
        $this->assertSame('1200.00', $entry->credit);
        $this->assertSame($payment->id, $entry->source_id);
        $this->assertNull($payment->sale_id, 'A collected payment belongs to no sale in particular.');
        $this->assertBooksAgree();
    }

    public function test_oldest_means_by_invoice_date_not_by_when_it_was_typed(): void
    {
        $c = $this->credit();
        $recentFirst = $this->invoice($c, '300', 1);
        $olderSecond = $this->invoice($c, '300', 30);

        $this->collect($c, '300');

        $this->assertSame('paid', $olderSecond->fresh()->payment_status);
        $this->assertSame('unpaid', $recentFirst->fresh()->payment_status);
    }

    public function test_money_beyond_what_is_owed_stays_as_credit(): void
    {
        $c = $this->credit();
        $this->invoice($c, '100');

        $this->collect($c, '150');

        $this->assertSame('-50.00', $this->balance($c));
        $this->assertSame('50.00', app(CustomerAccountService::class)->advanceFor($c->id));
        $this->assertBooksAgree();
    }

    public function test_a_manual_payment_settles_exactly_the_chosen_invoices(): void
    {
        $c = $this->credit();
        $a = $this->invoice($c, '1000', 10);
        $b = $this->invoice($c, '500', 2);

        $this->collect($c, '300', ['allocation' => 'manual', 'allocations' => [$b->id => '300']]);

        $this->assertSame('1000.00', $a->fresh()->due_amount, 'The older invoice is untouched.');
        $this->assertSame('200.00', $b->fresh()->due_amount);
        $this->assertBooksAgree();
    }

    public function test_payment_rules_are_enforced(): void
    {
        $c = $this->credit();
        $other = $this->credit();
        $inv = $this->invoice($c, '500');
        $theirs = $this->invoice($other, '500');
        $cancelled = $this->invoice($c, '100');
        app(VoidSale::class)->execute($cancelled, 'x', $this->manager);

        $cases = [
            'over-allocation' => ['300', ['allocation' => 'manual', 'allocations' => [$inv->id => '600']]],
            'more than the payment' => ['100', ['allocation' => 'manual', 'allocations' => [$inv->id => '80'] + [$theirs->id => '80']]],
            'another customer' => ['100', ['allocation' => 'manual', 'allocations' => [$theirs->id => '100']]],
            'cancelled invoice' => ['100', ['allocation' => 'manual', 'allocations' => [$cancelled->id => '100']]],
            'no invoice chosen' => ['100', ['allocation' => 'manual', 'allocations' => []]],
            'reference missing' => ['100', ['payment_method_id' => $this->method('bank_transfer')->id]],
            'customer credit method' => ['100', ['payment_method_id' => $this->method('customer_credit')->id]],
            'zero' => ['0', []],
            'negative' => ['-5', []],
            'unknown way' => ['100', ['allocation' => 'whatever']],
        ];
        foreach ($cases as $label => [$amount, $over]) {
            try {
                $this->collect($c, $amount, $over);
                $this->fail("Accepted: {$label}");
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, CustomerPayment::query()->where('sale_id', null)->count());
        $this->assertSame('500.00', $inv->fresh()->due_amount);

        $ok = $this->collect($c, '100', ['payment_method_id' => $this->method('easypaisa')->id, 'reference_no' => 'EP-77']);
        $this->assertSame('EP-77', $ok->reference_no);
    }

    public function test_the_walk_in_customer_has_no_account_to_collect_on(): void
    {
        $this->expectException(DomainException::class);
        $this->collect(Customer::walkIn(), '100');
    }

    public function test_a_failure_while_collecting_changes_nothing(): void
    {
        $c = $this->credit();
        $inv = $this->invoice($c, '500');
        DocumentSequence::query()->where('doc_type', 'customer_payment')->delete();

        try {
            $this->collect($c, '200');
            $this->fail('The injected failure did not happen.');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('500.00', $inv->fresh()->due_amount);
        $this->assertSame('500.00', $this->balance($c));
        $this->assertSame(1, CustomerLedgerEntry::query()->count());
    }

    // ---------------------------------------------------------------- cancelling a payment

    public function test_cancelling_a_payment_reopens_the_invoices_and_restores_the_balance(): void
    {
        $c = $this->credit();
        $a = $this->invoice($c, '1000', 10);
        $b = $this->invoice($c, '500', 2);
        $payment = $this->collect($c, '1200');

        $void = app(VoidCustomerPayment::class)->execute($payment, 'Wrong customer', $this->manager);

        $this->assertSame('voided', $void->status);
        $this->assertSame('1000.00', $a->fresh()->due_amount);
        $this->assertSame('unpaid', $a->fresh()->payment_status);
        $this->assertSame('500.00', $b->fresh()->due_amount);
        $this->assertSame('1500.00', $this->balance($c));
        $this->assertSame(['sale', 'sale', 'payment', 'payment_void'], CustomerLedgerEntry::query()->orderBy('id')->pluck('entry_type')->all());
        $this->assertSame('0.00', app(CustomerAccountService::class)->advanceFor($c->id));
        $this->assertBooksAgree();

        try {
            app(VoidCustomerPayment::class)->execute($payment, 'again', $this->manager);
            $this->fail('A payment was cancelled twice.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('already been cancelled', $e->getMessage());
        }
    }

    public function test_cancelling_a_payment_needs_a_reason(): void
    {
        $c = $this->credit();
        $payment = $this->collect($c, '50');

        $this->expectException(DomainException::class);
        app(VoidCustomerPayment::class)->execute($payment, '  ', $this->manager);
    }

    public function test_a_till_payment_of_a_named_customer_can_be_cancelled_but_a_walk_in_one_only_with_the_sale(): void
    {
        $c = $this->credit();
        $p = $this->stocked('10', '1', ['sale_price' => '100']);
        $sale = app(CompleteSale::class)->execute([
            'idempotency_key' => (string) Str::uuid(), 'customer_id' => $c->id, 'lines' => [['product_id' => $p->id, 'quantity' => '1']],
            'payments' => [['payment_method_id' => $this->method()->id, 'amount' => '100']],
        ], $this->cashier);
        $receipt = CustomerPayment::query()->where('sale_id', $sale->id)->sole();

        app(VoidCustomerPayment::class)->execute($receipt, 'Recorded as cash by mistake', $this->manager);
        $this->assertSame('unpaid', $sale->fresh()->payment_status);
        $this->assertSame('100.00', $this->balance($c));

        $walkInSale = app(CompleteSale::class)->execute([
            'idempotency_key' => (string) Str::uuid(), 'lines' => [['product_id' => $this->stocked('10', '1', ['sale_price' => '50'])->id, 'quantity' => '1']],
            'payments' => [['payment_method_id' => $this->method()->id, 'amount' => '50']],
        ], $this->cashier);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cancel the sale instead');
        app(VoidCustomerPayment::class)->execute(CustomerPayment::query()->where('sale_id', $walkInSale->id)->sole(), 'x', $this->manager);
    }

    // ---------------------------------------------------------------- applying an advance

    public function test_an_advance_can_be_applied_to_the_oldest_invoices_without_moving_money(): void
    {
        $c = $this->credit();
        $this->collect($c, '300', ['allocation' => 'advance']);
        $old = $this->invoice($c, '200', 5);
        $new = $this->invoice($c, '400', 1);
        $this->assertSame('300.00', $this->balance($c), '-300 + 200 + 400');
        $entries = CustomerLedgerEntry::query()->count();

        $applied = app(AllocateCustomerAdvance::class)->execute($c, null, $this->manager);

        $this->assertSame([['invoice' => $old->invoice_no, 'amount' => '200.00'], ['invoice' => $new->invoice_no, 'amount' => '100.00']], $applied);
        $this->assertSame('paid', $old->fresh()->payment_status);
        $this->assertSame('300.00', $new->fresh()->due_amount);
        $this->assertSame('300.00', $this->balance($c), 'No money moved.');
        $this->assertSame($entries, CustomerLedgerEntry::query()->count(), 'So there is no ledger line.');
        $this->assertSame('0.00', app(CustomerAccountService::class)->advanceFor($c->id));
        $this->assertBooksAgree();
    }

    public function test_an_advance_can_be_applied_to_chosen_invoices_but_never_beyond_what_there_is(): void
    {
        $c = $this->credit();
        $this->collect($c, '300', ['allocation' => 'advance']);
        $a = $this->invoice($c, '200', 5);
        $b = $this->invoice($c, '400', 1);

        try {
            app(AllocateCustomerAdvance::class)->execute($c, [$b->id => '350'], $this->manager);
            $this->fail('More advance was applied than exists.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Only 300.00', $e->getMessage());
        }
        try {
            app(AllocateCustomerAdvance::class)->execute($c, [$a->id => '250'], $this->manager);
            $this->fail('An invoice was paid more than it owes.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        app(AllocateCustomerAdvance::class)->execute($c, [$b->id => '300'], $this->manager);
        $this->assertSame('100.00', $b->fresh()->due_amount);
        $this->assertSame('200.00', $a->fresh()->due_amount);
        $this->assertBooksAgree();
    }

    public function test_there_is_nothing_to_apply_when_the_customer_has_no_advance_or_no_open_invoice(): void
    {
        $c = $this->credit();
        $this->invoice($c, '100');

        try {
            app(AllocateCustomerAdvance::class)->execute($c, null, $this->manager);
            $this->fail('An advance was applied that does not exist.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('no advance', $e->getMessage());
        }

        $d = $this->credit();
        $this->collect($d, '100', ['allocation' => 'advance']);
        $this->expectException(DomainException::class);
        app(AllocateCustomerAdvance::class)->execute($d, null, $this->manager);   // no invoice open
    }

    public function test_advance_that_belonged_to_a_cancelled_invoice_is_available_again(): void
    {
        $c = $this->credit();
        $sale = $this->invoice($c, '300');
        $this->collect($c, '300');                                    // settles it
        $this->assertSame('0.00', app(CustomerAccountService::class)->advanceFor($c->id));

        app(VoidSale::class)->execute($sale, 'x', $this->manager);

        $this->assertSame('300.00', app(CustomerAccountService::class)->advanceFor($c->id));
        $this->assertSame(1, SalePayment::query()->count(), 'The old allocation is kept as history.');
    }

    public function test_the_statement_has_a_running_balance_and_a_brought_forward_figure(): void
    {
        $c = $this->credit();
        $adjust = app(AdjustCustomerBalance::class);
        $adjust->execute($c, 'opening_balance', 'customer_owes', '1000', 'old', $this->admin, '2026-01-10');
        $adjust->execute($c, 'balance_adjustment', 'shop_owes', '200', 'discount', $this->admin, '2026-02-05');
        $adjust->execute($c, 'balance_adjustment', 'customer_owes', '50', 'fee', $this->admin, '2026-03-01');
        $accounts = app(CustomerAccountService::class);

        $all = $accounts->statement($c->id);
        $this->assertSame('0.00', $all['opening']);
        $this->assertSame(['1000.00', '800.00', '850.00'], array_column($all['rows'], 'balance'));
        $this->assertSame(['1050.00', '200.00', '850.00'], [$all['debit'], $all['credit'], $all['closing']]);

        $february = $accounts->statement($c->id, '2026-02-01', '2026-02-28');
        $this->assertSame('1000.00', $february['opening'], 'everything before the period is brought forward');
        $this->assertSame(['800.00'], array_column($february['rows'], 'balance'));
        $this->assertSame(['0.00', '200.00', '800.00'], [$february['debit'], $february['credit'], $february['closing']]);

        $later = $accounts->statement($c->id, '2026-04-01');
        $this->assertSame('850.00', $later['opening']);
        $this->assertSame([], $later['rows']);
        $this->assertSame('850.00', $later['closing']);
        $this->assertSame($accounts->balanceFromLedger($c->id), $all['closing']);
    }
}
