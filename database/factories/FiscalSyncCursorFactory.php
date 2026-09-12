<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\FiscalSyncCursor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalSyncCursor>
 */
class FiscalSyncCursorFactory extends Factory
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
            'family' => 'nfe',
            'environment' => 'production',
            'last_nsu' => '0',
        ];
    }
}
