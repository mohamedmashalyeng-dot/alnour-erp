<?php

namespace Database\Factories;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $price = fake()->numberBetween(100, 2000) * 100;

        return [
            'name' => 'إطار '.fake()->unique()->bothify('??-####'),
            'sku' => 'SKU-'.fake()->unique()->bothify('#######'),
            'barcode' => fake()->unique()->ean13(),
            'product_type' => 'frame',
            'purchase_price' => intdiv($price, 2),
            'selling_price' => $price,
            'track_stock' => true,
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Product $p) {
            $p->cost_price = $p->purchase_price;
        });
    }
}
