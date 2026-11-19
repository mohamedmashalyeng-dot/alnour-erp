<?php

namespace Database\Factories;

use App\Modules\Branches\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'code' => 'B'.fake()->unique()->numberBetween(10, 99999),
            'name' => 'فرع '.fake()->city(),
            'name_en' => fake()->city().' Branch',
            'city' => fake()->city(),
            'is_active' => true,
        ];
    }
}
