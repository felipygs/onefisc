<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Client;
use App\Models\WorkProcess;
use App\Models\WorkTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkTask>
 */
class WorkTaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $account = Account::factory()->create();
        $process = WorkProcess::factory()->create(['account_id' => $account->id]);
        $client = Client::factory()->create(['account_id' => $account->id]);

        return [
            'account_id' => $account->id,
            'work_process_id' => $process->id,
            'client_id' => $client->id,
            'work_process_task_definition_id' => null,
            'title' => fake()->sentence(4),
            'status' => 'todo',
            'position' => 0,
            'priority' => 'medium',
            'assigned_user_id' => null,
            'department_id' => null,
            'competence' => null,
            'start_at' => null,
            'due_on' => null,
        ];
    }
}
