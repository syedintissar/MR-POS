<?php

namespace Tests\Feature\Customers;

use App\Actions\Sales\CompleteSale;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use App\Services\Customers\CustomerAccountService;
use App\Services\Customers\ReceivablesService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/** Collecting money from customers, its receipts, statements and the "who owes us" report, through the web pages. */
class CustomerPaymentWebTest extends TestCase
{
    use ActsAsRoles;
    use MakesStock;
    use RefreshesSeededDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::factory()->create(['name' => 'Debtor Danish', 'allow_credit' => true]);
    }

    private function cash(): PaymentMethod
    {
        return PaymentMethod::query()->where('code', 'cash')->firstOrFail();
    }

    private function invoice(string $amount, int $daysAgo = 0, ?Customer $c = null): Sale
    {
        $p = $this->stocked('50', '10', ['sale_price' => $amount]);
        $sale = app(CompleteSale::class)->execute([
            'idempotency_key' => (string) Str::uuid(), 'customer_id' => ($c ?? $this->customer)->id, 'lines' => [['product_id' => $p->id, 'quantity' => '1']],
        ], User::factory()->withRole('Cashier')->create());
        if ($daysAgo > 0) {
            DB::table('sales')->where('id', $sale->id)->update(['sale_date' => now()->subDays($daysAgo)]);
        }

        return $sale->fresh();
    }

    private function collect(string $amount, array $over = []): TestResponse
    {
        return $this->post('/customer-payments', $over + [
            'customer_id' => $this->customer->id, 'payment_method_id' => $this->cash()->id, 'amount' => $amount, 'allocation' => 'auto',
        ]);
    }

    private function balance(?Customer $c = null): string
    {
        return (string) ($c ?? $this->customer)->fresh()->getRawOriginal('balance');
    }

    private function assertBooksAgree(): void
    {
        $this->assertSame([], app(CustomerAccountService::class)->findDrift());
    }

    // ---------------------------------------------------------------- collecting

    public function test_the_collect_payment_page_lists_the_open_invoices(): void
    {
        $a = $this->invoice('300');
        $this->as('Cashier');

        $this->get('/customer-payments/create')->assertOk();
        $this->get('/customer-payments/create?customer='.$this->customer->id)->assertOk()->assertSee($a->invoice_no)->assertSee('300.00');
        $this->get('/customer-payments/create?sale='.$a->id)->assertOk()->assertSee($a->invoice_no);
        $this->get('/customer-payments')->assertOk();
    }

    public function test_a_payment_settles_the_oldest_invoice_first_and_offers_the_receipt(): void
    {
        $old = $this->invoice('300', 10);
        $new = $this->invoice('200', 1);
        $this->as('Cashier');

        $r = $this->collect('350')->assertRedirect();
        $r->assertSessionHas('success')->assertSessionHas('receipt_url');

        $this->assertSame('paid', $old->fresh()->payment_status);
        $this->assertSame('150.00', (string) $new->fresh()->due_amount);
        $this->assertSame('150.00', $this->balance());
        $payment = CustomerPayment::query()->latest('id')->firstOrFail();
        $this->assertSame('350.00', (string) $payment->amount);
        $this->assertNull($payment->sale_id);
        $this->assertStringStartsWith('RCP-', $payment->receipt_no);
        $this->assertBooksAgree();

        $this->get('/customer-payments/'.$payment->id)->assertOk()->assertSee($payment->receipt_no)->assertSee($old->invoice_no)->assertSee($new->invoice_no);
        $this->get('/customer-payments')->assertOk()->assertSee($payment->receipt_no);
    }

    public function test_a_manual_payment_settles_exactly_the_chosen_invoices(): void
    {
        $old = $this->invoice('300', 10);
        $new = $this->invoice('200', 1);
        $this->as('Cashier');

        $this->collect('200', ['allocation' => 'manual', 'allocations' => [$new->id => '200', $old->id => '']])->assertRedirect();

        $this->assertSame('paid', $new->fresh()->payment_status);
        $this->assertSame('300.00', (string) $old->fresh()->due_amount);
        $this->assertSame('300.00', $this->balance());
        $this->assertBooksAgree();
    }

    public function test_money_beyond_what_is_owed_stays_as_the_customers_credit(): void
    {
        $this->invoice('100');
        $this->as('Cashier');

        $this->collect('250')->assertRedirect();

        $this->assertSame('-150.00', $this->balance());
        $this->assertSame('150.00', app(CustomerAccountService::class)->advanceFor($this->customer->id));
        $payment = CustomerPayment::query()->latest('id')->firstOrFail();
        $this->get('/customer-payments/'.$payment->id)->assertOk()->assertSee('150.00');

        $new = $this->invoice('120');   // the credit can then be applied to the next invoice
        $this->post('/customers/'.$this->customer->id.'/apply-advance')->assertRedirect()->assertSessionHas('success');
        $this->assertSame('paid', $new->fresh()->payment_status);
        $this->assertSame('-30.00', $this->balance());
        $this->assertBooksAgree();
    }

    public function test_a_payment_that_is_not_valid_changes_nothing(): void
    {
        $this->invoice('100');
        $this->as('Cashier');

        $this->collect('0')->assertSessionHasErrors('amount');
        $this->collect('10.555')->assertSessionHasErrors('amount');
        $this->collect('-5')->assertSessionHasErrors('amount');
        $this->collect('50', ['customer_id' => 999999])->assertSessionHasErrors('customer_id');
        $this->collect('50', ['payment_method_id' => PaymentMethod::query()->where('kind', 'customer_credit')->value('id')])->assertSessionHas('error');
        $this->collect('50', ['customer_id' => Customer::walkIn()->id])->assertSessionHas('error');
        $this->collect('50', ['allocation' => 'sideways'])->assertSessionHasErrors('allocation');

        $this->assertSame(0, CustomerPayment::query()->count());
        $this->assertSame('100.00', $this->balance());
    }

    public function test_only_people_who_may_collect_can_collect(): void
    {
        $this->invoice('100');

        $this->as('Technician');
        $this->collect('50')->assertForbidden();
        $this->asWithOnly(['customer_payments.view', 'customers.view']);
        $this->collect('50')->assertForbidden();
        $this->post('/customers/'.$this->customer->id.'/apply-advance')->assertForbidden();
        $this->assertSame(0, CustomerPayment::query()->count());
    }

    // ---------------------------------------------------------------- cancelling

    public function test_cancelling_a_payment_needs_permission_the_password_and_a_reason(): void
    {
        $sale = $this->invoice('300');
        $this->as('Cashier');
        $this->collect('300')->assertRedirect();
        $payment = CustomerPayment::query()->latest('id')->firstOrFail();
        $this->assertSame('paid', $sale->fresh()->payment_status);

        $this->post("/customer-payments/{$payment->id}/void", ['reason' => 'typo'])->assertForbidden();

        $this->as('Manager');
        $this->post("/customer-payments/{$payment->id}/void", ['reason' => 'typo']);
        $this->assertSame('posted', $payment->fresh()->status, 'the password was not asked for');

        $this->withSession($this->confirmed())->post("/customer-payments/{$payment->id}/void", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame('posted', $payment->fresh()->status);

        $this->withSession($this->confirmed())->post("/customer-payments/{$payment->id}/void", ['reason' => 'Entered twice'])
            ->assertRedirect(route('customer-payments.show', $payment))->assertSessionHas('success');

        $this->assertTrue($payment->fresh()->isVoided());
        $this->assertSame('300.00', (string) $sale->fresh()->due_amount);
        $this->assertSame('300.00', $this->balance());
        $this->assertSame(1, AuditLog::query()->where('event', 'customer_payment_voided')->count());
        $this->get("/customer-payments/{$payment->id}")->assertOk()->assertSee('Entered twice')->assertSee('Cancelled');
        $this->assertBooksAgree();

        $this->withSession($this->confirmed())->post("/customer-payments/{$payment->id}/void", ['reason' => 'again'])->assertSessionHas('error');
        $this->assertSame('300.00', $this->balance(), 'cancelling twice must not move the account twice');
    }

    // ---------------------------------------------------------------- receipts

    public function test_the_original_receipt_needs_its_signed_link_and_a_reprint_is_marked_and_audited(): void
    {
        $this->invoice('300');
        $this->as('Cashier');
        $this->collect('120')->assertRedirect();
        $payment = CustomerPayment::query()->latest('id')->firstOrFail();

        $this->get("/customer-payments/{$payment->id}/receipt")->assertForbidden();

        $signed = URL::temporarySignedRoute('customer-payments.receipt', now()->addMinutes(10), ['payment' => $payment->id]);
        $this->get($signed)->assertOk()->assertSee($payment->receipt_no)->assertSee('120.00')->assertDontSee('marked DUPLICATE', false);

        $this->get("/customer-payments/{$payment->id}/reprint")->assertOk()->assertSee('marked DUPLICATE', false);
        $this->assertSame(1, AuditLog::query()->where('event', 'customer_payment_reprinted')->count());

        $this->asWithOnly(['customer_payments.view'])->get("/customer-payments/{$payment->id}/reprint")->assertForbidden();
        $this->assertSame(1, AuditLog::query()->where('event', 'customer_payment_reprinted')->count());
    }

    // ---------------------------------------------------------------- statements

    public function test_the_statement_shows_the_running_balance_and_can_be_downloaded_as_a_pdf(): void
    {
        $inv = $this->invoice('500', 20);
        $this->as('Cashier');
        $this->collect('200')->assertRedirect();

        $this->get("/customers/{$this->customer->id}/statement")->assertOk()->assertSee($inv->invoice_no)->assertSee('Debtor Danish')->assertSee('300.00');
        $this->get("/customers/{$this->customer->id}/statement?from=".now()->subDays(5)->toDateString())->assertOk()->assertSee('Brought forward');
        $this->get("/customers/{$this->customer->id}/statement?from=not-a-date")->assertSessionHasErrors('from');

        $pdf = $this->get("/customers/{$this->customer->id}/statement.pdf")->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertSame(1, AuditLog::query()->where('event', 'customer_statement_printed')->count());

        $this->asWithOnly(['customers.view'])->get("/customers/{$this->customer->id}/statement")->assertForbidden();
        $this->asWithOnly(['customers.statement'])->get("/customers/{$this->customer->id}/statement")->assertForbidden();   // needs the money permission too
        $this->get('/customers/'.Customer::walkIn()->id.'/statement')->assertForbidden();
    }

    public function test_the_walk_in_customer_has_no_statement(): void
    {
        $this->as('Manager');
        $this->get('/customers/'.Customer::walkIn()->id.'/statement')->assertNotFound();
        $this->get('/customers/'.Customer::walkIn()->id.'/statement.pdf')->assertNotFound();
    }

    // ---------------------------------------------------------------- who owes us

    public function test_the_aging_report_sorts_unpaid_invoices_into_age_bands(): void
    {
        $this->invoice('100');          // today
        $this->invoice('200', 5);       // 1-30
        $this->invoice('300', 40);      // 31-60
        $this->invoice('400', 75);      // 61-90
        $this->invoice('500', 120);     // over 90
        $this->as('Admin');
        $this->withSession($this->confirmed())->post("/customers/{$this->customer->id}/adjust-balance", ['type' => 'opening_balance', 'direction' => 'customer_owes', 'amount' => '50', 'reason' => 'old']);

        $report = app(ReceivablesService::class)->report();
        $row = $report['rows'][0];
        $this->assertSame($this->customer->id, $row['customer']->id);
        $this->assertSame(['current' => '100.00', 'd1_30' => '200.00', 'd31_60' => '300.00', 'd61_90' => '400.00', 'd90' => '500.00'], $row['buckets']);
        $this->assertSame('1500.00', $row['due']);
        $this->assertSame('50.00', $row['other'], 'the opening balance is not on an invoice');
        $this->assertSame('1550.00', $row['balance']);
        $this->assertSame('1550.00', $report['totals']['balance']);

        $this->get('/receivables')->assertOk()->assertSee('Debtor Danish')->assertSee('1,550.00');
        $this->get('/receivables?detail=1&customer_id='.$this->customer->id)->assertOk()->assertSee('INV-');
    }

    public function test_the_bands_change_at_the_right_days(): void
    {
        $this->assertSame('current', ReceivablesService::bucketFor(0));
        $this->assertSame('d1_30', ReceivablesService::bucketFor(1));
        $this->assertSame('d1_30', ReceivablesService::bucketFor(30));
        $this->assertSame('d31_60', ReceivablesService::bucketFor(31));
        $this->assertSame('d31_60', ReceivablesService::bucketFor(60));
        $this->assertSame('d61_90', ReceivablesService::bucketFor(61));
        $this->assertSame('d61_90', ReceivablesService::bucketFor(90));
        $this->assertSame('d90', ReceivablesService::bucketFor(91));
    }

    public function test_the_report_only_lists_people_who_owe_or_hold_something_and_never_the_walk_in_customer(): void
    {
        $paid = Customer::factory()->create(['name' => 'Settled Sami', 'allow_credit' => true]);
        $this->invoice('100', 0, $paid);
        $this->actingAs(User::factory()->withRole('Cashier')->create());
        $this->post('/customer-payments', ['customer_id' => $paid->id, 'payment_method_id' => $this->cash()->id, 'amount' => '100', 'allocation' => 'auto']);
        $this->invoice('80');   // Debtor Danish owes 80

        $names = collect(app(ReceivablesService::class)->report()['rows'])->map(fn ($r) => $r['customer']->name)->all();
        $this->assertSame(['Debtor Danish'], $names);
    }

    public function test_the_report_needs_its_own_permission(): void
    {
        $this->invoice('100');

        $this->as('Cashier')->get('/receivables')->assertForbidden();
        $this->as('Technician')->get('/receivables')->assertForbidden();
        $this->as('Manager')->get('/receivables')->assertOk();
        $this->asWithOnly(['reports.customers'])->get('/receivables')->assertOk();
    }

    public function test_the_sales_staff_cannot_open_the_collected_payments_without_the_permission(): void
    {
        $this->invoice('100');
        $this->collectedAs('Cashier');

        $this->asWithOnly(['customers.view'])->get('/customer-payments')->assertForbidden();
        $this->as('Manager')->get('/customer-payments?source=all&status=posted&from=2020-01-01&to=2099-01-01&q=RCP')->assertOk();
    }

    private function collectedAs(string $role): void
    {
        $this->as($role);
        $this->collect('40')->assertRedirect();
    }
}
