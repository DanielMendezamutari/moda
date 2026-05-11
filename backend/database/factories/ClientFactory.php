<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->firstName();
        $surname = fake()->lastName();

        return [
            'name' => $name,
            'surname' => $surname,
            'full_name' => "{$name} {$surname}",
            'phone' => fake()->optional(0.9)->numerify('7#######'),
            'email' => fake()->optional(0.5)->safeEmail(),
            'type_client' => 'natural',
            'type_document' => 'CI',
            'n_document' => fake()->unique()->numerify('########'),
            'birthdate' => fake()->optional(0.6)->date(),
            'user_id' => null,
            'branch_id' => Branch::factory(),
            'is_active' => true,
            'gender' => fake()->optional()->randomElement(['M', 'F']),
            'ubigeo' => null,
            'address' => fake()->optional(0.7)->streetAddress(),
            'credit_enabled' => false,
            'credit_limit' => null,
            'credit_balance' => 0,
        ];
    }
}
