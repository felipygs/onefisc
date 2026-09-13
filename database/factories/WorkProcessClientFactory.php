<?php

namespace Database\Factories;

use App\Models\Account;
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
        $account = Account::factory()->create();

        return [
            'work_process_id' => WorkProcess::factory()->create(['account_id' => $account->id]),
            'client_id' => Client::factory()->create(['account_id' => $account->id]),
        ];
    }
}
