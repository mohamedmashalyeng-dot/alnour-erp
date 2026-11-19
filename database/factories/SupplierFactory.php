<?php

namespace Database\Factories;

use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return ['name' => fake()->company(), 'company_name' => fake()->company(), 'phone' => fake()->numerify('05########'), 'is_active' => true];
    }
}
