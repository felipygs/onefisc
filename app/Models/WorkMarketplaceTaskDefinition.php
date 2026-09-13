<?php

namespace App\Models;

use Database\Factories\WorkMarketplaceTaskDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Checklist template of a platform listing. Global like its parent: no
 * account scope; installs copy these rows into WorkProcessTaskDefinition.
 *
 * @property int $id
 * @property int $marketplace_process_id
 * @property string $title
 * @property int $position
 * @property string|null $description
 * @property int|null $due_day
 * @property string|null $competence_offset
 * @property string $priority
 * @property bool $requires_document
 */
#[Fillable(['marketplace_process_id', 'title', 'position', 'description', 'due_day', 'competence_offset', 'priority', 'requires_document'])]
class WorkMarketplaceTaskDefinition extends Model
{
    /** @use HasFactory<WorkMarketplaceTaskDefinitionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<WorkMarketplaceProcess, $this>
     */
    public function marketplaceProcess(): BelongsTo
    {
        return $this->belongsTo(WorkMarketplaceProcess::class, 'marketplace_process_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'due_day' => 'integer',
            'requires_document' => 'boolean',
        ];
    }
}
