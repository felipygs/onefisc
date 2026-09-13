<?php

namespace App\Models;

use Database\Factories\WorkMarketplaceProcessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Platform listing: global across accounts, so deliberately WITHOUT any
 * account scope. Per-account install state lives on WorkProcess through
 * the (account_id, marketplace_process_id) unique pair.
 *
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string|null $description
 * @property string $category
 * @property bool $published
 */
#[Fillable(['slug', 'title', 'description', 'category', 'published'])]
class WorkMarketplaceProcess extends Model
{
    /** @use HasFactory<WorkMarketplaceProcessFactory> */
    use HasFactory;

    /**
     * @return HasMany<WorkMarketplaceTaskDefinition, $this>
     */
    public function definitions(): HasMany
    {
        return $this->hasMany(WorkMarketplaceTaskDefinition::class, 'marketplace_process_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published' => 'boolean',
        ];
    }
}
