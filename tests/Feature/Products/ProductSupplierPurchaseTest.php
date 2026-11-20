<?php

namespace Tests\Feature\Products;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Unit;
use App\Services\CashBook\MoneyAccountService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/**
 * A new product whose stock was bought from a supplier: the product, a received purchase bill and its payment are saved together
 * (or nothing at all), the supplier's account, the stock, the average cost and the cash book all follow.
 */
class ProductSupplierPurchaseTest extends TestCase
{
    use ActsAsRoles;
    use RefreshesSeededDatabase;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplier = Supplier::factory()->create(['name' => 'Toner Traders', 'is_active' => true]);
    }

    private function method(string $code): PaymentMethod
    {
        return PaymentMethod::query()->where('code', $code)->firstOrFail();
    }

    private function product(array $over = []): array
    {
        return array_merge([
            'type' => 'stock', 'name' => 'HP 85A Toner', 'category_id' => Category::query()->value('id'),
            'unit_id' => Unit::query()->where('short_name', 'pcs')->value('id'), 'sale_price' => '4500', 'is_active' => 1,
            'buy_from_supplier' => 1, 'purchase_supplier_id' => $this->supplier->id, 'opening_stock' => '10', 'opening_cost' => '3000',
        ], $over);
    }

    private function supplierBalance(): string
    {
        return (string) $this->supplier->fresh()->getRawOriginal('balance');
    }

    private function nothingSaved(): void
    {
        $this->assertSame(0, Product::query()->count(), 'no product');
        $this->assertSame(0, Purchase::query()->count(), 'no bill');
        $this->assertSame(0, SupplierPayment::query()->count(), 'no payment');
        $this->assertSame('0.00', $this->supplierBalance(), 'the supplier is owed nothing');
    }

    public function test_bought_on_credit_the_bill_goes_on_the_suppliers_account_with_a_date_to_pay_by(): void
    {
        $this->as('Manager');
        $due = now()->addDays(5)->toDateString();   // within the week the dashboard looks ahead

        $this->get('/products/create')->assertOk()->assertSee('Bought from a supplier?')->assertSee('+ New supplier')->assertSee('Toner Traders');
        $this->post('/products', $this->product(['pay_mode' => 'credit', 'pay_amount' => '', 'due_date' => $due, 'supplier_invoice_no' => 'TT-991']))
            ->assertSessionHasNoErrors()->assertSessionHas('success', fn ($m) => str_contains($m, 'bought from Toner Traders') && str_contains($m, 'on credit 30000'));

        $product = Product::query()->sole();
        $bill = Purchase::query()->with('items')->sole();
        $this->assertSame('received', $bill->status);
        $this->assertSame($this->supplier->id, $bill->supplier_id);
        $this->assertSame('TT-991', $bill->supplier_invoice_no);
        $this->assertSame('30000.00', (string) $bill->grand_total);
        $this->assertSame('0.00', (string) $bill->paid_amount);
        $this->assertSame('30000.00', (string) $bill->due_amount);
        $this->assertSame('unpaid', $bill->payment_status);
        $this->assertSame($due, $bill->due_date->toDateString());
        $this->assertSame($product->id, $bill->items->sole()->product_id);

        $this->assertSame('10.000', (string) $product->current_stock);
        $this->assertSame('3000.0000', (string) $product->average_cost);
        $this->assertSame($this->supplier->id, $product->default_supplier_id, 'the supplier becomes the usual one');
        $this->assertSame('30000.00', $this->supplierBalance());
        $this->assertSame(0, StockAdjustment::query()->count(), 'the stock came in through the bill, not as an opening count');
        $this->assertSame(0, SupplierPayment::query()->count());

        $this->get("/purchases/{$bill->id}")->assertOk()->assertSee('Toner Traders')->assertSee('HP 85A Toner');
        $this->get('/')->assertOk()->assertSee('Payments to make to suppliers')->assertSee($bill->purchase_no);
        $this->assertSame(0, Artisan::call('pos:integrity:check'), 'stock, bills and the supplier account agree');
    }

    public function test_paid_in_full_in_cash_the_bill_is_paid_the_drawer_pays_out_and_no_reminder_is_kept(): void
    {
        $this->as('Manager');
        $cash = $this->method('cash');
        $accounts = app(MoneyAccountService::class);
        $before = $accounts->balance((int) $cash->money_account_id);

        $this->post('/products', $this->product(['pay_mode' => 'full', 'pay_amount' => '30000', 'pay_method_id' => $cash->id, 'due_date' => now()->addDay()->toDateString()]))
            ->assertSessionHasNoErrors()->assertSessionHas('success', fn ($m) => str_contains($m, 'paid 30000') && str_contains($m, 'on credit 0'));

        $bill = Purchase::query()->sole();
        $this->assertSame('30000.00', (string) $bill->paid_amount);
        $this->assertSame('0.00', (string) $bill->due_amount);
        $this->assertSame('paid', $bill->payment_status);
        $this->assertNull($bill->due_date, 'nothing left to pay, so no reminder date');
        $payment = SupplierPayment::query()->sole();
        $this->assertSame('30000.00', (string) $payment->amount);
        $this->assertSame($cash->id, $payment->payment_method_id);
        $this->assertSame('0.00', $this->supplierBalance());
        $this->assertSame(bcsub($before, '30000.00', 2), $accounts->balance((int) $cash->money_account_id), 'the cash book shows the money paid out');
        $this->assertSame(0, Artisan::call('pos:integrity:check'));
    }

    public function test_paid_part_by_easypaisa_needs_its_reference_and_leaves_the_rest_on_credit(): void
    {
        $this->as('Manager');
        $easypaisa = $this->method('easypaisa');

        // Easypaisa needs the transaction number: refused, and nothing at all is saved
        $this->post('/products', $this->product(['pay_mode' => 'part', 'pay_amount' => '12000', 'pay_method_id' => $easypaisa->id]))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'reference'));
        $this->nothingSaved();

        $this->post('/products', $this->product(['pay_mode' => 'part', 'pay_amount' => '12000', 'pay_method_id' => $easypaisa->id, 'pay_reference' => 'EP-55667']))
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $bill = Purchase::query()->sole();
        $this->assertSame('12000.00', (string) $bill->paid_amount);
        $this->assertSame('18000.00', (string) $bill->due_amount);
        $this->assertSame('partial', $bill->payment_status);
        $this->assertSame('EP-55667', SupplierPayment::query()->sole()->reference_no);
        $this->assertSame('18000.00', $this->supplierBalance());
        $this->assertSame(0, Artisan::call('pos:integrity:check'));
    }

    public function test_every_refusal_saves_nothing_not_even_the_picture(): void
    {
        $this->as('Manager');
        $cash = $this->method('cash');
        $pictures = fn () => count(File::glob(config('mrpos.files_path').DIRECTORY_SEPARATOR.'products'.DIRECTORY_SEPARATOR.'*'));

        // paying more than the bill
        $this->post('/products', $this->product(['pay_amount' => '30000.01', 'pay_method_id' => $cash->id, 'image' => UploadedFile::fake()->image('p.png', 300, 300)]))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'more than the purchase total'));
        $this->nothingSaved();
        $this->assertSame(0, $pictures(), 'the picture of a refused product is removed again');

        // what the form must have
        $this->post('/products', $this->product(['purchase_supplier_id' => '']))->assertSessionHasErrors('purchase_supplier_id');
        $this->post('/products', $this->product(['opening_stock' => '0']))->assertSessionHasErrors('opening_stock');
        $this->post('/products', $this->product(['opening_stock' => '2.5']))->assertSessionHasErrors('opening_stock');   // pieces are whole
        $this->post('/products', $this->product(['opening_cost' => '']))->assertSessionHasErrors('opening_cost');
        $this->post('/products', $this->product(['pay_amount' => '500', 'pay_method_id' => '']))->assertSessionHasErrors('pay_method_id');
        $this->post('/products', $this->product(['due_date' => now()->subDay()->toDateString()]))->assertSessionHasErrors('due_date');
        $this->post('/products', $this->product(['pay_amount' => '500', 'pay_method_id' => $this->method('customer_credit')->id]))->assertSessionHasErrors('pay_method_id');
        $inactive = Supplier::factory()->create(['is_active' => false]);
        $this->post('/products', $this->product(['purchase_supplier_id' => $inactive->id]))->assertSessionHasErrors('purchase_supplier_id');
        $this->nothingSaved();
    }

    public function test_without_the_tick_the_stock_is_an_opening_count_as_before(): void
    {
        $this->as('Manager');

        $this->post('/products', $this->product(['buy_from_supplier' => 0, 'opening_stock' => '4', 'opening_cost' => '2500']))->assertSessionHasNoErrors();

        $this->assertSame('4.000', (string) Product::query()->sole()->current_stock);
        $this->assertSame(1, StockAdjustment::query()->count());
        $this->assertSame(0, Purchase::query()->count());
        $this->assertSame('0.00', $this->supplierBalance());
    }

    public function test_only_people_who_may_receive_purchases_and_see_costs_can_buy_from_a_supplier_here(): void
    {
        // may create products and count stock, but not receive purchases
        $this->asWithOnly(['products.view', 'products.create', 'inventory.adjust', 'products.view_cost']);
        $this->get('/products/create')->assertOk()->assertDontSee('Bought from a supplier?');
        $this->post('/products', $this->product())->assertSessionHasErrors('buy_from_supplier');
        $this->nothingSaved();

        // may receive purchases, but not see costs
        $this->asWithOnly(['products.view', 'products.create', 'purchases.create', 'purchases.receive'], 'NoCost');
        $this->post('/products', $this->product())->assertSessionHasErrors('buy_from_supplier');
        $this->nothingSaved();

        // may buy, but not add suppliers: the quick-add button is hidden and refused
        $this->asWithOnly(['products.view', 'products.create', 'purchases.create', 'purchases.receive', 'products.view_cost'], 'Buyer');
        $this->get('/products/create')->assertOk()->assertSee('Bought from a supplier?')->assertDontSee('+ New supplier');
        $this->postJson('/suppliers/quick', ['name' => 'Nope'])->assertForbidden();
        $this->post('/products', $this->product())->assertSessionHasNoErrors();
        $this->assertSame(1, Purchase::query()->count());
    }

    public function test_a_new_supplier_can_be_added_from_the_product_form_and_bought_from_at_once(): void
    {
        $this->as('Manager');

        $r = $this->postJson('/suppliers/quick', ['name' => '  Saddar Wholesale ', 'phone' => '0300-7654321', 'company' => 'Saddar Co'])->assertCreated();
        $this->assertSame('Saddar Wholesale', $r->json('name'));
        $this->assertStringStartsWith('SUP-', $r->json('code'));
        $new = Supplier::query()->findOrFail($r->json('id'));
        $this->assertTrue($new->is_active);
        $this->assertSame('0300-7654321', $new->phone);
        $this->assertTrue(AuditLog::query()->where('event', 'supplier_created')->where('auditable_id', $new->id)->exists());
        $this->postJson('/suppliers/quick', ['name' => ''])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->post('/products', $this->product(['purchase_supplier_id' => $new->id, 'opening_stock' => '3', 'opening_cost' => '1000']))->assertSessionHasNoErrors();
        $this->assertSame('3000.00', (string) $new->fresh()->getRawOriginal('balance'));
        $this->assertSame($new->id, Purchase::query()->sole()->supplier_id);
    }
}
