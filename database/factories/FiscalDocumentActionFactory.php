<?php

namespace Database\Factories;

use App\Models\FiscalDocument;
use App\Models\FiscalDocumentAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalDocumentAction>
 */
class FiscalDocumentActionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fiscal_document_id' => FiscalDocument::factory(),
            'type' => 'ciencia',
            'actor_user_id' => null,
            'metadata' => null,
        ];
    }
}
