<?php

namespace Tests\Feature\Inventory;

use App\Models\Product;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

class LabelTest extends TestCase
{
    use ActsAsRoles;
    use MakesStock;
    use RefreshesSeededDatabase;

    public function test_the_selection_page_lists_stock_items_and_searches(): void
    {
        $this->as('Manager');
        $this->stocked('4', '1', ['name' => 'Labelled toner', 'barcode' => '4006381333931']);
        Product::factory()->service()->create(['name' => 'A service']);
        Product::factory()->inactive()->create(['name' => 'Inactive thing']);

        $this->get('/inventory/labels')->assertOk()->assertSee('Labelled toner')->assertDontSee('A service')->assertDontSee('Inactive thing');
        $this->get('/inventory/labels?q=4006381333931')->assertSee('Labelled toner');
        $this->get('/inventory/labels?q=zzzz')->assertSee('No products found');
    }

    public function test_the_print_page_makes_one_code_128_label_per_copy_using_the_barcode_or_the_sku(): void
    {
        $this->as('Manager');
        $withBarcode = Product::factory()->create(['name' => 'Has a barcode', 'sku' => 'S-1', 'barcode' => '4006381333931', 'sale_price' => '1250.00']);
        $skuOnly = Product::factory()->create(['name' => 'SKU only', 'sku' => 'S-2']);

        $html = $this->get('/inventory/labels/print?'.http_build_query(['items' => [$withBarcode->id => 3, $skuOnly->id => 1], 'size' => '50x30', 'show_price' => 1]))
            ->assertOk()->getContent();

        $this->assertSame(4, substr_count($html, '<div class="label">'));
        $this->assertSame(4, substr_count($html, '<svg'), 'Every label carries a barcode image.');
        $this->assertStringContainsString('4006381333931', $html);
        $this->assertStringContainsString('S-2', $html, 'A product with no barcode prints its SKU.');
        $this->assertStringContainsString('Rs. 1250', $html);
        $this->assertStringContainsString('Print 4 label(s)', $html);
    }

    public function test_the_price_is_only_printed_when_asked(): void
    {
        $this->as('Manager');
        $p = Product::factory()->create(['sale_price' => '777.00']);

        $this->get('/inventory/labels/print?'.http_build_query(['items' => [$p->id => 1], 'size' => '38x25']))->assertOk()->assertDontSee('777');
    }

    public function test_the_label_never_shows_a_cost(): void
    {
        $this->as('Manager');
        $p = $this->stocked('5', '4321.00', ['sale_price' => '5000']);

        $this->get('/inventory/labels/print?'.http_build_query(['items' => [$p->id => 1], 'size' => '50x30', 'show_price' => 1]))->assertOk()->assertDontSee('4321');
    }

    public function test_a_code_that_cannot_be_a_barcode_is_skipped_with_an_explanation(): void
    {
        $this->as('Manager');
        $bad = Product::factory()->create(['name' => 'Accent', 'sku' => 'ÄÖÜ-é', 'barcode' => 'ÄÖÜ-é']);
        $good = Product::factory()->create(['name' => 'Plain', 'sku' => 'PLAIN-1']);

        $html = $this->get('/inventory/labels/print?'.http_build_query(['items' => [$bad->id => 1, $good->id => 2], 'size' => '50x30']))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, '<div class="label">'));
        $this->assertStringContainsString('Skipped', $html);
        $this->assertStringContainsString('cannot be printed as a barcode', $html);
    }

    public function test_every_size_renders_and_the_a4_sheet_is_a_grid(): void
    {
        $this->as('Manager');
        $p = Product::factory()->create();

        foreach (['50x30', '38x25', 'a4-3x8'] as $size) {
            $this->get('/inventory/labels/print?'.http_build_query(['items' => [$p->id => 2], 'size' => $size]))->assertOk()->assertSee('@page', false);
        }
        $this->get('/inventory/labels/print?'.http_build_query(['items' => [$p->id => 2], 'size' => 'a4-3x8']))->assertSee('size: A4', false);
    }

    public function test_bad_requests_are_refused(): void
    {
        $this->as('Manager');
        $p = Product::factory()->create();

        $this->get('/inventory/labels/print')->assertStatus(302);                                                                    // nothing chosen
        $this->get('/inventory/labels/print?'.http_build_query(['items' => [$p->id => 0], 'size' => '50x30']))->assertStatus(422);   // all zero
        $this->get('/inventory/labels/print?'.http_build_query(['items' => [$p->id => 5], 'size' => 'giant']))->assertStatus(302);   // unknown size
        $this->get('/inventory/labels/print?'.http_build_query(['items' => [$p->id => 501], 'size' => '50x30']))->assertStatus(302); // too many of one
    }

    public function test_at_most_a_thousand_labels_at_once(): void
    {
        $this->as('Manager');
        $items = [];
        foreach (Product::factory()->count(3)->create() as $p) {
            $items[$p->id] = 400;
        }

        $this->get('/inventory/labels/print?'.http_build_query(['items' => $items, 'size' => '50x30']))->assertStatus(422);
    }

    public function test_cashiers_may_print_labels_but_guests_may_not(): void
    {
        $p = Product::factory()->create();
        $url = '/inventory/labels/print?'.http_build_query(['items' => [$p->id => 1], 'size' => '50x30']);

        $this->as('Cashier')->get($url)->assertOk();
        $this->asWithOnly(['inventory.view'])->get($url)->assertForbidden();
        auth()->logout();
        $this->get($url)->assertRedirect('/login');
    }
}
