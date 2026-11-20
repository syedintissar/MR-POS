<?php

namespace Tests\Feature\Cheques;

use App\Actions\Customers\VoidCustomerPayment;
use App\Actions\Sales\CompleteSale;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/** The cheque register: follows cheques, reminds when they are due, records no money. */
class ChequeTest extends TestCase
{
    use ActsAsRoles;
    use MakesStock;
    use RefreshesSeededDatabase;

    public function test_a_cheque_is_added_by_hand_followed_and_shown_on_the_dashboard(): void
    {
        $supplier = Supplier::factory()->create(['name' => 'Toner Traders']);
        $this->as('Admin');

        $this->get('/cheques')->assertOk()->assertSee('Add a cheque to the register')->assertSee('No cheques here');

        $this->post('/cheques', ['direction' => 'given', 'supplier_id' => $supplier->id, 'amount' => '25000', 'cheque_no' => '000123', 'bank_name' => 'HBL', 'cheque_date' => now()->addDays(3)->toDateString()])
            ->assertSessionHasNoErrors()->assertRedirect('/cheques');
        $this->post('/cheques', ['direction' => 'received', 'party_name' => 'Walk-in office', 'amount' => '9000', 'cheque_date' => now()->addDays(30)->toDateString()])->assertSessionHasNoErrors();
        $this->post('/cheques', ['direction' => 'received', 'amount' => '10', 'cheque_date' => now()->toDateString()])->assertSessionHasErrors('party_name');
        $this->post('/cheques', ['direction' => 'received', 'party_name' => 'X', 'amount' => '0', 'cheque_date' => now()->toDateString()])->assertSessionHasErrors('amount');

        $given = Cheque::query()->where('direction', 'given')->sole();
        $this->assertSame('Toner Traders', $given->party_name);
        $this->assertSame('pending', $given->status);

        $this->get('/cheques')->assertOk()->assertSee('Toner Traders')->assertSee('000123')->assertSee('post-dated')->assertSee('25,000.00')->assertSee('9,000.00');
        // due within a week: on the dashboard; the one a month away is not
        $this->get('/')->assertOk()->assertSee('Cheques to deal with')->assertSee('Toner Traders')->assertSee('in 3 days')->assertDontSee('Walk-in office');

        // its date is moved, then it clears
        $this->post("/cheques/{$given->id}", ['status' => 'pending', 'cheque_date' => now()->subDay()->toDateString()])->assertSessionHas('success');
        $this->get('/')->assertOk()->assertSee('dated 1 day ago');
        $this->post("/cheques/{$given->id}", ['status' => 'cleared'])->assertSessionHas('success');
        $given->refresh();
        $this->assertSame('cleared', $given->status);
        $this->assertNotNull($given->status_date);
        $this->get('/')->assertOk()->assertDontSee('Cheques to deal with');
        $this->get('/cheques?status=cleared')->assertOk()->assertSee('Toner Traders')->assertSee('Undo');
        $this->post("/cheques/{$given->id}", ['status' => 'pending'])->assertSessionHas('success');
        $this->assertNull($given->fresh()->status_date);
    }

    public function test_a_payment_made_by_cheque_adds_its_cheque_and_cancelling_the_payment_cancels_it(): void
    {
        $customer = Customer::factory()->create(['name' => 'Debtor Danish', 'allow_credit' => true]);
        $cheque = PaymentMethod::query()->where('code', 'cheque')->firstOrFail();
        $cash = PaymentMethod::query()->where('code', 'cash')->firstOrFail();
        app(CompleteSale::class)->execute([
            'idempotency_key' => (string) Str::uuid(), 'customer_id' => $customer->id, 'lines' => [['product_id' => $this->stocked('5', '10', ['sale_price' => '5000'])->id, 'quantity' => '1']],
        ], User::factory()->withRole('Cashier')->create());
        $this->as('Manager');

        // cash adds nothing to the register; a cheque does
        $this->post('/customer-payments', ['customer_id' => $customer->id, 'payment_method_id' => $cash->id, 'amount' => '1000', 'allocation' => 'auto'])->assertRedirect();
        $this->assertSame(0, Cheque::query()->count());
        $this->post('/customer-payments', ['customer_id' => $customer->id, 'payment_method_id' => $cheque->id, 'amount' => '4000', 'reference_no' => 'CHQ-77', 'allocation' => 'auto'])->assertRedirect();

        $row = Cheque::query()->sole();
        $payment = CustomerPayment::query()->where('payment_method_id', $cheque->id)->sole();
        $this->assertSame('received', $row->direction);
        $this->assertSame('Debtor Danish', $row->party_name);
        $this->assertSame('CHQ-77', $row->cheque_no);
        $this->assertSame('4000.00', (string) $row->amount);
        $this->assertSame($payment->id, $row->customer_payment_id);
        $this->get('/cheques')->assertOk()->assertSee($payment->receipt_no)->assertSee('CHQ-77');

        // it bounces: the register says so and points at the payment to cancel
        $this->post("/cheques/{$row->id}", ['status' => 'bounced'])->assertSessionHas('success', fn ($m) => str_contains($m, 'cancel it'));
        $this->post("/cheques/{$row->id}", ['status' => 'pending']);

        app(VoidCustomerPayment::class)->execute($payment, 'Cheque returned by the bank', User::factory()->withRole('Admin')->create());
        $this->assertSame('cancelled', $row->fresh()->status);
    }

    public function test_only_people_with_the_cash_book_see_or_change_the_register(): void
    {
        $this->as('Cashier');
        $this->get('/cheques')->assertForbidden();
        $this->post('/cheques', ['direction' => 'received', 'party_name' => 'X', 'amount' => '10', 'cheque_date' => now()->toDateString()])->assertForbidden();

        $this->asWithOnly(['cashbook.view']);
        $this->get('/cheques')->assertOk()->assertDontSee('Add a cheque to the register');
        $this->post('/cheques', ['direction' => 'received', 'party_name' => 'X', 'amount' => '10', 'cheque_date' => now()->toDateString()])->assertForbidden();
    }
}
