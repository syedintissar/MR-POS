<?php

namespace Tests\Concerns;

use App\Models\Product;
use App\Services\Inventory\InventoryService;

trait MakesStock
{
    /** A stock product that already holds $qty units bought at $cost (goes through the real ledger). */
    protected function stocked(string $qty = '10', string $cost = '100', array $attrs = []): Product
    {
        $product = Product::factory()->create($attrs);

        if ($qty !== '0') {
            app(InventoryService::class)->move($product, $qty, 'purchase', ['unit_cost' => $cost]);
        }

        return $product->refresh();
    }

    protected function inventory(): InventoryService
    {
        return app(InventoryService::class);
    }
}
