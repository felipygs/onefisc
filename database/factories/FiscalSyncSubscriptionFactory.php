<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\FiscalSyncSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalSyncSubscription>
 */
class FiscalSyncSubscriptionFactory extends Factory
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
            'next_run_at' => now()->addHour(),
            'blocked_until' => null,
        ];
    }
}
