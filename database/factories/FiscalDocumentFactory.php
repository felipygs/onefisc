<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\FiscalDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalDocument>
 */
class FiscalDocumentFactory extends Factory
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
            'doc_type' => 'nfe',
            'number' => fake()->unique()->numerify('#########'),
            'series' => '1',
            'key' => fake()->unique()->numerify('############################################'),
            'derived_from_key' => false,
            'emission_at' => now(),
            'issuer_name' => fake()->company(),
            'issuer_tax_id' => fake()->unique()->numerify('##############'),
            'recipient_name' => fake()->company(),
            'recipient_tax_id' => fake()->unique()->numerify('##############'),
            'status' => 'pending',
            'has_xml' => false,
            'has_danfe' => false,
            'xml_path' => null,
            'pdf_path' => null,
        ];
    }
}
