<?php

namespace Tests\Feature\Products;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\Unit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use ActsAsRoles;
    use MakesStock;
    use RefreshesSeededDatabase;

    private function valid(array $over = []): array
    {
        return array_merge([
            'type' => 'stock',
            'name' => 'HP 85A Toner',
            'category_id' => Category::query()->where('name', 'Toners')->value('id'),
            'unit_id' => Unit::query()->where('short_name', 'pcs')->value('id'),
            'sale_price' => '4500',
            'is_active' => 1,
        ], $over);
    }

    public function test_creating_a_product_generates_the_sku_records_who_made_it_and_audits_it(): void
    {
        $this->as('Manager');
        $user = auth()->user();

        $this->post('/products', $this->valid())->assertRedirect();

        $p = Product::query()->firstOrFail();
        $this->assertSame('P-000001', $p->sku);
        $this->assertSame($user->id, $p->created_by);
        $this->assertSame('0.000', $p->current_stock);
        $this->assertSame('4500.00', $p->sale_price);
        $row = DB::table('audit_logs')->where('event', 'product_created')->latest('id')->first();
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame('product', $row->auditable_type);
        $this->assertStringNotContainsString('average_cost', (string) $row->new_values, 'Cost never goes into a general audit snapshot.');
    }

    public function test_skus_count_up_and_a_typed_sku_is_kept_and_skipped_by_the_counter(): void
    {
        $this->as('Manager');
        Product::factory()->create(['sku' => 'P-000002']);   // already taken by hand

        $this->post('/products', $this->valid(['name' => 'One']));
        $this->post('/products', $this->valid(['name' => 'Two']));
        $this->post('/products', $this->valid(['name' => 'Typed', 'sku' => 'MY-SKU-1']));

        $this->assertSame(['P-000001', 'P-000003', 'MY-SKU-1'], Product::query()->whereIn('name', ['One', 'Two', 'Typed'])->orderBy('id')->pluck('sku')->all());
    }

    public function test_opening_stock_entered_with_the_product_is_posted_through_the_ledger(): void
    {
        $this->as('Manager');

        $this->post('/products', $this->valid(['opening_stock' => '12', 'opening_cost' => '3200']))->assertSessionHasNoErrors();

        $p = Product::query()->firstOrFail();
        $this->assertSame('12.000', $p->current_stock);
        $this->assertSame('3200.0000', $p->average_cost);
        $this->assertSame('3200.0000', $p->last_purchase_cost);
        $m = StockMovement::query()->where('product_id', $p->id)->firstOrFail();
        $this->assertSame('opening', $m->movement_type);
        $this->assertSame('stock_adjustment', $m->source_type);
        $this->assertSame('opening', StockAdjustment::query()->firstOrFail()->reason);
    }

    public function test_a_user_who_may_not_enter_stock_cannot_set_opening_stock(): void
    {
        $this->asWithOnly(['products.view', 'products.create']);

        $this->post('/products', $this->valid(['opening_stock' => '5']))->assertSessionHasErrors('opening_stock');
        $this->assertSame(0, Product::count());
    }

    public function test_someone_who_cannot_see_costs_cannot_type_one_either(): void
    {
        $this->asWithOnly(['products.view', 'products.create', 'inventory.adjust']);

        $this->post('/products', $this->valid(['opening_stock' => '5', 'opening_cost' => '999']))->assertSessionHasNoErrors();

        $p = Product::query()->firstOrFail();
        $this->assertSame('5.000', $p->current_stock);
        $this->assertSame('0.0000', $p->average_cost, 'The typed cost was ignored; a manager can correct it later.');
    }

    public function test_opening_stock_must_respect_the_unit(): void
    {
        $this->as('Manager');

        $this->post('/products', $this->valid(['opening_stock' => '2.5']))->assertSessionHasErrors('opening_stock');   // pieces

        $g = Unit::query()->where('short_name', 'g')->value('id');
        $this->post('/products', $this->valid(['name' => 'Powder', 'unit_id' => $g, 'opening_stock' => '2.5', 'opening_cost' => '4']))->assertSessionHasNoErrors();
        $this->assertSame('2.500', Product::query()->where('name', 'Powder')->value('current_stock'));
    }

    public function test_validation_rules(): void
    {
        $this->as('Manager');
        Product::factory()->create(['sku' => 'TAKEN', 'barcode' => '111222333']);

        $this->post('/products', $this->valid(['name' => '']))->assertSessionHasErrors('name');
        $this->post('/products', $this->valid(['sku' => 'TAKEN']))->assertSessionHasErrors('sku');
        $this->post('/products', $this->valid(['sku' => 'taken']))->assertSessionHasErrors('sku');          // case-insensitive
        $this->post('/products', $this->valid(['barcode' => '111222333']))->assertSessionHasErrors('barcode');
        $this->post('/products', $this->valid(['sale_price' => '-1']))->assertSessionHasErrors('sale_price');
        $this->post('/products', $this->valid(['sale_price' => 'abc']))->assertSessionHasErrors('sale_price');
        $this->post('/products', $this->valid(['sale_price' => '100', 'min_sale_price' => '150']))->assertSessionHasErrors('min_sale_price');
        $this->post('/products', $this->valid(['category_id' => 99999]))->assertSessionHasErrors('category_id');
        $this->post('/products', $this->valid(['type' => 'gadget']))->assertSessionHasErrors('type');
        $this->post('/products', $this->valid(['tax_rate' => '101']))->assertSessionHasErrors('tax_rate');
        $this->post('/products', $this->valid(['barcode' => str_repeat('9', 65)]))->assertSessionHasErrors('barcode');
        $this->assertSame(1, Product::count());
    }

    public function test_sku_and_barcode_must_be_plain_text_a_scanner_can_read(): void
    {
        $this->as('Manager');

        $this->post('/products', $this->valid(['sku' => 'Käse-1']))->assertSessionHasErrors('sku');
        $this->post('/products', $this->valid(['barcode' => 'AB 12']))->assertSessionHasErrors('barcode');
        $this->post('/products', $this->valid(['barcode' => 'ÄÖÜ']))->assertSessionHasErrors('barcode');
        $this->post('/products', $this->valid(['sku' => 'HP 85A / black', 'barcode' => 'AB-12.x_9']))->assertSessionHasNoErrors();   // spaces are fine in a SKU, ordinary symbols everywhere
    }

    public function test_a_barcode_equal_to_another_products_sku_is_refused_and_vice_versa(): void
    {
        $this->as('Manager');
        Product::factory()->create(['sku' => 'ABC123', 'barcode' => '7777']);

        $this->post('/products', $this->valid(['barcode' => 'ABC123']))->assertSessionHasErrors('barcode');
        $this->post('/products', $this->valid(['sku' => '7777']))->assertSessionHasErrors('sku');
    }

    public function test_service_items_never_get_stock_or_stock_settings(): void
    {
        $this->as('Manager');

        $this->post('/products', $this->valid(['type' => 'service', 'name' => 'Repair labour', 'opening_stock' => '5', 'min_stock_level' => '3', 'rack_location' => 'A1']))->assertSessionHasNoErrors();

        $p = Product::query()->firstOrFail();
        $this->assertSame('service', $p->type);
        $this->assertSame('0.000', $p->current_stock);
        $this->assertNull($p->min_stock_level);
        $this->assertNull($p->rack_location);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_editing_audits_the_changed_fields_with_old_and_new_values(): void
    {
        $this->as('Manager');
        $p = Product::factory()->create(['name' => 'Old name', 'model' => 'M1', 'rack_location' => 'A1']);

        $this->put("/products/{$p->id}", $this->valid(['name' => 'New name', 'sku' => $p->sku, 'model' => 'M1', 'rack_location' => 'B2', 'sale_price' => '100']))->assertRedirect();

        $row = DB::table('audit_logs')->where('event', 'product_updated')->latest('id')->first();
        $old = json_decode($row->old_values, true);
        $new = json_decode($row->new_values, true);
        $this->assertSame('Old name', $old['name']);
        $this->assertSame('New name', $new['name']);
        $this->assertSame('B2', $new['rack_location']);
        $this->assertArrayNotHasKey('model', $new, 'Unchanged fields are not logged.');
    }

    public function test_price_changes_are_audited_separately(): void
    {
        $this->as('Manager');
        $p = Product::factory()->create(['sale_price' => '100.00', 'wholesale_price' => '90.00']);

        $this->put("/products/{$p->id}", $this->valid(['name' => $p->name, 'sku' => $p->sku, 'sale_price' => '120', 'wholesale_price' => '90', 'min_sale_price' => '80']));

        $row = DB::table('audit_logs')->where('event', 'product_price_changed')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame(['sale_price' => '100.00', 'min_sale_price' => null], json_decode($row->old_values, true));
        $this->assertSame(['sale_price' => '120.00', 'min_sale_price' => '80.00'], json_decode($row->new_values, true));
        $this->assertSame('120.00', $p->fresh()->sale_price);
    }

    public function test_someone_without_the_change_prices_permission_can_edit_the_rest_but_prices_stay(): void
    {
        $this->asWithOnly(['products.view', 'products.edit']);
        $p = Product::factory()->create(['name' => 'Before', 'sale_price' => '100.00', 'wholesale_price' => '90.00', 'min_sale_price' => '80.00']);

        // the form posts no price boxes (read-only), and even a forged price is ignored
        $this->put("/products/{$p->id}", ['type' => 'stock', 'name' => 'After', 'sku' => $p->sku, 'category_id' => $p->category_id, 'unit_id' => $p->unit_id, 'sale_price' => '1', 'wholesale_price' => '1', 'min_sale_price' => '1'])
            ->assertSessionHasNoErrors();

        $p->refresh();
        $this->assertSame('After', $p->name);
        $this->assertSame('100.00', $p->sale_price);
        $this->assertSame('90.00', $p->wholesale_price);
        $this->assertSame('80.00', $p->min_sale_price);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'product_price_changed']);

        // and with no price fields at all
        $this->put("/products/{$p->id}", ['type' => 'stock', 'name' => 'After 2', 'sku' => $p->sku, 'category_id' => $p->category_id, 'unit_id' => $p->unit_id])->assertSessionHasNoErrors();
        $this->assertSame('After 2', $p->fresh()->name);
        $this->assertSame('100.00', $p->fresh()->sale_price);
    }

    public function test_the_unit_and_kind_cannot_change_once_there_is_stock_history(): void
    {
        $this->as('Manager');
        $p = $this->stocked('5');
        $grams = Unit::query()->where('short_name', 'g')->value('id');
        $base = ['name' => $p->name, 'sku' => $p->sku, 'category_id' => $p->category_id, 'sale_price' => '100'];

        $this->put("/products/{$p->id}", $base + ['type' => 'stock', 'unit_id' => $grams])->assertSessionHas('error', fn ($m) => str_contains($m, 'unit cannot be changed'));
        $this->put("/products/{$p->id}", $base + ['type' => 'service', 'unit_id' => $p->unit_id])->assertSessionHas('error', fn ($m) => str_contains($m, 'cannot be switched'));

        $p->refresh();
        $this->assertSame('stock', $p->type);
        $this->assertNotSame($grams, $p->unit_id);
    }

    public function test_a_product_without_history_can_change_unit_and_kind(): void
    {
        $this->as('Manager');
        $p = Product::factory()->create();
        $grams = Unit::query()->where('short_name', 'g')->value('id');

        $this->put("/products/{$p->id}", ['type' => 'service', 'name' => $p->name, 'sku' => $p->sku, 'category_id' => $p->category_id, 'unit_id' => $grams, 'sale_price' => '100'])->assertSessionHasNoErrors();

        $this->assertSame('service', $p->fresh()->type);
        $this->assertSame($grams, $p->fresh()->unit_id);
    }

    public function test_deactivating_hides_the_product_from_pickers_and_is_audited(): void
    {
        $this->as('Manager');
        $p = Product::factory()->create(['name' => 'Findable thing']);

        $this->post("/products/{$p->id}/toggle")->assertSessionHas('success');
        $this->assertFalse($p->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['event' => 'product_deactivated', 'auditable_id' => $p->id]);
        $this->getJson('/products/lookup?q=Findable')->assertOk()->assertJsonCount(0);

        $this->post("/products/{$p->id}/toggle");
        $this->assertTrue($p->fresh()->is_active);
        $this->getJson('/products/lookup?q=Findable&stock_only=0')->assertJsonCount(1);
    }

    public function test_deleting_needs_password_confirmation_and_is_refused_when_there_is_history(): void
    {
        $this->as('Manager');
        $clean = Product::factory()->create(['name' => 'Never used']);
        $used = $this->stocked('3', '50', ['name' => 'Has history']);

        // not confirmed: sent to the password screen, nothing deleted
        $this->delete("/products/{$clean->id}")->assertRedirect(route('password.confirm'));
        $this->assertNotNull(Product::find($clean->id));

        $this->withSession($this->confirmed());
        $this->delete("/products/{$used->id}")->assertSessionHas('error', fn ($m) => str_contains($m, 'stock history'));
        $this->assertNotNull(Product::find($used->id));

        $this->delete("/products/{$clean->id}")->assertRedirect('/products');
        $this->assertNull(Product::find($clean->id));
        $this->assertNotNull(Product::withTrashed()->find($clean->id), 'Deleting is a soft delete.');
        $this->assertDatabaseHas('audit_logs', ['event' => 'product_deleted', 'auditable_id' => $clean->id]);
    }

    public function test_a_deleted_products_sku_stays_reserved(): void
    {
        $this->as('Manager');
        $p = Product::factory()->create(['sku' => 'RESERVED-1']);
        $p->delete();

        $this->post('/products', $this->valid(['sku' => 'RESERVED-1']))->assertSessionHasErrors('sku');
    }

    public function test_pictures_are_reencoded_stored_privately_and_served_to_viewers(): void
    {
        $this->as('Manager');

        $this->post('/products', $this->valid(['image' => UploadedFile::fake()->image('p.png', 1600, 900)]))->assertSessionHasNoErrors();

        $p = Product::query()->firstOrFail();
        $this->assertStringStartsWith('products/', $p->image_path);
        $file = config('mrpos.files_path').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $p->image_path);
        $this->assertFileExists($file);
        [$w, $h, $type] = getimagesize($file);
        $this->assertSame(IMAGETYPE_JPEG, $type, 'Everything is re-encoded as JPEG.');
        $this->assertLessThanOrEqual(800, max($w, $h));

        $this->get("/products/{$p->id}/image")->assertOk();
        $this->as('Cashier')->get("/products/{$p->id}/image")->assertOk();
        auth()->logout();
        $this->get("/products/{$p->id}/image")->assertRedirect('/login');
    }

    public function test_replacing_or_removing_a_picture_deletes_the_old_file_and_bad_files_are_refused(): void
    {
        $this->as('Manager');
        $this->post('/products', $this->valid(['image' => UploadedFile::fake()->image('a.jpg', 300, 300)]));
        $p = Product::query()->firstOrFail();
        $old = config('mrpos.files_path').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $p->image_path);
        $base = $this->valid(['name' => $p->name, 'sku' => $p->sku]);

        $this->put("/products/{$p->id}", $base + ['image' => UploadedFile::fake()->image('b.jpg', 300, 300)]);
        $this->assertFileDoesNotExist($old);
        $second = config('mrpos.files_path').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $p->fresh()->image_path);
        $this->assertFileExists($second);

        $this->put("/products/{$p->id}", $base + ['image' => UploadedFile::fake()->createWithContent('evil.png', '<?php echo 1; ?>')])->assertSessionHasErrors('image');
        $this->put("/products/{$p->id}", $base + ['image' => UploadedFile::fake()->image('huge.jpg')->size(5000)])->assertSessionHasErrors('image');
        $this->assertFileExists($second, 'A refused upload leaves the current picture alone.');

        $this->put("/products/{$p->id}", $base + ['remove_image' => 1]);
        $this->assertNull($p->fresh()->image_path);
        $this->assertFileDoesNotExist($second);
    }

    public function test_search_finds_by_name_sku_barcode_and_model_and_ranks_exact_codes_first(): void
    {
        $this->as('Manager');
        Product::factory()->create(['name' => 'Canon toner 725', 'sku' => 'CN-725', 'model' => 'CRG-725']);
        Product::factory()->create(['name' => 'Brother drum', 'sku' => 'BR-DR', 'barcode' => '8801234567890']);
        Product::factory()->create(['name' => 'Unrelated cable', 'sku' => 'CB-1']);
        Product::factory()->create(['name' => 'Fits CN-725 machines', 'sku' => 'ZZ-1']);

        $this->get('/products?q=toner')->assertOk()->assertSee('Canon toner 725')->assertDontSee('Unrelated cable');
        $this->get('/products?q=CRG-725')->assertSee('Canon toner 725');
        $this->get('/products?q=8801234567890')->assertSee('Brother drum')->assertDontSee('Canon toner');
        $this->get('/products?q=BR-DR')->assertSee('Brother drum');

        // exact SKU first, then the product that merely mentions it
        $this->assertSame(['CN-725', 'ZZ-1'], Product::query()->search('CN-725')->pluck('sku')->all());
    }

    public function test_search_treats_percent_and_underscore_literally(): void
    {
        $this->as('Manager');
        Product::factory()->create(['name' => '100% cotton', 'sku' => 'A-1']);
        Product::factory()->create(['name' => 'Something else', 'sku' => 'A-2']);

        $this->get('/products?q=%25')->assertSee('100% cotton')->assertDontSee('Something else');
        $this->get('/products?q=_')->assertDontSee('Something else');
    }

    public function test_filters_category_includes_subcategories_status_and_stock_level(): void
    {
        $this->as('Manager');
        $parts = Category::query()->where('name', 'Printer Parts')->firstOrFail();
        $drums = Category::query()->where('name', 'Drums')->firstOrFail();
        $inDrums = Product::factory()->create(['name' => 'Drum unit X', 'category_id' => $drums->id]);
        Product::factory()->create(['name' => 'Cable Y', 'category_id' => Category::query()->where('name', 'Cables')->value('id')]);
        $inactive = Product::factory()->inactive()->create(['name' => 'Retired Z']);
        $low = $this->stocked('2', '10', ['name' => 'Low item', 'min_stock_level' => '5']);
        $this->stocked('50', '10', ['name' => 'Plenty item']);
        $out = Product::factory()->create(['name' => 'Empty item']);

        $this->get("/products?category={$parts->id}")->assertSee('Drum unit X')->assertDontSee('Cable Y');
        $this->get('/products')->assertDontSee('Retired Z');
        $this->get('/products?status=inactive')->assertSee('Retired Z')->assertDontSee('Cable Y');
        $this->get('/products?stock=low')->assertSee('Low item')->assertDontSee('Plenty item')->assertDontSee('Empty item');
        $this->get('/products?stock=out')->assertSee('Empty item')->assertDontSee('Low item');
        $this->get('/products?stock=in')->assertSee('Plenty item')->assertDontSee('Empty item');
        $this->assertNotNull($inDrums->id.$inactive->id.$low->id.$out->id);
    }

    public function test_the_lookup_returns_what_a_picker_needs_and_never_a_cost(): void
    {
        $this->as('Cashier');
        $this->stocked('7', '123.45', ['name' => 'Picker item', 'sku' => 'PK-1', 'barcode' => '4006381333931']);
        Product::factory()->service()->create(['name' => 'Picker service']);

        $json = $this->getJson('/products/lookup?q=Picker')->assertOk()->assertJsonCount(1)->json();
        $this->assertSame(['id', 'sku', 'barcode', 'name', 'stock', 'unit', 'allows_decimal'], array_keys($json[0]));
        $this->assertSame('7', $json[0]['stock']);
        $this->assertSame('pcs', $json[0]['unit']);
        $this->assertStringNotContainsString('123.45', json_encode($json));

        $this->getJson('/products/lookup?q=Picker&stock_only=0')->assertJsonCount(2);
        $this->getJson('/products/lookup')->assertStatus(422);
    }

    public function test_who_can_do_what_with_products(): void
    {
        $p = Product::factory()->create();

        foreach (['Cashier', 'Technician'] as $role) {
            $this->as($role);
            $this->get('/products')->assertOk();
            $this->get("/products/{$p->id}")->assertOk();
            $this->get('/products/create')->assertForbidden();
            $this->post('/products', $this->valid())->assertForbidden();
            $this->get("/products/{$p->id}/edit")->assertForbidden();
            $this->put("/products/{$p->id}", $this->valid())->assertForbidden();
            $this->post("/products/{$p->id}/toggle")->assertForbidden();
            $this->withSession($this->confirmed())->delete("/products/{$p->id}")->assertForbidden();
        }

        $this->as('Manager');
        $this->get('/products/create')->assertOk();
        $this->get("/products/{$p->id}/edit")->assertOk();
        $this->assertNotNull(Product::find($p->id));
    }

    public function test_the_product_pages_render(): void
    {
        $this->as('Admin');
        $p = $this->stocked('4', '50', ['model' => 'X-1', 'warranty_months' => 12, 'rack_location' => 'A1']);

        $this->get('/products')->assertOk()->assertSee($p->sku);
        $this->get('/products/create')->assertOk()->assertSee('Stock you have now');
        $this->get("/products/{$p->id}")->assertOk()->assertSee('Recent stock movements')->assertSee('Purchase received');
        $this->get("/products/{$p->id}/edit")->assertOk()->assertSee('Average cost');
        $this->get('/products/999999')->assertNotFound();
    }
}
