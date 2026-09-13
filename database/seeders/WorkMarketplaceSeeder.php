<?php

namespace Database\Seeders;

use App\Models\WorkMarketplaceProcess;
use App\Models\WorkMarketplaceTaskDefinition;
use Illuminate\Database\Seeder;

class WorkMarketplaceSeeder extends Seeder
{
    /**
     * Seed the platform marketplace. Idempotent by slug + position, so it
     * runs safely on every deploy without duplicating listings.
     */
    public function run(): void
    {
        $process = WorkMarketplaceProcess::firstOrCreate(
            ['slug' => 'pgdas-mensal'],
            [
                'title' => 'PGDAS Mensal',
                'description' => 'Apuração mensal do Simples Nacional: confere o movimento do mês anterior e gera o DAS com vencimento no dia 20.',
                'category' => 'Fiscal',
                'published' => true,
            ]
        );

        $definitions = [
            [
                'title' => 'Conferir notas fiscais do mês anterior',
                'description' => 'Validar se todas as vendas e serviços do mês anterior foram documentados.',
                'due_day' => 20,
                'competence_offset' => 'previous_month',
                'priority' => 'high',
                'requires_document' => false,
            ],
            [
                'title' => 'Apurar débitos do Simples Nacional',
                'description' => 'Calcular a base e aplicar a alíquota da faixa de faturamento.',
                'due_day' => 20,
                'competence_offset' => 'previous_month',
                'priority' => 'high',
                'requires_document' => false,
            ],
            [
                'title' => 'Gerar e validar o DAS',
                'description' => 'Emitir a guia no PGDAS-D e conferir valores antes de liberar ao cliente.',
                'due_day' => 20,
                'competence_offset' => 'previous_month',
                'priority' => 'medium',
                'requires_document' => true,
            ],
            [
                'title' => 'Confirmar o pagamento da guia anterior',
                'description' => 'Baixar o comprovante e registrar a quitação da competência passada.',
                'due_day' => 20,
                'competence_offset' => 'previous_month',
                'priority' => 'low',
                'requires_document' => true,
            ],
        ];

        foreach (array_values($definitions) as $position => $attributes) {
            WorkMarketplaceTaskDefinition::updateOrCreate(
                ['marketplace_process_id' => $process->id, 'position' => $position],
                $attributes
            );
        }
    }
}
