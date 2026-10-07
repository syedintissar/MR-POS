<?php

namespace Database\Factories;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Unit> */
class UnitFactory extends Factory
{
    protected $model = Unit::class;

    public function definition(): array
    {
        return ['name' => 'Unit '.fake()->unique()->numerify('####'), 'short_name' => fake()->unique()->lexify('u???'), 'allows_decimal' => false];
    }
}
