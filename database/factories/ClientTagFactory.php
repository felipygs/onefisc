<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\ClientTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientTag>
 */
class ClientTagFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->unique()->word(),
        ];
    }
}
