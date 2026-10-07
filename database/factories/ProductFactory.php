<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Uses the seeded categories and units (Other, pcs) so tests stay fast.
 * Stock and cost are never set here: use InventoryService (or the helper in tests) to give a product stock.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'type' => Product::TYPE_STOCK,
            'name' => ucfirst(fake()->unique()->words(3, true)),
            'sku' => 'T-'.fake()->unique()->numerify('######'),
            'barcode' => null,
            'category_id' => fn () => Category::query()->whereNull('parent_id')->where('name', 'Other')->value('id'),
            'unit_id' => fn () => Unit::query()->where('short_name', 'pcs')->value('id'),
            'sale_price' => '100.00',
            'is_active' => true,
        ];
    }

    public function service(): static
    {
        return $this->state(fn () => ['type' => Product::TYPE_SERVICE]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function unit(string $shortName): static
    {
        return $this->state(fn () => ['unit_id' => Unit::query()->where('short_name', $shortName)->value('id')]);
    }
}
