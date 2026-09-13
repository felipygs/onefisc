<?php

namespace App\Models;

use Database\Factories\WorkProcessTaskDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Scoped through the parent process (no account_id column): consumers MUST
 * query via WorkProcess (BelongsToAccount) or an explicit work_process_id.
 *
 * @property int $id
 * @property int $work_process_id
 * @property string $title
 * @property int $position
 * @property string|null $description
 * @property int|null $due_day
 * @property string|null $competence_offset
 * @property string $priority
 * @property int|null $default_assigned_user_id
 * @property int|null $department_id
 * @property bool $requires_document
 */
#[Fillable(['work_process_id', 'title', 'position', 'description', 'due_day', 'competence_offset', 'priority', 'default_assigned_user_id', 'department_id', 'requires_document'])]
class WorkProcessTaskDefinition extends Model
{
    /** @use HasFactory<WorkProcessTaskDefinitionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<WorkProcess, $this>
     */
    public function process(): BelongsTo
    {
        return $this->belongsTo(WorkProcess::class, 'work_process_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function defaultAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_assigned_user_id');
    }

    /**
     * @return BelongsTo<AccountDepartment, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(AccountDepartment::class, 'department_id');
    }

    /**
     * @return HasMany<WorkTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(WorkTask::class, 'work_process_task_definition_id');
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
