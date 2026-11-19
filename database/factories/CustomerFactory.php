<?php

namespace Database\Factories;

use App\Modules\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return ['name' => fake()->name(), 'phone' => fake()->unique()->numerify('05########'), 'is_active' => true];
    }
}
