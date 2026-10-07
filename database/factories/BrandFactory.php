<?php

namespace Database\Factories;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Brand> */
class BrandFactory extends Factory
{
    protected $model = Brand::class;

    public function definition(): array
    {
        return ['name' => ucfirst(fake()->unique()->word()).' '.fake()->unique()->numerify('###'), 'is_active' => true];
    }
}
