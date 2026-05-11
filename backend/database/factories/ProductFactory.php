<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku' => fake()->unique()->bothify('SKU-####-???'),
            'barcode' => null,
            'name' => fake()->words(3, true),
            'description' => fake()->optional(0.4)->paragraph(),
            'image_path' => null,
            'price' => fake()->randomFloat(2, 5, 500),
            'wholesale_price' => fake()->optional(0.5)->randomFloat(2, 4, 400),
            'cost_price' => fake()->optional(0.7)->randomFloat(2, 3, 400),
            'discount_percent' => fake()->randomFloat(2, 0, 25),
            'is_gift_card' => false,
            'is_active' => true,
            'warranty_days' => fake()->numberBetween(0, 180),
            'category_id' => null,
        ];
    }
}
