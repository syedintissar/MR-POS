<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Category> */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        return ['name' => ucfirst(fake()->unique()->word()).' '.fake()->unique()->numerify('###'), 'is_active' => true, 'sort_order' => 0];
    }
}
