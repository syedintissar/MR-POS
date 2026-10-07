<?php

namespace Tests\Feature\Purchasing;

use App\Actions\Purchasing\PostSupplierPayment;
use App\Actions\Purchasing\ReceivePurchase;
use App\Actions\Purchasing\SavePurchaseDraft;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/** The purchase, supplier payment and supplier account screens, driven the way a browser drives them. */
class PurchaseWebTest extends TestCase
{
    use ActsAsRoles;
    use MakesStock;
    use RefreshesSeededDatabase;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplier = Supplier::factory()->create();
    }

    private function method(string $code = 'cash'): PaymentMethod
    {
        return PaymentMethod::query()->where('code', $code)->firstOrFail();
    }

    private function payload(Product $p, array $over = []): array
    {
        return $over + [
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->toDateString(),
            'lines' => [['product_id' => $p->id, 'quantity' => '10', 'unit_cost' => '100']],
        ];
    }

    private function draft(?Product $p = null, string $qty = '10', string $cost = '100'): Purchase
    {
        return app(SavePurchaseDraft::class)->execute([
            'supplier_id' => $this->supplier->id, 'purchase_date' => now()->toDateString(),
            'lines' => [['product_id' => ($p ?? Product::factory()->create())->id, 'quantity' => $qty, 'unit_cost' => $cost]],
        ], User::factory()->create());
    }

    private function received(string $qty = '10', string $cost = '100'): Purchase
    {
        return app(ReceivePurchase::class)->execute($this->draft(null, $qty, $cost), User::factory()->create());
    }

    // ---------------------------------------------------------------- pages render

    public function test_every_purchasing_page_renders_for_a_manager(): void
    {
        $bill = $this->received();
        $draft = $this->draft();
        $payment = app(PostSupplierPayment::class)->execute(['supplier_id' => $this->supplier->id, 'payment_method_id' => $this->method()->id, 'amount' => '300'], User::factory()->create());
        $this->as('Manager');

        $this->get('/purchases')->assertOk()->assertSee($bill->purchase_no);
        $this->get('/purchases?status=draft&payment=unpaid&q=PUR')->assertOk();
        $this->get('/purchases/create')->assertOk()->assertSee('Save &amp; receive stock', false);
        $this->get("/purchases/{$bill->id}")->assertOk()->assertSee($bill->purchase_no)->assertSee('Payments against this bill');
        $this->get("/purchases/{$draft->id}")->assertOk()->assertSee('Receive into stock');
        $this->get("/purchases/{$draft->id}/edit")->assertOk();
        $this->get("/purchases/{$bill->id}/print")->assertOk()->assertSee('PURCHASE RECORD');
        $this->get('/supplier-payments')->assertOk()->assertSee($payment->payment_no);
        $this->get('/supplier-payments/create')->assertOk()->assertSee('Which supplier');
        $this->get("/supplier-payments/create?supplier={$this->supplier->id}")->assertOk()->assertSee($bill->purchase_no);
        $this->get("/supplier-payments/create?supplier={$this->supplier->id}&purchase={$bill->id}")->assertOk();
        $this->get("/supplier-payments/{$payment->id}")->assertOk()->assertSee($payment->payment_no);
        $this->get("/suppliers/{$this->supplier->id}")->assertOk()->assertSee('Account statement')->assertSee($bill->purchase_no);
        $this->get("/suppliers/{$this->supplier->id}?from=2020-01-01&to=2099-01-01")->assertOk();
        $this->get("/suppliers/{$this->supplier->id}/statement")->assertOk()->assertSee('SUPPLIER ACCOUNT STATEMENT');
        $this->get('/suppliers')->assertOk()->assertSee('Balance');
        $this->get("/suppliers/{$this->supplier->id}/edit")->assertOk()->assertSee('Open the account');
    }

    public function test_the_edit_page_of_a_draft_shows_its_lines_and_a_received_purchase_cannot_be_edited(): void
    {
        $draft = $this->draft(Product::factory()->create(['name' => 'Brother TN-2480 toner']));
        $bill = $this->received();
        $this->as('Manager');

        $this->get("/purchases/{$draft->id}/edit")->assertOk()->assertSee('Brother TN-2480 toner');
        $this->get("/purchases/{$bill->id}/edit")->assertRedirect("/purchases/{$bill->id}")->assertSessionHas('error');
    }

    // ---------------------------------------------------------------- creating and receiving

    public function test_saving_a_draft_changes_no_stock_and_no_account(): void
    {
        $p = Product::factory()->create();
        $this->as('Manager')->post('/purchases', $this->payload($p, ['action' => 'draft']))->assertRedirect()->assertSessionHas('success');

        $purchase = Purchase::query()->sole();
        $this->assertSame('draft', $purchase->status);
        $this->assertSame('1000.00', $purchase->grand_total);
        $this->assertSame('0.000', $p->fresh()->current_stock);
        $this->assertSame(0, SupplierLedgerEntry::query()->count());
    }

    public function test_save_and_receive_adds_the_stock_numbers_the_bill_and_records_the_payment(): void
    {
        $p = Product::factory()->create();

        $this->as('Manager')->post('/purchases', $this->payload($p, [
            'action' => 'receive', 'additional_cost' => '50', 'pay_amount' => '400', 'pay_method_id' => $this->method()->id,
        ]))->assertRedirect()->assertSessionHas('success');

        $purchase = Purchase::query()->sole();
        $this->assertSame('PUR-000001', $purchase->purchase_no);
        $this->assertSame('1050.00', $purchase->grand_total);
        $this->assertSame('400.00', $purchase->paid_amount);
        $this->assertSame('10.000', $p->fresh()->current_stock);
        $this->assertSame('105.0000', $p->fresh()->average_cost, 'Freight is part of the cost: (1000 + 50) / 10');
        $this->assertSame('650.00', (string) $this->supplier->fresh()->getRawOriginal('balance'));
    }

    public function test_a_failed_receive_keeps_the_draft_and_changes_nothing_else(): void
    {
        $p = Product::factory()->create();

        $response = $this->as('Manager')->post('/purchases', $this->payload($p, [
            'action' => 'receive', 'pay_amount' => '100', 'pay_method_id' => $this->method('bank_transfer')->id,   // needs a reference
        ]));

        $purchase = Purchase::query()->sole();
        $response->assertRedirect("/purchases/{$purchase->id}/edit")->assertSessionHas('error');
        $this->assertStringContainsString('NOT received', session('error'));
        $this->assertSame('draft', $purchase->status);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, SupplierPayment::query()->count());
    }

    public function test_a_draft_can_be_edited_received_from_its_page_and_deleted(): void
    {
        $p = Product::factory()->create();
        $draft = $this->draft($p, '2', '10');
        $this->as('Manager');

        $this->put("/purchases/{$draft->id}", $this->payload($p, ['action' => 'draft', 'lines' => [['product_id' => $p->id, 'quantity' => '5', 'unit_cost' => '10']]]))
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame('50.00', $draft->fresh()->grand_total);

        $this->post("/purchases/{$draft->id}/receive")->assertRedirect("/purchases/{$draft->id}")->assertSessionHas('success');
        $this->assertSame('received', $draft->fresh()->status);
        $this->assertSame('5.000', $p->fresh()->current_stock);

        $other = $this->draft();
        $this->delete("/purchases/{$other->id}")->assertRedirect('/purchases');
        $this->assertNull(Purchase::query()->find($other->id));

        $this->delete("/purchases/{$draft->id}")->assertSessionHas('error');   // received: cannot be deleted
        $this->assertNotNull(Purchase::query()->find($draft->id));
    }

    public function test_bad_input_is_rejected_with_messages_and_nothing_is_saved(): void
    {
        $p = Product::factory()->create();
        $service = Product::factory()->create(['type' => 'service']);
        $this->as('Manager');

        $bad = [
            'no lines' => $this->payload($p, ['lines' => []]),
            'negative cost' => $this->payload($p, ['lines' => [['product_id' => $p->id, 'quantity' => '1', 'unit_cost' => '-5']]]),
            'zero quantity' => $this->payload($p, ['lines' => [['product_id' => $p->id, 'quantity' => '0', 'unit_cost' => '5']]]),
            'cost missing' => $this->payload($p, ['lines' => [['product_id' => $p->id, 'quantity' => '1']]]),
            'five decimals' => $this->payload($p, ['lines' => [['product_id' => $p->id, 'quantity' => '1', 'unit_cost' => '1.12345']]]),
            'exponent' => $this->payload($p, ['lines' => [['product_id' => $p->id, 'quantity' => '1e3', 'unit_cost' => '1']]]),
            'service item' => $this->payload($service),
            'future date' => $this->payload($p, ['purchase_date' => now()->addDay()->toDateString()]),
            'unknown supplier' => $this->payload($p, ['supplier_id' => 999999]),
            'huge discount' => $this->payload($p, ['discount_amount' => '999999']),
        ];

        foreach ($bad as $label => $data) {
            $this->post('/purchases', $data + ['action' => 'draft'])->assertRedirect();
            // refused either by the form validation (field errors) or by the business rules (an error message)
            $this->assertTrue(session()->has('errors') || session()->has('error'), "Bad input was accepted: {$label}");
            $this->assertSame(0, Purchase::query()->count(), "Bad input was saved: {$label}");
        }
        $this->assertSame(0, Purchase::query()->count(), 'Nothing may be saved from bad input.');
    }

    public function test_the_same_supplier_invoice_number_is_refused_with_a_clear_message(): void
    {
        $p = Product::factory()->create();
        $this->as('Manager');

        $this->post('/purchases', $this->payload($p, ['supplier_invoice_no' => 'A-1', 'action' => 'draft']))->assertSessionHas('success');
        $this->post('/purchases', $this->payload($p, ['supplier_invoice_no' => 'A-1', 'action' => 'draft']))->assertSessionHas('error');

        $this->assertSame(1, Purchase::query()->count());
    }

    public function test_a_user_who_may_only_prepare_drafts_cannot_receive(): void
    {
        $p = Product::factory()->create();
        $this->asWithOnly(['purchases.view', 'purchases.create', 'products.view']);

        $this->post('/purchases', $this->payload($p, ['action' => 'receive']))->assertForbidden();
        $this->assertSame(0, Purchase::query()->count(), 'Nothing is saved when the receive part is not allowed.');

        $this->post('/purchases', $this->payload($p, ['action' => 'draft']))->assertSessionHas('success');
        $draft = Purchase::query()->sole();
        $this->post("/purchases/{$draft->id}/receive")->assertForbidden();
        $this->assertSame('draft', $draft->fresh()->status);
    }

    // ---------------------------------------------------------------- cancelling

    public function test_cancelling_a_purchase_needs_the_password_and_a_reason(): void
    {
        $p = Product::factory()->create();
        $bill = app(ReceivePurchase::class)->execute($this->draft($p), User::factory()->create());
        $this->as('Manager');

        $this->post("/purchases/{$bill->id}/void", ['reason' => 'x'])->assertRedirect(route('password.confirm'));
        $this->assertSame('received', $bill->fresh()->status);

        $this->withSession($this->confirmed())->post("/purchases/{$bill->id}/void", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->withSession($this->confirmed())->post("/purchases/{$bill->id}/void", ['reason' => 'Wrong supplier'])->assertRedirect("/purchases/{$bill->id}")->assertSessionHas('success');

        $this->assertSame('voided', $bill->fresh()->status);
        $this->assertSame('0.000', $p->fresh()->current_stock);
        $this->assertSame('0.00', (string) $this->supplier->fresh()->getRawOriginal('balance'));
    }

    public function test_cancelling_is_refused_politely_when_the_goods_are_gone(): void
    {
        $p = Product::factory()->create();
        $bill = app(ReceivePurchase::class)->execute($this->draft($p), User::factory()->create());
        $this->inventory()->move($p, '-9', 'sale');

        $this->as('Manager')->withSession($this->confirmed())->post("/purchases/{$bill->id}/void", ['reason' => 'x'])->assertSessionHas('error');
        $this->assertSame('received', $bill->fresh()->status);
    }

    // ---------------------------------------------------------------- lookup

    public function test_the_product_search_shows_the_last_cost_paid_to_this_supplier(): void
    {
        $p = Product::factory()->create(['name' => 'Canon GI-790 black ink']);
        $other = Supplier::factory()->create();
        app(ReceivePurchase::class)->execute($this->draft($p, '4', '250'), User::factory()->create());

        $rows = $this->as('Manager')->getJson('/purchases/lookup?q=GI-790&supplier_id='.$this->supplier->id)->assertOk()->json();
        $this->assertSame('250', $rows[0]['supplier_last_cost']);
        $this->assertSame('250', $rows[0]['last_cost']);

        $rows = $this->getJson('/purchases/lookup?q=GI-790&supplier_id='.$other->id)->assertOk()->json();
        $this->assertNull($rows[0]['supplier_last_cost']);
    }

    // ---------------------------------------------------------------- supplier payments

    public function test_recording_a_payment_settles_the_oldest_bill_and_shows_the_result(): void
    {
        $a = $this->received('10', '100');
        $b = $this->received('5', '100');
        $this->as('Manager');

        $this->post('/supplier-payments', [
            'supplier_id' => $this->supplier->id, 'payment_date' => now()->toDateString(), 'payment_method_id' => $this->method()->id,
            'amount' => '1200', 'allocation' => 'auto',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('paid', $a->fresh()->payment_status);
        $this->assertSame('300.00', $b->fresh()->due_amount);
        $this->assertSame('300.00', (string) $this->supplier->fresh()->getRawOriginal('balance'));
    }

    public function test_a_manual_payment_validates_the_amounts_given_to_each_bill(): void
    {
        $a = $this->received('5', '100');
        $this->as('Manager');
        $base = ['supplier_id' => $this->supplier->id, 'payment_date' => now()->toDateString(), 'payment_method_id' => $this->method()->id, 'allocation' => 'manual'];

        $this->post('/supplier-payments', $base + ['amount' => '900', 'allocations' => [$a->id => '600']])->assertSessionHas('error');   // bill owes only 500
        $this->post('/supplier-payments', $base + ['amount' => '100', 'allocations' => [$a->id => '1.234']])->assertSessionHasErrors();       // too many decimals
        $this->assertSame(0, SupplierPayment::query()->count());

        $this->post('/supplier-payments', $base + ['amount' => '200', 'allocations' => [$a->id => '200']])->assertSessionHas('success');
        $this->assertSame('300.00', $a->fresh()->due_amount);
    }

    public function test_a_payment_needs_a_reference_when_its_method_asks_for_one(): void
    {
        $this->received();
        $this->as('Manager')->post('/supplier-payments', [
            'supplier_id' => $this->supplier->id, 'payment_date' => now()->toDateString(), 'payment_method_id' => $this->method('cheque')->id, 'amount' => '100', 'allocation' => 'auto',
        ])->assertSessionHas('error');
        $this->assertSame(0, SupplierPayment::query()->count());
    }

    public function test_cancelling_a_payment_and_applying_an_advance_work_from_the_screens(): void
    {
        $bill = $this->received('5', '100');
        $payment = app(PostSupplierPayment::class)->execute(['supplier_id' => $this->supplier->id, 'payment_method_id' => $this->method()->id, 'amount' => '700', 'allocation' => 'auto'], User::factory()->create());
        $next = $this->received('4', '100');   // 400 owed; the advance of 200 can be applied
        $this->as('Manager');

        $this->get("/supplier-payments/{$payment->id}")->assertOk()->assertSee('Apply the advance');
        $this->post("/supplier-payments/{$payment->id}/allocate", ['allocations' => [$next->id => '150']])->assertSessionHas('success');
        $this->assertSame('250.00', $next->fresh()->due_amount);
        $this->post("/supplier-payments/{$payment->id}/allocate", ['allocations' => [$next->id => '100']])->assertSessionHas('error');   // only 50 left

        $this->post("/supplier-payments/{$payment->id}/void", ['reason' => 'x'])->assertRedirect(route('password.confirm'));
        $this->withSession($this->confirmed())->post("/supplier-payments/{$payment->id}/void", ['reason' => 'Wrong supplier'])->assertSessionHas('success');
        $this->assertSame('voided', $payment->fresh()->status);
        $this->assertSame('500.00', $bill->fresh()->due_amount);
        $this->assertSame('400.00', $next->fresh()->due_amount);
    }

    // ---------------------------------------------------------------- supplier account

    public function test_only_an_administrator_can_adjust_a_supplier_balance_and_it_needs_the_password(): void
    {
        $url = "/suppliers/{$this->supplier->id}/adjust-balance";
        $data = ['type' => 'opening_balance', 'direction' => 'we_owe', 'amount' => '12500', 'reason' => 'From the paper register'];

        $this->as('Manager')->withSession($this->confirmed())->post($url, $data)->assertForbidden();

        $this->flushSession();   // forget the manager's password confirmation
        $this->as('Admin')->post($url, $data)->assertRedirect(route('password.confirm'));
        $this->withSession($this->confirmed())->post($url, $data)->assertRedirect()->assertSessionHas('success');

        $this->assertSame('12500.00', (string) $this->supplier->fresh()->getRawOriginal('balance'));
        $this->get("/suppliers/{$this->supplier->id}")->assertOk()->assertSee('Opening balance')->assertSee('12,500.00');

        $this->withSession($this->confirmed())->post($url, $data)->assertSessionHas('error');   // opening balance only once
        $this->withSession($this->confirmed())->post($url, array_merge($data, ['type' => 'balance_adjustment', 'reason' => '']))->assertSessionHasErrors('reason');
    }

    public function test_the_statement_has_a_running_balance_and_a_brought_forward_line(): void
    {
        $this->received('10', '100');
        $this->as('Manager');

        $this->get("/suppliers/{$this->supplier->id}/statement")->assertOk()->assertSee('1,000.00');
        $this->get("/suppliers/{$this->supplier->id}/statement?from=".now()->addDay()->toDateString())->assertOk()->assertSee('No entries in this period');
    }

    // ---------------------------------------------------------------- payment methods in settings

    public function test_an_administrator_manages_payment_methods_and_system_methods_are_protected(): void
    {
        $this->as('Admin');
        $this->get('/admin/settings/payment_methods')->assertOk()->assertSee('Easypaisa')->assertSee('Customer Credit');

        $this->post('/admin/payment-methods', ['name' => 'Meezan Bank', 'kind' => 'bank', 'requires_reference' => '1'])->assertSessionHas('success');
        $new = PaymentMethod::query()->where('name', 'Meezan Bank')->sole();
        $this->assertSame('meezan_bank', $new->code);
        $this->assertTrue($new->requires_reference);
        $this->post('/admin/payment-methods', ['name' => 'meezan bank', 'kind' => 'bank'])->assertSessionHasErrors('name');   // unique, case-insensitively
        $this->post('/admin/payment-methods', ['name' => 'Sneaky', 'kind' => 'customer_credit'])->assertSessionHasErrors('kind');

        $this->put("/admin/payment-methods/{$new->id}", ['name' => 'Meezan Current', 'kind' => 'bank'])->assertSessionHas('success');
        $this->assertSame('Meezan Current', $new->fresh()->name);
        $this->assertFalse($new->fresh()->requires_reference);

        $this->post("/admin/payment-methods/{$new->id}/toggle")->assertSessionHas('success');
        $this->assertFalse($new->fresh()->is_active);

        $cash = $this->method('cash');
        $this->post("/admin/payment-methods/{$cash->id}/toggle")->assertSessionHas('error');
        $this->delete("/admin/payment-methods/{$cash->id}")->assertSessionHas('error');
        $this->assertTrue($cash->fresh()->is_active);

        // a method that has been used can never be deleted, only switched off
        $used = $this->method('card');
        app(PostSupplierPayment::class)->execute(['supplier_id' => $this->supplier->id, 'payment_method_id' => $used->id, 'amount' => '10', 'allocation' => 'advance'], User::factory()->create());
        $this->delete("/admin/payment-methods/{$used->id}")->assertSessionHas('error');
        $this->assertNotNull(PaymentMethod::query()->find($used->id));

        $this->delete("/admin/payment-methods/{$new->id}")->assertSessionHas('success');
        $this->assertNull(PaymentMethod::query()->find($new->id));
    }

    public function test_payment_method_names_fit_the_database_and_generated_codes_never_collide(): void
    {
        $this->as('Admin');

        $long = str_repeat('A', 50);
        $this->post('/admin/payment-methods', ['name' => $long, 'kind' => 'bank'])->assertSessionHas('success');
        $this->post('/admin/payment-methods', ['name' => $long.'B', 'kind' => 'bank'])->assertSessionHasErrors('name');   // 51 characters

        // two names that turn into the same code still get different codes
        $this->post('/admin/payment-methods', ['name' => 'Bank A/B', 'kind' => 'bank'])->assertSessionHas('success');
        $this->post('/admin/payment-methods', ['name' => 'Bank A B', 'kind' => 'bank'])->assertSessionHas('success');
        $codes = PaymentMethod::query()->whereIn('name', ['Bank A/B', 'Bank A B'])->pluck('code')->all();
        $this->assertCount(2, array_unique($codes));
        $this->assertLessThanOrEqual(30, max(array_map('strlen', PaymentMethod::query()->pluck('code')->all())));
    }

    public function test_the_default_payment_methods_exist_after_migrating_alone(): void
    {
        // an installation that only runs "php artisan migrate" must be able to pay a supplier straight away
        $codes = PaymentMethod::query()->orderBy('sort_order')->pluck('code')->all();
        $this->assertSame(['cash', 'bank_transfer', 'card', 'easypaisa', 'jazzcash', 'cheque', 'customer_credit'], $codes);
        $this->assertSame(['cash', 'customer_credit'], PaymentMethod::query()->where('is_system', true)->orderBy('sort_order')->pluck('code')->all());
    }

    public function test_a_manager_may_see_but_not_change_payment_methods(): void
    {
        $this->as('Manager');
        $this->get('/admin/settings/payment_methods')->assertOk();
        $this->post('/admin/payment-methods', ['name' => 'X', 'kind' => 'bank'])->assertForbidden();
    }

    public function test_a_switched_off_method_cannot_be_used_for_a_payment(): void
    {
        $this->received();
        $card = $this->method('card');
        $card->update(['is_active' => false]);

        $this->as('Manager')->post('/supplier-payments', [
            'supplier_id' => $this->supplier->id, 'payment_date' => now()->toDateString(), 'payment_method_id' => $card->id, 'amount' => '10', 'allocation' => 'auto',
        ])->assertSessionHasErrors('payment_method_id');
    }

    // ---------------------------------------------------------------- dashboard and integrity

    public function test_the_dashboard_shows_what_is_owed_to_suppliers_only_to_those_who_may_see_financials(): void
    {
        $this->received('10', '100');

        $this->as('Manager')->get('/')->assertOk()->assertSee('Owed to suppliers')->assertSee('1,000.00');
        $this->as('Cashier')->get('/')->assertOk()->assertDontSee('Owed to suppliers');
    }

    public function test_the_integrity_check_passes_on_good_books_and_names_a_tampered_supplier_balance(): void
    {
        $this->received('10', '100');
        $this->assertSame(0, Artisan::call('pos:integrity:check'));

        DB::table('suppliers')->where('id', $this->supplier->id)->update(['balance' => '999.00']);   // somebody edited the database by hand

        $this->assertSame(1, Artisan::call('pos:integrity:check'));
        $this->assertStringContainsString($this->supplier->code, Artisan::output());
    }

    public function test_the_integrity_check_names_a_bill_whose_paid_figure_was_tampered_with(): void
    {
        $bill = $this->received('10', '100');
        DB::table('purchases')->where('id', $bill->id)->update(['paid_amount' => '500.00', 'due_amount' => '500.00', 'payment_status' => 'partial']);

        $this->assertSame(1, Artisan::call('pos:integrity:check'));
        $this->assertStringContainsString($bill->purchase_no, Artisan::output());
    }
}
