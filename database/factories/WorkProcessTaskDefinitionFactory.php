<?php

namespace Database\Factories;

use App\Models\WorkProcess;
use App\Models\WorkProcessTaskDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkProcessTaskDefinition>
 */
class WorkProcessTaskDefinitionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_process_id' => WorkProcess::factory(),
            'title' => fake()->sentence(4),
            'position' => fake()->numberBetween(0, 99),
            'description' => fake()->optional()->sentence(),
            'due_day' => null,
            'competence_offset' => null,
            'priority' => 'medium',
            'default_assigned_user_id' => null,
            'department_id' => null,
            'requires_document' => false,
        ];
    }
}
