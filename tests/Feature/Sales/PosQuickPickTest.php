<?php

namespace Tests\Feature\Sales;

use App\Models\Brand;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/** The till's quick-pick and search tiles show the product's picture, brand and model. */
class PosQuickPickTest extends TestCase
{
    use ActsAsRoles;
    use MakesStock;
    use RefreshesSeededDatabase;

    public function test_quick_picks_and_search_results_carry_the_picture_brand_and_model(): void
    {
        $hp = Brand::query()->firstOrCreate(['name' => 'HP']);
        $withPic = $this->stocked('5', '100', ['name' => 'HP 85A Toner', 'brand_id' => $hp->id, 'model' => 'CE285A', 'image_path' => 'products/abc.jpg', 'sale_price' => '4500']);
        $plain = $this->stocked('5', '100', ['name' => 'Plain cable', 'brand_id' => null, 'model' => null, 'image_path' => null, 'sale_price' => '200']);
        $this->as('Cashier');

        $rows = collect($this->getJson('/pos/products/quick')->assertOk()->json())->keyBy('id');
        $this->assertSame('HP', $rows[$withPic->id]['brand']);
        $this->assertSame('CE285A', $rows[$withPic->id]['model']);
        $this->assertStringStartsWith(route('products.image', $withPic), $rows[$withPic->id]['image']);
        $this->assertNull($rows[$plain->id]['image']);
        $this->assertNull($rows[$plain->id]['brand']);
        $this->assertNull($rows[$plain->id]['model']);
        $this->assertArrayNotHasKey('average_cost', $rows[$withPic->id], 'the till never receives cost');

        $found = $this->getJson('/pos/products/search?q=CE285A')->assertOk()->json();
        $this->assertSame('CE285A', $found[0]['model']);
        $this->assertSame('HP', $found[0]['brand']);

        $this->get('/pos')->assertOk()->assertSee('[p.brand, p.model]', false)->assertSee('p.image', false);
    }

    public function test_someone_who_may_not_see_products_gets_no_picture_address(): void
    {
        $this->stocked('5', '100', ['name' => 'Toner', 'image_path' => 'products/abc.jpg', 'model' => 'X1']);
        $this->asWithOnly(['pos.access']);

        $row = $this->getJson('/pos/products/quick')->assertOk()->json()[0];
        $this->assertNull($row['image']);
        $this->assertSame('X1', $row['model']);
    }
}
