<?php

namespace Database\Factories;

use App\Models\WorkMarketplaceProcess;
use App\Models\WorkMarketplaceTaskDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkMarketplaceTaskDefinition>
 */
class WorkMarketplaceTaskDefinitionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'marketplace_process_id' => WorkMarketplaceProcess::factory(),
            'title' => fake()->sentence(4),
            'position' => fake()->numberBetween(0, 99),
            'description' => fake()->optional()->sentence(),
            'due_day' => null,
            'competence_offset' => null,
            'priority' => 'medium',
            'requires_document' => false,
        ];
    }
}
