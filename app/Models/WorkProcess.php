<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Database\Factories\WorkProcessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $account_id
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property string $source
 * @property string|null $marketplace_process_id
 * @property int|null $recurrence_interval
 * @property string $recurrence_unit
 * @property string|null $due_mode
 * @property int|null $due_day
 * @property int|null $estimated_duration_days
 * @property string $competence_offset
 * @property int|null $target_lead_days
 * @property bool $cascade_execution
 * @property array<int, string>|null $association_regimes
 * @property array<int, int>|null $association_tag_ids
 * @property array<int, int>|null $extra_client_ids
 * @property array<int, int>|null $excluded_client_ids
 */
#[Fillable(['account_id', 'title', 'description', 'status', 'source', 'marketplace_process_id', 'recurrence_interval', 'recurrence_unit', 'due_mode', 'due_day', 'estimated_duration_days', 'competence_offset', 'target_lead_days', 'cascade_execution', 'association_regimes', 'association_tag_ids', 'extra_client_ids', 'excluded_client_ids'])]
class WorkProcess extends Model
{
    /** @use HasFactory<WorkProcessFactory> */
    use BelongsToAccount, HasFactory;

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<WorkProcessTaskDefinition, $this>
     */
    public function definitions(): HasMany
    {
        return $this->hasMany(WorkProcessTaskDefinition::class);
    }

    /**
     * @return HasMany<WorkProcessClient, $this>
     */
    public function processClients(): HasMany
    {
        return $this->hasMany(WorkProcessClient::class);
    }

    /**
     * @return BelongsToMany<Client, $this>
     */
    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'work_process_clients');
    }

    /**
     * @return HasMany<WorkTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(WorkTask::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cascade_execution' => 'boolean',
            'association_regimes' => 'array',
            'association_tag_ids' => 'array',
            'extra_client_ids' => 'array',
            'excluded_client_ids' => 'array',
        ];
    }
}
