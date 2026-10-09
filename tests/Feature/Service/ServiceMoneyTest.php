<?php

namespace Tests\Feature\Service;

use App\Actions\Customers\PostCustomerPayment;
use App\Actions\Customers\VoidCustomerPayment;
use App\Actions\Service\AddServiceCharge;
use App\Actions\Service\AddServicePart;
use App\Actions\Service\CancelServiceJob;
use App\Actions\Service\ChangeServiceJobStatus;
use App\Actions\Service\DeliverServiceJob;
use App\Actions\Service\ReceiveServiceJob;
use App\Actions\Service\TakeServiceAdvance;
use App\Actions\Service\UpdateServicePricing;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\ServiceJob;
use App\Models\ServiceType;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Customers\CustomerAccountService;
use App\Services\Customers\ReceivablesService;
use App\Services\Sales\SaleAccountService;
use App\Services\Service\ServiceJobService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/** The money side of a service job: advances, delivery and invoice, cancellation, collecting what was left on account. */
class ServiceMoneyTest extends TestCase
{
    use MakesStock;
    use RefreshesSeededDatabase;

    private User $admin;

    private User $manager;

    private User $cashier;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->withRole('Admin')->create();
        $this->manager = User::factory()->withRole('Manager')->create();
        $this->cashier = User::factory()->withRole('Cashier')->create();
        $this->tech = User::factory()->withRole('Technician')->create();
    }

    private function method(string $code = 'cash'): PaymentMethod
    {
        return PaymentMethod::query()->where('code', $code)->firstOrFail();
    }

    private function credit(array $attrs = []): Customer
    {
        return Customer::factory()->create($attrs + ['allow_credit' => true]);
    }

    /** A completed job worth $amount for the customer (walk-in when null), waiting to be delivered. */
    private function completed(string $amount = '1000', ?Customer $customer = null): ServiceJob
    {
        $job = app(ReceiveServiceJob::class)->execute([
            'customer_id' => $customer?->id, 'contact_name' => 'Mr Ali', 'contact_phone' => '0300-0000000',
            'service_type_id' => ServiceType::query()->where('name', 'Printer repair')->value('id'), 'device_type' => 'printer',
            'reported_problem' => 'Does not print', 'technician_id' => $this->tech->id,
        ], $this->cashier);
        foreach (['diagnosing', 'in_progress'] as $to) {
            $job = app(ChangeServiceJobStatus::class)->execute($job, $to, $this->manager);
        }
        app(AddServiceCharge::class)->execute($job, ['description' => 'Repair', 'unit_price' => $amount], $this->manager);

        return app(ChangeServiceJobStatus::class)->execute($job->fresh(), 'completed', $this->manager);
    }

    private function advance(ServiceJob $job, string $amount, ?User $by = null, string $method = 'cash'): CustomerPayment
    {
        return app(TakeServiceAdvance::class)->execute($job, ['payment_method_id' => $this->method($method)->id, 'amount' => $amount, 'reference_no' => $method === 'cash' ? null : 'REF-1'], $by ?? $this->cashier);
    }

    /** @param  list<array{0: string, 1: string}>  $lines  [method code, amount] */
    private function deliver(ServiceJob $job, array $lines, ?User $by = null): ServiceJob
    {
        $payments = array_map(fn ($l) => ['payment_method_id' => $this->method($l[0])->id, 'amount' => $l[1], 'reference_no' => $l[0] === 'cash' ? null : 'REF-2'], $lines);

        return app(DeliverServiceJob::class)->execute($job, $payments, $by ?? $this->cashier);
    }

    private function balance(Customer $c): string
    {
        return (string) $c->fresh()->getRawOriginal('balance');
    }

    private function advanceOf(Customer $c): string
    {
        return app(CustomerAccountService::class)->advanceFor($c->id);
    }

    private function assertBooksAgree(): void
    {
        $this->assertSame([], app(ServiceJobService::class)->findDrift());
        $this->assertSame([], app(CustomerAccountService::class)->findDrift());
        $this->assertSame([], app(SaleAccountService::class)->findDrift());
        $this->assertSame([], $this->inventory()->findDrift());
    }

    // ---------------------------------------------------------------- advances

    public function test_an_advance_pays_the_job_credits_the_account_and_is_not_spendable_credit(): void
    {
        $c = $this->credit();
        $job = $this->completed('1000', $c);

        $payment = $this->advance($job, '300');

        $job->refresh();
        $this->assertSame('300.00', (string) $job->paid_amount);
        $this->assertSame('700.00', (string) $job->due_amount);
        $this->assertSame('partial', $job->payment_status);
        $this->assertStringStartsWith('RCP-', $payment->receipt_no);
        $this->assertSame('receipt', $payment->type);
        $this->assertSame('-300.00', $this->balance($c), 'the account holds the customer\'s money until the job is invoiced');
        $this->assertSame('0.00', $this->advanceOf($c), 'money that belongs to a job is not free credit');
        $this->assertSame(1, CustomerLedgerEntry::query()->where('customer_id', $c->id)->where('entry_type', 'payment')->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'service_advance_taken')->count());
        $this->assertBooksAgree();
    }

    public function test_the_walk_in_customer_can_leave_an_advance_without_an_account(): void
    {
        $job = $this->completed('1000');
        $payment = $this->advance($job, '200');

        $this->assertTrue($payment->customer->is_walk_in);
        $this->assertSame('200.00', (string) $job->fresh()->paid_amount);
        $this->assertSame(0, CustomerLedgerEntry::query()->count(), 'the walk-in customer has no ledger');
        $this->assertSame('0.00', (string) Customer::walkIn()->fresh()->getRawOriginal('balance'));
        $this->assertBooksAgree();
    }

    public function test_an_advance_cannot_be_more_than_the_job_is_worth_or_on_a_locked_job(): void
    {
        $job = $this->completed('1000', $this->credit());
        $this->advance($job, '600');

        try {
            $this->advance($job, '500');
            $this->fail('More than the job costs was taken.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('at most 400', $e->getMessage());
        }
        foreach ([['0', 'cash'], ['-5', 'cash']] as [$amount, $m]) {
            try {
                $this->advance($job, $amount);
                $this->fail('A bad amount was taken.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('600.00', (string) $job->fresh()->paid_amount);

        $this->expectException(AuthorizationException::class);   // a technician handles no money
        $this->advance($job, '100', $this->tech);
    }

    public function test_an_advance_with_a_wallet_needs_a_reference_and_cannot_use_customer_credit(): void
    {
        $job = $this->completed('1000', $this->credit());

        try {
            app(TakeServiceAdvance::class)->execute($job, ['payment_method_id' => $this->method('easypaisa')->id, 'amount' => '100'], $this->cashier);
            $this->fail('A wallet payment without a reference was accepted.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(DomainException::class);
        app(TakeServiceAdvance::class)->execute($job, ['payment_method_id' => $this->method('customer_credit')->id, 'amount' => '100'], $this->cashier);
    }

    // ---------------------------------------------------------------- delivery

    public function test_delivery_invoices_the_job_posts_the_account_and_locks_it(): void
    {
        $c = $this->credit(['credit_limit' => '5000']);
        $job = $this->completed('1000', $c);
        $this->advance($job, '300');

        $job = $this->deliver($job, [['cash', '200']]);

        $this->assertSame(ServiceJob::DELIVERED, $job->status);
        $this->assertSame('SRV-000001', $job->invoice_no);
        $this->assertNotNull($job->delivered_at);
        $this->assertSame($this->cashier->id, $job->delivered_by);
        $this->assertSame('500.00', (string) $job->paid_amount);
        $this->assertSame('500.00', (string) $job->due_amount);
        $this->assertSame('partial', $job->payment_status);

        // ledger: advance Cr 300, invoice Dr 1000, payment Cr 200 -> the customer owes 500
        $this->assertSame('500.00', $this->balance($c));
        $entries = CustomerLedgerEntry::query()->where('customer_id', $c->id)->orderBy('id')->get(['entry_type', 'debit', 'credit'])->map(fn ($e) => [$e->entry_type, (string) $e->debit, (string) $e->credit])->all();
        $this->assertSame([['payment', '0.00', '300.00'], ['service_invoice', '1000.00', '0.00'], ['payment', '0.00', '200.00']], $entries);
        $this->assertSame('service_job', CustomerLedgerEntry::query()->where('entry_type', 'service_invoice')->value('source_type'));
        $this->assertSame(2, CustomerPayment::query()->where('customer_id', $c->id)->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'service_job_delivered')->count());
        $this->assertBooksAgree();

        // locked
        foreach ([
            fn () => app(ChangeServiceJobStatus::class)->execute($job, 'in_progress', $this->manager, ['note' => 'x']),
            fn () => app(AddServiceCharge::class)->execute($job, ['description' => 'More', 'unit_price' => '10'], $this->manager),
            fn () => $this->advance($job, '10'),
            fn () => $this->deliver($job, [['cash', '500']]),
            fn () => app(CancelServiceJob::class)->execute($job, 'changed my mind', $this->manager),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A delivered job was changed.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('500.00', $this->balance($c));
    }

    public function test_the_next_invoice_gets_the_next_number_and_a_free_job_posts_nothing(): void
    {
        $c = $this->credit();
        $this->assertSame('SRV-000001', $this->deliver($this->completed('100', $c), [['cash', '100']])->invoice_no);

        $free = $this->completed('0', $c);
        $free = $this->deliver($free, []);
        $this->assertSame('SRV-000002', $free->invoice_no);
        $this->assertSame('0.00', (string) $free->grand_total);
        $this->assertSame(0, CustomerLedgerEntry::query()->where('entry_type', 'service_invoice')->where('source_id', $free->id)->count());
        $this->assertBooksAgree();
    }

    public function test_the_walk_in_customer_pays_in_full_and_a_refused_delivery_changes_nothing(): void
    {
        $job = $this->completed('1000');

        try {
            $this->deliver($job, [['cash', '400']]);
            $this->fail('The walk-in customer left without paying in full.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('walk-in customer must pay in full', $e->getMessage());
        }
        $fresh = $job->fresh();
        $this->assertSame(ServiceJob::COMPLETED, $fresh->status);
        $this->assertNull($fresh->invoice_no);
        $this->assertSame(0, CustomerPayment::query()->count());
        $this->assertSame(1, DB::table('document_sequences')->where('doc_type', 'service_invoice')->value('next_number'), 'the invoice number must not be used up');

        $job = $this->deliver($job, [['cash', '1000']]);
        $this->assertSame('paid', $job->payment_status);
        $this->assertSame('0.00', (string) $job->due_amount);
        $this->assertSame(0, CustomerLedgerEntry::query()->count());
        $this->assertBooksAgree();
    }

    public function test_cash_in_excess_is_change_and_only_the_job_amount_is_recorded(): void
    {
        $c = $this->credit();
        $action = app(DeliverServiceJob::class);
        $job = $this->completed('1000', $c);

        $action->execute($job, [['payment_method_id' => $this->method()->id, 'amount' => '1500']], $this->cashier);

        $this->assertSame('500.00', $action->change);
        $this->assertSame('1000.00', (string) CustomerPayment::query()->where('customer_id', $c->id)->value('amount'));
        $this->assertSame('0.00', $this->balance($c));

        // a card cannot be overpaid
        $other = $this->completed('1000', $c);
        try {
            $action->execute($other, [['payment_method_id' => $this->method('card')->id, 'amount' => '1200']], $this->cashier);
            $this->fail('A card was overpaid.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Only cash', $e->getMessage());
        }
        $this->assertSame('0.00', $action->change);
        $this->assertBooksAgree();
    }

    public function test_a_split_payment_makes_one_receipt_per_method(): void
    {
        $c = $this->credit();
        $job = $this->deliver($this->completed('1000', $c), [['cash', '400'], ['easypaisa', '600']]);

        $this->assertSame('paid', $job->payment_status);
        $this->assertSame(2, CustomerPayment::query()->where('customer_id', $c->id)->count());
        $this->assertSame('0.00', $this->balance($c));
        $this->assertBooksAgree();
    }

    public function test_a_balance_stays_on_account_only_for_customers_who_may_have_one(): void
    {
        $cashOnly = $this->credit(['allow_credit' => false]);
        try {
            $this->deliver($this->completed('1000', $cashOnly), [['cash', '300']]);
            $this->fail('A cash-only customer was given credit.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('not allowed credit', $e->getMessage());
        }

        $limited = $this->credit(['credit_limit' => '500']);
        $job = $this->completed('1000', $limited);
        try {
            $this->deliver($job, [['cash', '300']]);   // would owe 700 > 500: a cashier needs a manager
            $this->fail('The credit limit was ignored.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('credit limit', $e->getMessage());
        }
        $this->assertSame('0.00', $this->balance($limited));

        $job = $this->deliver($job, [['cash', '300']], $this->manager);   // a manager may exceed it
        $this->assertSame('700.00', $this->balance($limited));
        $this->assertSame('700.00', (string) $job->due_amount);
        $this->assertBooksAgree();
    }

    public function test_someone_who_may_not_sell_on_credit_cannot_leave_a_balance(): void
    {
        $c = $this->credit();
        $job = $this->completed('1000', $c);
        $noCredit = User::factory()->create();
        $noCredit->givePermissionTo(['service_jobs.view_all', 'service_jobs.deliver']);

        $this->expectException(DomainException::class);
        $this->deliver($job, [['cash', '300']], $noCredit);
    }

    public function test_delivery_can_be_paid_with_the_customers_credit(): void
    {
        $c = $this->credit();
        app(PostCustomerPayment::class)->execute(['customer_id' => $c->id, 'payment_method_id' => $this->method()->id, 'amount' => '1500', 'allocation' => 'advance'], $this->cashier);
        $this->assertSame('1500.00', $this->advanceOf($c));

        $job = $this->deliver($this->completed('1000', $c), [['customer_credit', '1000']]);

        $this->assertSame('paid', $job->payment_status);
        $this->assertSame('500.00', $this->advanceOf($c), 'the rest of the credit is still theirs');
        $this->assertSame('-500.00', $this->balance($c));

        $this->expectException(DomainException::class);   // more than they have
        $this->deliver($this->completed('900', $c), [['customer_credit', '900']]);
    }

    public function test_only_a_completed_job_can_be_delivered_and_only_by_someone_who_may(): void
    {
        $c = $this->credit();
        $open = $this->completed('1000', $c);
        DB::table('service_jobs')->where('id', $open->id)->update(['status' => 'in_progress']);

        try {
            $this->deliver($open->fresh(), [['cash', '1000']]);
            $this->fail('A job in progress was delivered.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('completed', $e->getMessage());
        }

        DB::table('service_jobs')->where('id', $open->id)->update(['status' => 'completed']);
        $this->expectException(AuthorizationException::class);
        $this->deliver($open->fresh(), [['cash', '1000']], $this->tech);
    }

    public function test_advances_that_turn_out_to_be_too_much_are_returned_at_delivery(): void
    {
        $c = $this->credit();
        $job = $this->completed('1000', $c);
        $this->advance($job, '900');
        app(UpdateServicePricing::class)->execute($job, ['discount_amount' => '500'], $this->manager);   // the job now costs 500, 900 was paid

        $job = $this->deliver($job->fresh(), []);

        $this->assertSame('500.00', (string) $job->paid_amount);
        $this->assertSame('0.00', (string) $job->due_amount);
        $this->assertSame('400.00', $this->advanceOf($c), 'the extra stays as the customer\'s credit');
        $this->assertSame('-400.00', $this->balance($c));
        $this->assertBooksAgree();
    }

    public function test_extra_advance_of_a_walk_in_customer_is_handed_back(): void
    {
        $job = $this->completed('1000');
        $this->advance($job, '900');
        app(UpdateServicePricing::class)->execute($job, ['discount_amount' => '500'], $this->manager);

        $job = $this->deliver($job->fresh(), []);

        $this->assertSame('500.00', (string) $job->paid_amount);
        $refund = CustomerPayment::query()->where('type', 'refund')->firstOrFail();
        $this->assertSame('400.00', (string) $refund->amount);
        $this->assertNotNull($refund->refund_of_id);
        $this->assertBooksAgree();
    }

    // ---------------------------------------------------------------- cancelling

    public function test_cancelling_returns_parts_and_keeps_the_advance_as_credit(): void
    {
        $toner = $this->stocked('10', '600', ['sale_price' => '1500']);
        $c = $this->credit();
        $job = $this->completed('500', $c);
        DB::table('service_jobs')->where('id', $job->id)->update(['status' => 'in_progress']);
        app(AddServicePart::class)->execute($job->fresh(), ['product_id' => $toner->id, 'quantity' => '2'], $this->tech);
        $this->advance($job->fresh(), '400');
        $this->assertSame('8.000', $toner->fresh()->current_stock);

        $job = app(CancelServiceJob::class)->execute($job->fresh(), 'The customer declined the estimate', $this->manager);

        $this->assertSame(ServiceJob::CANCELLED, $job->status);
        $this->assertSame('The customer declined the estimate', $job->cancel_reason);
        $this->assertSame('10.000', $toner->fresh()->current_stock, 'the parts went back to stock');
        $this->assertSame(1, StockMovement::query()->where('movement_type', 'service_return')->count());
        $this->assertSame('0.00', (string) $job->paid_amount);
        $this->assertSame('0.00', (string) $job->due_amount);
        $this->assertSame('400.00', $this->advanceOf($c), 'the 400 is now the customer\'s credit');
        $this->assertSame('-400.00', $this->balance($c));
        $this->assertSame(0, CustomerPayment::query()->where('type', 'refund')->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'service_job_cancelled')->count());
        $this->assertBooksAgree();

        $this->expectException(DomainException::class);   // locked now
        app(AddServiceCharge::class)->execute($job, ['description' => 'x', 'unit_price' => '1'], $this->manager);
    }

    public function test_cancelling_can_hand_the_advance_back(): void
    {
        $c = $this->credit();
        $job = $this->completed('500', $c);
        DB::table('service_jobs')->where('id', $job->id)->update(['status' => 'in_progress']);
        $this->advance($job->fresh(), '400');

        $job = app(CancelServiceJob::class)->execute($job->fresh(), 'Not worth repairing', $this->manager, [], 'refund');

        $refund = CustomerPayment::query()->where('type', 'refund')->firstOrFail();
        $this->assertSame('400.00', (string) $refund->amount);
        $this->assertSame('0.00', $this->advanceOf($c));
        $this->assertSame('0.00', $this->balance($c), 'credit 400 then refund 400');
        $this->assertSame(1, CustomerLedgerEntry::query()->where('entry_type', 'refund')->count());
        $this->assertBooksAgree();
    }

    public function test_cancelling_a_walk_in_job_always_hands_the_advance_back(): void
    {
        $job = $this->completed('500');
        DB::table('service_jobs')->where('id', $job->id)->update(['status' => 'in_progress']);
        $this->advance($job->fresh(), '300');

        app(CancelServiceJob::class)->execute($job->fresh(), 'Walked away', $this->manager, [], 'credit');   // "credit" is not possible without an account

        $this->assertSame('300.00', (string) CustomerPayment::query()->where('type', 'refund')->value('amount'));
        $this->assertSame(0, CustomerLedgerEntry::query()->count());
        $this->assertBooksAgree();
    }

    public function test_a_part_can_be_written_off_when_the_job_is_cancelled(): void
    {
        $toner = $this->stocked('10', '600', ['sale_price' => '1500']);
        $job = $this->completed('500', $this->credit());
        DB::table('service_jobs')->where('id', $job->id)->update(['status' => 'in_progress']);
        $line = app(AddServicePart::class)->execute($job->fresh(), ['product_id' => $toner->id, 'quantity' => '1'], $this->tech);

        app(CancelServiceJob::class)->execute($job->fresh(), 'Customer left', $this->manager, [$line->id => 'written_off']);

        $this->assertSame('9.000', $toner->fresh()->current_stock);
        $this->assertSame('written_off', $line->fresh()->removal_disposition);
        $this->assertBooksAgree();
    }

    public function test_cancel_rules(): void
    {
        $c = $this->credit();
        $done = $this->completed('500', $c);

        try {
            app(CancelServiceJob::class)->execute($done, 'x', $this->manager);
            $this->fail('A completed job was cancelled without reopening it.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Reopen', $e->getMessage());
        }
        try {
            app(CancelServiceJob::class)->execute($done, '  ', $this->manager);
            $this->fail('Cancelled without a reason.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
        try {
            app(CancelServiceJob::class)->execute($done, 'reason', $this->cashier);
            $this->fail('A cashier cancelled a job.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(ServiceJob::COMPLETED, $done->fresh()->status);
    }

    // ---------------------------------------------------------------- what was left on account

    public function test_a_delivered_job_on_account_is_collected_like_an_invoice(): void
    {
        $c = $this->credit();
        $job = $this->deliver($this->completed('1000', $c), [['cash', '300']]);
        $this->assertSame('700.00', $this->balance($c));

        $rows = app(CustomerAccountService::class)->openRows($c->id);
        $this->assertCount(1, $rows);
        $this->assertSame('job', $rows[0]['kind']);
        $this->assertSame('700.00', $rows[0]['due']);
        $this->assertStringContainsString($job->invoice_no, $rows[0]['no']);

        $payment = app(PostCustomerPayment::class)->execute(['customer_id' => $c->id, 'payment_method_id' => $this->method()->id, 'amount' => '500', 'allocation' => 'auto'], $this->cashier);
        $job->refresh();
        $this->assertSame('800.00', (string) $job->paid_amount);
        $this->assertSame('200.00', (string) $job->due_amount);
        $this->assertSame('200.00', $this->balance($c));
        $this->assertBooksAgree();

        // cancelling that payment opens the job again
        app(VoidCustomerPayment::class)->execute($payment, 'Entered twice', $this->manager);
        $this->assertSame('700.00', (string) $job->fresh()->due_amount);
        $this->assertSame('700.00', $this->balance($c));
        $this->assertBooksAgree();
    }

    public function test_a_payment_can_be_aimed_at_one_job_and_oldest_first_mixes_invoices_and_jobs(): void
    {
        $c = $this->credit();
        $job = $this->deliver($this->completed('1000', $c), []);
        $other = $this->deliver($this->completed('400', $c), []);

        app(PostCustomerPayment::class)->execute(['customer_id' => $c->id, 'payment_method_id' => $this->method()->id, 'amount' => '400', 'allocation' => 'manual', 'allocations' => ['j'.$other->id => '400']], $this->cashier);
        $this->assertSame('paid', $other->fresh()->payment_status);
        $this->assertSame('1000.00', (string) $job->fresh()->due_amount);

        app(PostCustomerPayment::class)->execute(['customer_id' => $c->id, 'payment_method_id' => $this->method()->id, 'amount' => '1200', 'allocation' => 'auto'], $this->cashier);
        $this->assertSame('paid', $job->fresh()->payment_status);
        $this->assertSame('-200.00', $this->balance($c));
        $this->assertSame('200.00', $this->advanceOf($c));
        $this->assertBooksAgree();

        $this->expectException(DomainException::class);   // somebody else's job
        app(PostCustomerPayment::class)->execute(['customer_id' => $this->credit()->id, 'payment_method_id' => $this->method()->id, 'amount' => '10', 'allocation' => 'manual', 'allocations' => ['j'.$job->id => '10']], $this->cashier);
    }

    public function test_the_aging_report_lists_unpaid_jobs_next_to_unpaid_invoices(): void
    {
        $c = $this->credit(['name' => 'Aging Amir']);
        $job = $this->deliver($this->completed('800', $c), [['cash', '300']]);

        $report = app(ReceivablesService::class)->report($c->id);
        $this->assertCount(1, $report['rows']);
        $this->assertSame('500.00', $report['rows'][0]['due']);
        $this->assertSame('500.00', $report['rows'][0]['balance']);
        $this->assertSame('job', $report['rows'][0]['invoices'][0]['kind']);
        $this->assertSame('0.00', $report['rows'][0]['other']);
    }

    public function test_the_integrity_check_names_a_job_whose_invoice_is_not_on_the_account(): void
    {
        $c = $this->credit();
        $job = $this->deliver($this->completed('1000', $c), [['cash', '1000']]);
        $this->assertBooksAgree();

        DB::table('service_jobs')->where('id', $job->id)->update(['paid_amount' => '1.00', 'due_amount' => '999.00', 'payment_status' => 'partial']);
        $this->assertStringContainsString($job->job_no, implode(' ', app(ServiceJobService::class)->findDrift()));
    }

    public function test_a_receipt_used_for_more_than_its_amount_is_named(): void
    {
        $c = $this->credit();
        $job = $this->completed('1000', $c);
        $payment = $this->advance($job, '300');
        $this->assertBooksAgree();

        DB::table('customer_payments')->where('id', $payment->id)->update(['amount' => '100.00']);
        $this->assertStringContainsString($payment->receipt_no, implode(' ', app(SaleAccountService::class)->findDrift()));
    }

    public function test_the_integrity_command_covers_service_jobs(): void
    {
        $job = $this->completed('500', $this->credit());
        $this->assertSame(0, Artisan::call('pos:integrity:check'));

        DB::table('service_jobs')->where('id', $job->id)->update(['grand_total' => '1.00']);
        $this->assertSame(1, Artisan::call('pos:integrity:check'));
        $this->assertStringContainsString($job->job_no, Artisan::output());
    }
}
