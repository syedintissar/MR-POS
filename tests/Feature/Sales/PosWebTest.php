<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\VoidSale;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\HeldSale;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/** The till and the sales screens, driven the way the browser drives them. */
class PosWebTest extends TestCase
{
    use ActsAsRoles;
    use MakesStock;
    use RefreshesSeededDatabase;

    private function method(string $code = 'cash'): PaymentMethod
    {
        return PaymentMethod::query()->where('code', $code)->firstOrFail();
    }

    private function item(string $price = '150', string $qty = '10', string $cost = '777.77', array $attrs = []): Product
    {
        return $this->stocked($qty, $cost, ['sale_price' => $price] + $attrs);
    }

    private function payload(Product $p, array $over = []): array
    {
        return $over + [
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['product_id' => $p->id, 'quantity' => '2']],
            'payments' => [['payment_method_id' => $this->method()->id, 'amount' => '500']],
        ];
    }

    private function sale(User $by, ?Product $p = null, string $qty = '1'): Sale
    {
        return app(CompleteSale::class)->execute([
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['product_id' => ($p ?? $this->item())->id, 'quantity' => $qty]],
            'payments' => [['payment_method_id' => $this->method()->id, 'amount' => '100000']],
        ], $by);
    }

    // ---------------------------------------------------------------- pages

    public function test_the_till_and_the_sales_pages_open(): void
    {
        $manager = User::factory()->withRole('Manager')->create();
        $sale = $this->sale($manager);
        $this->actingAs($manager);

        $this->get('/pos')->assertOk()->assertSee('Scan a barcode')->assertSee('Pay (F9)');
        $this->get('/sales')->assertOk()->assertSee($sale->invoice_no);
        $this->get('/sales?status=voided&q=INV&from=2020-01-01&to=2099-01-01&method='.$this->method()->id)->assertOk();
        $this->get("/sales/{$sale->id}")->assertOk()->assertSee($sale->invoice_no)->assertSee('Payments');
    }

    public function test_the_till_screen_gets_the_shops_settings_and_what_the_user_may_do(): void
    {
        app(SettingsService::class)->set('tax.enabled', true);
        app(SettingsService::class)->set('sales.round_to', '5');

        // the settings travel as JSON.parse('...') inside an attribute: quotes are written as \u0022 and then HTML-escaped
        $html = str_replace('\u0022', '"', html_entity_decode($this->as('Cashier')->get('/pos')->assertOk()->getContent()));

        $this->assertStringContainsString('"taxEnabled":true', $html);
        $this->assertStringContainsString('"roundTo":5', $html);
        $this->assertStringContainsString('"changePrice":false', $html, 'a cashier cannot change prices without a manager');
        $this->assertStringContainsString('"discountUnlimited":false', $html);
        $this->assertStringContainsString('"hold":true', $html);
    }

    // ---------------------------------------------------------------- product search

    public function test_the_search_finds_by_name_sku_barcode_and_model_and_exact_codes_come_first(): void
    {
        $a = $this->item('150', '5', '100', ['name' => 'Brother TN-2480 toner', 'sku' => 'TN2480', 'barcode' => '6001234', 'model' => 'HL-L2375']);
        $b = $this->item('90', '5', '50', ['name' => 'TN2480 compatible', 'sku' => 'COMP-1']);
        $this->as('Cashier');

        foreach (['toner', 'TN2480', '6001234', 'HL-L2375'] as $term) {
            $this->assertContains($a->id, collect($this->getJson('/pos/products/search?q='.urlencode($term))->assertOk()->json())->pluck('id')->all(), "term {$term}");
        }
        $rows = $this->getJson('/pos/products/search?q=TN2480')->json();
        $this->assertSame($a->id, $rows[0]['id'], 'The exact SKU is listed before the name match.');
        $this->assertSame([], $this->getJson('/pos/products/search')->json());
        $this->assertSame([], $this->getJson('/pos/products/search?q=')->json());
    }

    public function test_the_search_hides_cost_and_the_lowest_price_from_everyone(): void
    {
        $p = $this->item('150', '5', '777.77', ['min_sale_price' => '123.45', 'name' => 'Secret toner']);

        foreach (['Cashier', 'Manager', 'Admin'] as $role) {
            $json = $this->as($role)->getJson('/pos/products/search?q=Secret')->assertOk()->getContent();
            $this->assertStringNotContainsString('777', $json, "{$role} must not get the cost");
            $this->assertStringNotContainsString('123.45', $json, "{$role} must not get the lowest price");
            foreach (['cost', 'min_sale_price', 'min_price', 'average_cost', 'last_purchase_cost'] as $field) {
                $this->assertStringNotContainsString($field, $json);
            }
            $this->assertStringContainsString('150.00', $json);
        }
        $this->assertNotNull($p);
    }

    public function test_the_search_lists_services_but_not_inactive_products_and_can_browse_a_category(): void
    {
        $category = Category::query()->whereNull('parent_id')->where('name', 'Other')->firstOrFail();
        $service = Product::factory()->service()->create(['name' => 'Toner refill counter', 'sale_price' => '800']);
        $inactive = $this->item('10', '5', '5', ['name' => 'Toner old', 'is_active' => false]);
        $this->as('Cashier');

        $names = collect($this->getJson('/pos/products/search?q=Toner')->json())->pluck('name')->all();
        $this->assertContains('Toner refill counter', $names);
        $this->assertNotContains('Toner old', $names);

        $row = collect($this->getJson('/pos/products/search?q=refill')->json())->firstWhere('id', $service->id);
        $this->assertTrue($row['is_service']);
        $this->assertNull($row['stock']);

        $ids = collect($this->getJson("/pos/products/search?category={$category->id}")->json())->pluck('id')->all();
        $this->assertContains($service->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_the_search_can_return_todays_prices_for_given_products(): void
    {
        $a = $this->item('150');
        $b = $this->item('90');
        $this->as('Cashier');

        $rows = $this->getJson("/pos/products/search?ids={$a->id},{$b->id},999999")->assertOk()->json();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], collect($rows)->pluck('id')->all());
    }

    public function test_the_tax_rate_in_the_search_follows_the_settings(): void
    {
        $p = $this->item('1000', '5', '100', ['tax_rate' => '5', 'name' => 'Taxed thing']);
        $q = $this->item('1000', '5', '100', ['name' => 'Taxed other']);
        $this->as('Cashier');
        $rate = fn (Product $x) => collect($this->getJson('/pos/products/search?q=Taxed')->json())->firstWhere('id', $x->id)['tax_rate'];

        $this->assertSame('0', $rate($p), 'tax is off by default');

        app(SettingsService::class)->set('tax.enabled', true);
        app(SettingsService::class)->set('tax.default_rate', '17');
        $this->assertSame('5.00', $rate($p));
        $this->assertSame('17.00', $rate($q), 'a product without its own rate uses the default');
    }

    public function test_quick_picks_show_the_fast_movers_first(): void
    {
        $slow = $this->item('100', '50', '10', ['name' => 'Slow seller']);
        $fast = $this->item('100', '50', '10', ['name' => 'Fast seller']);
        $cashier = User::factory()->withRole('Cashier')->create();
        $this->sale($cashier, $slow, '1');
        $this->sale($cashier, $fast, '5');

        $rows = $this->actingAs($cashier)->getJson('/pos/products/quick')->assertOk()->json();

        $this->assertSame($fast->id, $rows[0]['id']);
        $this->assertSame($slow->id, $rows[1]['id']);
    }

    // ---------------------------------------------------------------- completing a sale

    public function test_completing_a_sale_over_http_returns_the_invoice_and_signed_print_links(): void
    {
        $p = $this->item('150', '10');
        $this->as('Cashier');

        $r = $this->postJson('/pos/sales', $this->payload($p))->assertCreated();

        $r->assertJsonStructure(['sale_id', 'invoice_no', 'total', 'paid', 'change', 'tendered', 'replayed', 'print' => ['thermal', 'a4'], 'view']);
        $this->assertSame('INV-000001', $r->json('invoice_no'));
        $this->assertSame('300.00', $r->json('total'));
        $this->assertSame('200.00', $r->json('change'));
        $this->assertFalse($r->json('replayed'));
        $this->assertSame('8.000', $p->fresh()->current_stock);

        // the original print works through its signed link
        $this->get($r->json('print.thermal'))->assertOk()->assertSee('INV-000001')->assertDontSee('DUPLICATE');
        $this->get($r->json('print.a4'))->assertOk()->assertSee('INV-000001')->assertDontSee('DUPLICATE');
    }

    public function test_pressing_complete_twice_returns_the_same_sale_with_a_200(): void
    {
        $p = $this->item('150', '10');
        $this->as('Cashier');
        $data = $this->payload($p);

        $first = $this->postJson('/pos/sales', $data)->assertCreated();
        $second = $this->postJson('/pos/sales', $data)->assertOk();

        $this->assertSame($first->json('sale_id'), $second->json('sale_id'));
        $this->assertTrue($second->json('replayed'));
        $this->assertSame(1, Sale::query()->count());
        $this->assertSame('8.000', $p->fresh()->current_stock);
    }

    public function test_the_server_refuses_bad_input_without_saving_anything(): void
    {
        $p = $this->item('150', '10');
        $this->as('Cashier');
        $base = $this->payload($p);
        $bad = [
            'no key' => array_diff_key($base, ['idempotency_key' => 1]),
            'bad key' => ['idempotency_key' => 'abc'] + $base,
            'no lines' => ['lines' => []] + $base,
            'zero qty' => ['lines' => [['product_id' => $p->id, 'quantity' => '0']]] + $base,
            'four decimals' => ['lines' => [['product_id' => $p->id, 'quantity' => '1.0001']]] + $base,
            'negative price' => ['lines' => [['product_id' => $p->id, 'quantity' => '1', 'unit_price' => '-1']]] + $base,
            'price decimals' => ['lines' => [['product_id' => $p->id, 'quantity' => '1', 'unit_price' => '1.234']]] + $base,
            'discount over 100' => ['lines' => [['product_id' => $p->id, 'quantity' => '1', 'discount_percent' => '101']]] + $base,
            'exponent' => ['lines' => [['product_id' => $p->id, 'quantity' => '1e1']]] + $base,
            'seven payments' => ['payments' => array_fill(0, 7, ['payment_method_id' => $this->method()->id, 'amount' => '50'])] + $base,
            'tier' => ['price_tier' => 'vip'] + $base,
        ];

        foreach ($bad as $label => $data) {
            $this->postJson('/pos/sales', $data)->assertStatus(422);
            $this->assertSame(0, Sale::query()->count(), "Saved despite: {$label}");
        }
        $this->assertSame('10.000', $p->fresh()->current_stock);
    }

    public function test_business_rule_failures_come_back_as_readable_json(): void
    {
        $scarce = $this->item('150', '1');
        $this->as('Cashier');

        $r = $this->postJson('/pos/sales', $this->payload($scarce, ['lines' => [['product_id' => $scarce->id, 'quantity' => '3']]]))->assertStatus(422);
        $this->assertSame('insufficient_stock', $r->json('error'));
        $this->assertStringContainsString($scarce->name, $r->json('message'));

        $r = $this->postJson('/pos/sales', $this->payload($scarce, ['payments' => [['payment_method_id' => $this->method()->id, 'amount' => '10']]]))->assertStatus(422);
        $this->assertSame('invalid', $r->json('error'));
        $this->assertStringContainsString('pay in full', $r->json('message'));

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_a_total_that_differs_from_the_screen_is_reported_not_charged(): void
    {
        $p = $this->item('150', '10');
        $this->as('Cashier');

        $r = $this->postJson('/pos/sales', $this->payload($p, ['expected_total' => '250.00']))->assertStatus(409);

        $this->assertSame('total_mismatch', $r->json('error'));
        $this->assertSame('300.00', $r->json('server_total'));
        $this->assertSame(0, Sale::query()->count());
    }

    public function test_a_discount_above_the_limit_asks_for_approval_and_a_managers_login_lets_it_through(): void
    {
        $p = $this->item('1000', '10');
        $manager = User::factory()->withRole('Manager')->create(['username' => 'themanager']);
        $this->as('Cashier');
        $data = $this->payload($p, ['lines' => [['product_id' => $p->id, 'quantity' => '1', 'discount_percent' => '10']], 'payments' => [['payment_method_id' => $this->method()->id, 'amount' => '1000']]]);

        $r = $this->postJson('/pos/sales', $data)->assertStatus(409);
        $this->assertSame('approval_required', $r->json('error'));
        $this->assertSame('discount_limit', $r->json('reasons.0.code'));
        $this->assertSame(0, Sale::query()->count());

        $this->postJson('/pos/approvals', ['username' => 'themanager', 'password' => 'wrong'])->assertStatus(422);
        $grant = $this->postJson('/pos/approvals', ['username' => 'themanager', 'password' => 'password'])->assertOk();
        $this->assertSame($manager->name, $grant->json('approver'));

        $done = $this->postJson('/pos/sales', $data + ['approval_token' => $grant->json('token')])->assertCreated();
        $this->assertSame('900.00', $done->json('total'));
        $this->assertSame($manager->id, Sale::query()->sole()->approved_by);

        // used once
        $again = $this->postJson('/pos/sales', array_merge($data, ['idempotency_key' => (string) Str::uuid(), 'approval_token' => $grant->json('token')]));
        $again->assertStatus(409);
    }

    public function test_the_approval_dialog_does_not_work_for_accounts_that_may_not_approve(): void
    {
        User::factory()->withRole('Cashier')->create(['username' => 'othercashier']);
        $this->as('Cashier');

        $this->postJson('/pos/approvals', ['username' => 'othercashier', 'password' => 'password'])->assertStatus(422);
        $this->assertSame(1, AuditLog::query()->where('event', 'pos_approval_failed')->count());
    }

    public function test_someone_who_may_not_sell_gets_a_403(): void
    {
        $p = $this->item();
        $this->as('Technician');

        $this->postJson('/pos/sales', $this->payload($p))->assertForbidden();
        $this->getJson('/pos/products/search?q=a')->assertForbidden();
        $this->post('/pos/approvals', ['username' => 'a', 'password' => 'b'])->assertForbidden();
    }

    // ---------------------------------------------------------------- prints

    public function test_the_signed_print_link_cannot_be_forged_or_reused_after_it_expires(): void
    {
        $cashier = User::factory()->withRole('Cashier')->create();
        $sale = $this->sale($cashier);
        $this->actingAs($cashier);

        $this->get("/sales/{$sale->id}/print/thermal")->assertForbidden();                        // no signature
        $this->get("/sales/{$sale->id}/print/thermal?signature=forged")->assertForbidden();

        $signed = URL::temporarySignedRoute('sales.print', now()->addMinutes(10), ['sale' => $sale->id, 'format' => 'thermal']);
        $this->get($signed)->assertOk();
        $this->get(str_replace('/thermal', '/a4', $signed))->assertForbidden();                  // a signature is for one format only

        $this->travel(11)->minutes();
        $this->get($signed)->assertForbidden();
    }

    public function test_a_reprint_is_marked_duplicate_and_audited_and_a_voided_sale_prints_void(): void
    {
        $cashier = User::factory()->withRole('Cashier')->create();
        $manager = User::factory()->withRole('Manager')->create();
        $sale = $this->sale($cashier);
        $this->actingAs($cashier);

        $this->get("/sales/{$sale->id}/reprint/thermal")->assertOk()->assertSee('DUPLICATE')->assertSee($sale->invoice_no);
        $this->get("/sales/{$sale->id}/reprint/a4")->assertOk()->assertSee('DUPLICATE');
        $this->assertSame(2, AuditLog::query()->where('event', 'sale_reprinted')->count());

        app(VoidSale::class)->execute($sale, 'test', $manager);
        $this->get("/sales/{$sale->id}/reprint/thermal")->assertOk()->assertSee('VOID')->assertDontSee('DUPLICATE');
    }

    public function test_the_a4_invoice_can_be_downloaded_as_a_pdf_and_that_is_audited(): void
    {
        $manager = User::factory()->withRole('Manager')->create();
        $p = $this->item('150', '10', '777.77', ['name' => 'Canon ink']);
        $sale = $this->sale($manager, $p, '2');
        $this->actingAs($manager);

        $response = $this->get("/sales/{$sale->id}/pdf")->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString($sale->invoice_no.'.pdf', $response->headers->get('Content-Disposition'));
        $body = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $body);
        $this->assertGreaterThan(2000, strlen($body));
        $this->assertSame(1, AuditLog::query()->where('event', 'sale_reprinted')->where('description', 'like', '%PDF%')->count());

        // a cashier may download only their own invoices
        $cashier = User::factory()->withRole('Cashier')->create();
        $this->actingAs($cashier)->get("/sales/{$sale->id}/pdf")->assertForbidden();
        $mine = $this->sale($cashier);
        $this->get("/sales/{$mine->id}/pdf")->assertOk();
    }

    public function test_the_receipt_shows_the_sale_the_way_the_customer_expects_and_never_the_cost(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('shop.name', 'Mr Printer & Tuner');
        $settings->set('shop.phone', '0300-1112223');
        $settings->set('receipt.footer', 'Thank you, come again!');
        $settings->set('printing.paper_width_mm', '80');
        $settings->set('printing.copies', 2);
        $p = $this->item('150', '10', '777.77', ['name' => 'Canon ink', 'warranty_months' => 6]);
        $manager = User::factory()->withRole('Manager')->create();
        $sale = app(CompleteSale::class)->execute([
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['product_id' => $p->id, 'quantity' => '2', 'serial_number' => 'SN-42']],
            'payments' => [['payment_method_id' => $this->method()->id, 'amount' => '500']],
            'notes' => 'For the office', 'print_notes' => true,
        ], $manager);
        $this->actingAs($manager);

        foreach (['thermal', 'a4'] as $format) {
            $html = $this->get("/sales/{$sale->id}/reprint/{$format}")->assertOk()->getContent();
            foreach (['Mr Printer &amp; Tuner', '0300-1112223', $sale->invoice_no, 'Canon ink', '300.00', 'Thank you, come again!', 'SN-42', 'For the office', 'Cash'] as $needle) {
                $this->assertStringContainsString($needle, $html, "{$format} receipt lacks {$needle}");
            }
            $this->assertStringContainsString('<svg', $html, 'the invoice number is also a barcode');
            $this->assertStringNotContainsString('777', $html, 'the cost never appears on a customer document');
        }

        $thermal = $this->get("/sales/{$sale->id}/reprint/thermal")->getContent();
        $this->assertSame(2, substr_count($thermal, 'class="copy"'), 'two copies were asked for in the settings');
        $this->assertStringContainsString('size: 80mm auto', $thermal);
        $this->assertStringContainsString('500.00', $thermal);
        $this->assertStringContainsString('200.00', $thermal, 'the change');
    }

    public function test_tax_appears_on_the_documents_only_when_there_is_tax(): void
    {
        $cashier = User::factory()->withRole('Cashier')->create();
        $plain = $this->sale($cashier);
        $this->actingAs($cashier);
        $this->get("/sales/{$plain->id}/reprint/a4")->assertOk()->assertDontSee('GST');

        $settings = app(SettingsService::class);
        $settings->set('tax.enabled', true);
        $settings->set('tax.default_rate', '17');
        $taxed = $this->sale($cashier, $this->item('1000'));
        $this->get("/sales/{$taxed->id}/reprint/a4")->assertOk()->assertSee('GST INVOICE')->assertSee('170.00');
    }

    // ---------------------------------------------------------------- the sales list and who may see what

    public function test_a_cashier_sees_only_their_own_sales_and_a_manager_sees_all(): void
    {
        $one = User::factory()->withRole('Cashier')->create();
        $two = User::factory()->withRole('Cashier')->create();
        $manager = User::factory()->withRole('Manager')->create();
        $mine = $this->sale($one);
        $theirs = $this->sale($two);

        $this->actingAs($one)->get('/sales')->assertOk()->assertSee($mine->invoice_no)->assertDontSee($theirs->invoice_no);
        $this->get("/sales/{$mine->id}")->assertOk();
        $this->get("/sales/{$theirs->id}")->assertForbidden();
        $this->get("/sales/{$theirs->id}/reprint/thermal")->assertForbidden();
        $this->get("/sales?cashier={$two->id}")->assertOk()->assertDontSee($theirs->invoice_no);   // the filter is ignored for own-only users

        $this->actingAs($manager)->get('/sales')->assertOk()->assertSee($mine->invoice_no)->assertSee($theirs->invoice_no);
        $this->get("/sales?cashier={$two->id}")->assertOk()->assertDontSee($mine->invoice_no)->assertSee($theirs->invoice_no);
    }

    public function test_cost_and_profit_are_shown_on_an_invoice_only_to_those_who_may_see_costs(): void
    {
        $p = $this->item('1000', '10', '600.55');
        $cashier = User::factory()->withRole('Cashier')->create();
        $sale = $this->sale($cashier, $p);

        $this->actingAs($cashier)->get("/sales/{$sale->id}")->assertOk()->assertDontSee('600.55')->assertDontSee('Gross profit')->assertDontSee('Profit');
        $this->as('Manager')->get("/sales/{$sale->id}")->assertOk()->assertSee('600.55')->assertSee('Gross profit')->assertSee('399.45');
    }

    // ---------------------------------------------------------------- cancelling

    public function test_cancelling_a_sale_over_http_needs_the_password_and_a_reason(): void
    {
        $p = $this->item('150', '10');
        $cashier = User::factory()->withRole('Cashier')->create();
        $sale = $this->sale($cashier, $p, '2');
        $this->as('Manager');

        $this->post("/sales/{$sale->id}/void", ['reason' => 'x'])->assertRedirect(route('password.confirm'));
        $this->assertSame('completed', $sale->fresh()->status);

        $this->withSession($this->confirmed())->post("/sales/{$sale->id}/void", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->withSession($this->confirmed())->post("/sales/{$sale->id}/void", ['reason' => 'Wrong item'])->assertRedirect("/sales/{$sale->id}")->assertSessionHas('success');

        $this->assertSame('voided', $sale->fresh()->status);
        $this->assertSame('10.000', $p->fresh()->current_stock);
        $this->get("/sales/{$sale->id}")->assertOk()->assertSee('Cancelled')->assertSee('Wrong item');
    }

    public function test_a_cashier_cannot_cancel_a_sale(): void
    {
        $cashier = User::factory()->withRole('Cashier')->create();
        $sale = $this->sale($cashier);

        $this->actingAs($cashier)->withSession($this->confirmed())->post("/sales/{$sale->id}/void", ['reason' => 'x'])->assertForbidden();
        $this->assertSame('completed', $sale->fresh()->status);
    }

    // ---------------------------------------------------------------- dashboard and integrity

    public function test_the_dashboard_shows_todays_sales_to_those_who_may_see_sales_and_cashiers_only_their_own(): void
    {
        $one = User::factory()->withRole('Cashier')->create();
        $two = User::factory()->withRole('Cashier')->create();
        $this->sale($one, $this->item('150'), '2');   // 300
        $this->sale($two, $this->item('100'), '1');   // 100

        $this->actingAs($one)->get('/')->assertOk()->assertSee('Your sales today')->assertSee('300.00')->assertDontSee('400.00');
        $this->as('Manager')->get('/')->assertOk()->assertSee('Sales today')->assertSee('400.00')->assertDontSee('Your sales today');
        $this->as('Technician')->get('/')->assertOk()->assertDontSee('Sales today')->assertDontSee('Your sales today');
    }

    public function test_the_integrity_command_covers_invoices(): void
    {
        $cashier = User::factory()->withRole('Cashier')->create();
        $sale = $this->sale($cashier, $this->item('150'), '2');
        $this->assertSame(0, Artisan::call('pos:integrity:check'));

        DB::table('sales')->where('id', $sale->id)->update(['grand_total' => '999.00']);   // somebody edited the database by hand

        $this->assertSame(1, Artisan::call('pos:integrity:check'));
        $this->assertStringContainsString($sale->invoice_no, Artisan::output());
    }

    // ---------------------------------------------------------------- held carts

    public function test_a_cart_can_be_held_listed_resumed_and_discarded(): void
    {
        $p = $this->item('150', '10');
        $cashier = User::factory()->withRole('Cashier')->create();
        $cart = ['lines' => [['id' => $p->id, 'qty' => '2', 'price' => '150.00']], 'tier' => 'retail', 'meta' => ['total' => '300.00']];
        $this->actingAs($cashier);

        $id = $this->postJson('/pos/held', ['label' => 'Mr Ali', 'cart' => $cart])->assertCreated()->json('id');
        $list = $this->getJson('/pos/held')->assertOk()->json();
        $this->assertSame([['id' => $id, 'label' => 'Mr Ali', 'lines' => 1, 'total' => '300.00']], array_map(fn ($h) => array_intersect_key($h, array_flip(['id', 'label', 'lines', 'total'])), $list));

        $p->update(['sale_price' => '170']);
        $resumed = $this->getJson("/pos/held/{$id}")->assertOk()->json();
        $this->assertSame('150.00', $resumed['cart']['lines'][0]['price'], 'what was saved');
        $this->assertSame('170.00', $resumed['products'][0]['price'], "today's price, so the screen can warn");
        $this->assertTrue($resumed['products'][0]['available']);

        $this->deleteJson("/pos/held/{$id}")->assertOk();
        $this->assertSame(0, HeldSale::query()->count());
        $this->assertSame(0, Sale::query()->count(), 'A held cart is not a sale.');
        $this->assertSame(0, StockMovement::query()->where('movement_type', 'sale')->count(), 'and it reserves no stock');
    }

    public function test_held_carts_belong_to_the_cashier_who_held_them(): void
    {
        $p = $this->item();
        $one = User::factory()->withRole('Cashier')->create();
        $two = User::factory()->withRole('Cashier')->create();
        $this->actingAs($one);
        $id = $this->postJson('/pos/held', ['cart' => ['lines' => [['id' => $p->id, 'qty' => '1']]]])->assertCreated()->json('id');

        $this->actingAs($two);
        $this->assertSame([], $this->getJson('/pos/held')->json());
        $this->getJson("/pos/held/{$id}")->assertForbidden();
        $this->deleteJson("/pos/held/{$id}")->assertForbidden();
        $this->assertSame(1, HeldSale::query()->count());
    }

    public function test_a_held_cart_needs_lines_and_there_is_a_limit(): void
    {
        $p = $this->item();
        $cashier = User::factory()->withRole('Cashier')->create();
        $this->actingAs($cashier);

        $this->postJson('/pos/held', ['cart' => ['lines' => []]])->assertStatus(422);
        $this->postJson('/pos/held', ['cart' => []])->assertStatus(422);

        for ($i = 0; $i < 30; $i++) {
            HeldSale::query()->create(['user_id' => $cashier->id, 'label' => "c{$i}", 'cart' => ['lines' => [['id' => $p->id, 'qty' => '1']]]]);
        }
        $this->postJson('/pos/held', ['cart' => ['lines' => [['id' => $p->id, 'qty' => '1']]]])->assertStatus(422)->assertJsonPath('error', 'too_many');
    }

    public function test_a_technician_cannot_hold_carts(): void
    {
        $this->as('Technician');

        $this->getJson('/pos/held')->assertForbidden();
        $this->postJson('/pos/held', ['cart' => ['lines' => [['id' => 1, 'qty' => '1']]]])->assertForbidden();
    }
}
