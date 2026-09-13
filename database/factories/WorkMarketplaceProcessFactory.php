<?php

namespace Database\Factories;

use App\Models\WorkMarketplaceProcess;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkMarketplaceProcess>
 */
class WorkMarketplaceProcessFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'category' => 'Fiscal',
            'published' => true,
        ];
    }
}
