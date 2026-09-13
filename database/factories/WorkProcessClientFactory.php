<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\WorkProcess;
use App\Models\WorkProcessClient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkProcessClient>
 */
class WorkProcessClientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_process_id' => WorkProcess::factory(),
            'client_id' => Client::factory(),
        ];
    }
}
