<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use Database\Seeders\CatalogueSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use ActsAsRoles;
    use MakesStock;
    use RefreshesSeededDatabase;

    public function test_the_starter_catalogue_matches_the_shops_categories(): void
    {
        $roots = Category::query()->whereNull('parent_id')->orderBy('sort_order')->pluck('name')->all();
        $this->assertSame(['Printers', 'Toners', 'Cartridges', 'Ink', 'Printer Parts', 'Cables', 'Computer Accessories', 'Stationery', 'Services', 'Other'], $roots);

        $parts = Category::query()->where('name', 'Printer Parts')->firstOrFail();
        $this->assertSame(['Drums', 'Blades', 'Rollers', 'Fusers', 'Other parts'], $parts->children()->pluck('name')->all());

        $this->assertTrue(Unit::query()->where('short_name', 'g')->value('allows_decimal'));
        $this->assertFalse(Unit::query()->where('short_name', 'pcs')->value('allows_decimal'));
        $this->assertTrue(Brand::query()->where('name', 'HP')->exists());
    }

    public function test_the_seeder_can_run_again_without_duplicating_anything(): void
    {
        $before = [Category::count(), Brand::count(), Unit::count()];

        $this->seed(CatalogueSeeder::class);

        $this->assertSame($before, [Category::count(), Brand::count(), Unit::count()]);
    }

    public function test_a_manager_adds_a_category_and_a_subcategory_and_it_is_audited(): void
    {
        $this->as('Manager');

        $this->post('/catalog/categories', ['name' => 'Laptops', 'sort_order' => 5])->assertRedirect('/catalog/categories');
        $laptops = Category::query()->where('name', 'Laptops')->firstOrFail();
        $this->post('/catalog/categories', ['name' => 'Batteries', 'parent_id' => $laptops->id])->assertSessionHasNoErrors();

        $this->assertSame($laptops->id, Category::query()->where('name', 'Batteries')->value('parent_id'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'catalog_created', 'module' => 'catalog']);
    }

    public function test_category_rules_depth_duplicates_and_self_parenting(): void
    {
        $this->as('Manager');
        $drums = Category::query()->where('name', 'Drums')->firstOrFail();
        $parts = Category::query()->where('name', 'Printer Parts')->firstOrFail();

        // a subcategory cannot go under another subcategory
        $this->post('/catalog/categories', ['name' => 'Too deep', 'parent_id' => $drums->id])->assertSessionHasErrors('parent_id');
        // duplicate among main categories, and among siblings
        $this->post('/catalog/categories', ['name' => 'toners'])->assertSessionHasErrors('name');
        $this->post('/catalog/categories', ['name' => 'drums', 'parent_id' => $parts->id])->assertSessionHasErrors('name');
        // the same name under a different parent is fine
        $this->post('/catalog/categories', ['name' => 'Drums', 'parent_id' => Category::query()->where('name', 'Toners')->value('id')])->assertSessionHasNoErrors();
        // a category with children cannot become a child itself; nobody is their own parent
        $this->put("/catalog/categories/{$parts->id}", ['name' => 'Printer Parts', 'parent_id' => Category::query()->where('name', 'Toners')->value('id')])->assertSessionHasErrors('parent_id');
        $this->put("/catalog/categories/{$parts->id}", ['name' => 'Printer Parts', 'parent_id' => $parts->id])->assertSessionHasErrors('parent_id');
    }

    public function test_a_category_in_use_or_with_children_cannot_be_deleted_but_an_empty_one_can(): void
    {
        $this->as('Manager');
        $toners = Category::query()->where('name', 'Toners')->firstOrFail();
        Product::factory()->create(['category_id' => $toners->id]);

        $this->delete("/catalog/categories/{$toners->id}")->assertSessionHas('error');
        $this->assertNotNull(Category::query()->find($toners->id));

        $parts = Category::query()->where('name', 'Printer Parts')->firstOrFail();
        $this->delete("/catalog/categories/{$parts->id}")->assertSessionHas('error', fn ($m) => str_contains($m, 'subcategories'));

        $empty = Category::query()->create(['name' => 'Temporary']);
        $this->delete("/catalog/categories/{$empty->id}")->assertSessionHas('success');
        $this->assertNull(Category::query()->find($empty->id));
        $this->assertDatabaseHas('audit_logs', ['event' => 'catalog_deleted']);
    }

    public function test_renaming_a_category_is_audited_with_old_and_new_values(): void
    {
        $this->as('Manager');
        $c = Category::query()->where('name', 'Other')->firstOrFail();

        $this->put("/catalog/categories/{$c->id}", ['name' => 'Miscellaneous', 'sort_order' => 90, 'is_active' => 1]);

        $row = DB::table('audit_logs')->where('event', 'catalog_updated')->latest('id')->first();
        $this->assertSame('Other', json_decode($row->old_values, true)['name']);
        $this->assertSame('Miscellaneous', json_decode($row->new_values, true)['name']);
        // put it back so other tests still find "Other"
        $this->put("/catalog/categories/{$c->id}", ['name' => 'Other', 'sort_order' => 100, 'is_active' => 1]);
    }

    public function test_brands_are_unique_and_protected_when_used(): void
    {
        $this->as('Manager');
        $this->post('/catalog/brands', ['name' => 'Lexmark'])->assertSessionHasErrors('name');
        $this->post('/catalog/brands', ['name' => 'Konica'])->assertSessionHasNoErrors();

        $brand = Brand::query()->where('name', 'Konica')->firstOrFail();
        Product::factory()->create(['brand_id' => $brand->id]);
        $this->delete("/catalog/brands/{$brand->id}")->assertSessionHas('error');
        $this->assertNotNull(Brand::query()->find($brand->id));
    }

    public function test_units_need_unique_names_and_keep_decimals_once_stock_history_exists(): void
    {
        $this->as('Manager');
        $this->post('/catalog/units', ['name' => 'Gram', 'short_name' => 'gm'])->assertSessionHasErrors('name');
        $this->post('/catalog/units', ['name' => 'Roll', 'short_name' => 'g'])->assertSessionHasErrors('short_name');
        $this->post('/catalog/units', ['name' => 'Roll', 'short_name' => 'roll', 'allows_decimal' => 0])->assertSessionHasNoErrors();

        $gram = Unit::query()->where('short_name', 'g')->firstOrFail();
        $powder = Product::factory()->unit('g')->create();
        $this->inventory()->move($powder, '2.5', 'purchase', ['unit_cost' => '4']);

        $this->put("/catalog/units/{$gram->id}", ['name' => 'Gram', 'short_name' => 'g', 'allows_decimal' => 0])->assertSessionHasErrors('allows_decimal');
        $this->assertTrue($gram->fresh()->allows_decimal);
    }

    public function test_everyone_who_may_see_products_can_view_the_lists_but_only_catalog_managers_can_change_them(): void
    {
        $this->as('Cashier')->get('/catalog/categories')->assertOk()->assertSee('Toners')->assertDontSee('New category');
        $this->post('/catalog/categories', ['name' => 'Hack'])->assertForbidden();
        $this->put('/catalog/brands/1', ['name' => 'Hack'])->assertForbidden();
        $this->delete('/catalog/units/1')->assertForbidden();
        $this->assertDatabaseMissing('categories', ['name' => 'Hack']);

        $this->as('Technician')->get('/catalog/brands')->assertOk();
        $this->as('Manager')->get('/catalog/units')->assertOk()->assertSee('Allows decimals');
    }

    public function test_the_catalogue_pages_render_for_every_tab_and_unknown_tabs_are_404(): void
    {
        $this->as('Admin');
        foreach (['categories', 'brands', 'units'] as $tab) {
            $this->get("/catalog/{$tab}")->assertOk();
        }
        $this->get('/catalog/colours')->assertNotFound();
        $this->get('/catalog')->assertOk()->assertSee('Printer Parts');
    }
}
