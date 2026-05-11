<?php

namespace Database\Factories;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 10, 1000);
        $igv = 0.0;
        $total = round($subtotal + $igv, 2);

        return [
            'user_id' => User::factory(),
            'reference' => fake()->optional()->bothify('VTE-########'),
            'subtotal' => $subtotal,
            'igv' => $igv,
            'total' => $total,
            'state_sale' => 'validated',
            'state_payment' => 'pending',
            'debt' => $total,
            'paid_out' => 0,
        ];
    }
}
