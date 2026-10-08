<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'code' => 'C-'.fake()->unique()->numerify('######'),
            'name' => fake()->name(),
            'phone' => fake()->numerify('03#########'),
            'price_tier' => 'retail',
            'is_active' => true,
        ];
    }
}
