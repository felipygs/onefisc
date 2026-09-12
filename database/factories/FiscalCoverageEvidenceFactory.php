<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\FiscalCoverageEvidence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalCoverageEvidence>
 */
class FiscalCoverageEvidenceFactory extends Factory
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
            'family' => 'nfse',
            'ibge_code' => '3550308',
            'status' => 'limited',
            'reason' => fake()->sentence(),
            'evidence' => null,
        ];
    }
}
