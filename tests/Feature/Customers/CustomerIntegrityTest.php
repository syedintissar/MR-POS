<?php

namespace Tests\Feature\Customers;

use App\Actions\Customers\PostCustomerPayment;
use App\Actions\Sales\CompleteSale;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/** `pos:integrity:check` must catch customer figures that no longer agree with the ledger, and the ledger must refuse to be edited. */
class CustomerIntegrityTest extends TestCase
{
    use MakesStock;
    use RefreshesSeededDatabase;

    private Customer $customer;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->withRole('Cashier')->create();
        $this->customer = Customer::factory()->create(['allow_credit' => true]);
    }

    private function invoice(string $amount): Sale
    {
        return app(CompleteSale::class)->execute([
            'idempotency_key' => (string) Str::uuid(), 'customer_id' => $this->customer->id,
            'lines' => [['product_id' => $this->stocked('10', '10', ['sale_price' => $amount])->id, 'quantity' => '1']],
        ], $this->cashier);
    }

    private function collect(string $amount): CustomerPayment
    {
        return app(PostCustomerPayment::class)->execute([
            'customer_id' => $this->customer->id, 'payment_method_id' => PaymentMethod::query()->where('code', 'cash')->value('id'), 'amount' => $amount, 'allocation' => 'auto',
        ], $this->cashier);
    }

    public function test_good_books_pass_and_a_hand_edited_balance_is_named(): void
    {
        $this->invoice('400');
        $this->collect('150');
        $this->assertSame(0, Artisan::call('pos:integrity:check'));

        DB::table('customers')->where('id', $this->customer->id)->update(['balance' => '1.00']);

        $this->assertSame(1, Artisan::call('pos:integrity:check'));
        $this->assertStringContainsString($this->customer->code, Artisan::output());
    }

    public function test_an_invoice_whose_paid_figure_was_tampered_with_is_named(): void
    {
        $sale = $this->invoice('400');
        DB::table('sales')->where('id', $sale->id)->update(['paid_amount' => '400.00', 'due_amount' => '0.00', 'payment_status' => 'paid']);

        $this->assertSame(1, Artisan::call('pos:integrity:check'));
        $this->assertStringContainsString($sale->invoice_no, Artisan::output());
    }

    public function test_a_receipt_changed_after_the_fact_no_longer_matches_the_ledger(): void
    {
        $this->invoice('400');
        $payment = $this->collect('150');
        DB::table('customer_payments')->where('id', $payment->id)->update(['amount' => '250.00']);

        $this->assertSame(1, Artisan::call('pos:integrity:check'));
        $this->assertStringContainsString($payment->receipt_no, Artisan::output());
    }

    public function test_the_customer_ledger_cannot_be_edited_or_deleted_even_by_hand(): void
    {
        $this->invoice('400');
        $id = DB::table('customer_ledger_entries')->value('id');

        foreach ([
            fn () => DB::table('customer_ledger_entries')->where('id', $id)->update(['debit' => '1.00']),
            fn () => DB::table('customer_ledger_entries')->where('id', $id)->delete(),
            fn () => DB::table('customer_ledger_entries')->insert(['customer_id' => $this->customer->id, 'entry_date' => now(), 'entry_type' => 'payment', 'debit' => '5.00', 'credit' => '5.00', 'description' => 'both sides', 'created_at' => now()]),
            fn () => DB::table('customer_ledger_entries')->insert(['customer_id' => $this->customer->id, 'entry_date' => now(), 'entry_type' => 'made_up', 'debit' => '5.00', 'credit' => '0.00', 'description' => 'unknown type', 'created_at' => now()]),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('The database accepted a change to the customer ledger that it must refuse.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, Artisan::call('pos:integrity:check'));
    }

    public function test_a_hand_made_ledger_line_on_the_walk_in_account_shows_up_as_drift(): void
    {
        // nothing in the program writes to the walk-in account; a hand-made entry shows up as drift against its (always zero) balance
        DB::table('customer_ledger_entries')->insert(['customer_id' => Customer::walkIn()->id, 'entry_date' => now(), 'entry_type' => 'balance_adjustment', 'debit' => '5.00', 'credit' => '0.00', 'description' => 'hand made', 'created_at' => now()]);

        $this->assertSame(1, Artisan::call('pos:integrity:check'));
    }
}
