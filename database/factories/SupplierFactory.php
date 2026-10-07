<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Supplier> */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'code' => 'S-'.fake()->unique()->numerify('######'),
            'name' => fake()->company(),
            'phone' => fake()->numerify('03#########'),
            'is_active' => true,
        ];
    }
}
