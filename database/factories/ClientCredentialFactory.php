<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientCredential>
 */
class ClientCredentialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'pfx_data' => null,
            'pfx_password' => null,
            'thumbprint' => fake()->unique()->sha1(),
            'expires_at' => null,
            'portal_password' => null,
        ];
    }
}
