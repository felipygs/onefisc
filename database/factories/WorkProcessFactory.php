<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\WorkProcess;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkProcess>
 */
class WorkProcessFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'status' => 'active',
            'source' => 'manual',
            'marketplace_process_id' => null,
            'recurrence_interval' => null,
            'recurrence_unit' => 'none',
            'due_mode' => null,
            'due_day' => null,
            'estimated_duration_days' => null,
            'competence_offset' => 'due_month',
            'target_lead_days' => null,
            'cascade_execution' => false,
            'association_regimes' => null,
            'association_tag_ids' => null,
            'extra_client_ids' => null,
            'excluded_client_ids' => null,
        ];
    }
}
