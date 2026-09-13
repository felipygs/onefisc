<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Database\Factories\WorkTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $account_id
 * @property int $work_process_id
 * @property int $client_id
 * @property int|null $work_process_task_definition_id
 * @property string $title
 * @property string $status
 * @property int $position
 * @property string $priority
 * @property int|null $assigned_user_id
 * @property int|null $department_id
 * @property string|null $competence
 * @property Carbon|null $start_at
 * @property Carbon|null $due_on
 */
#[Fillable(['account_id', 'work_process_id', 'client_id', 'work_process_task_definition_id', 'title', 'status', 'position', 'priority', 'assigned_user_id', 'department_id', 'competence', 'start_at', 'due_on'])]
class WorkTask extends Model
{
    /** @use HasFactory<WorkTaskFactory> */
    use BelongsToAccount, HasFactory;

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<WorkProcess, $this>
     */
    public function process(): BelongsTo
    {
        return $this->belongsTo(WorkProcess::class, 'work_process_id');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<WorkProcessTaskDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkProcessTaskDefinition::class, 'work_process_task_definition_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * @return BelongsTo<AccountDepartment, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(AccountDepartment::class, 'department_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_at' => 'date',
            'due_on' => 'date',
        ];
    }
}
