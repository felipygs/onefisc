<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use App\Support\WorkCompetence;
use App\Support\WorkDueDates;
use Database\Factories\WorkTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
 * @property-read string|null $target_date
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
     * Competence matching shared by the task list, the process tree and the
     * board (single rule implementation lives in WorkCompetence). Null
     * means no filter — `?competence` is explicit only, never defaulted.
     *
     * @param  Builder<WorkTask>  $query
     * @return Builder<WorkTask>
     */
    public function scopeForCompetence(Builder $query, ?string $competence): Builder
    {
        return WorkCompetence::applyScope($query, $competence);
    }

    /**
     * THE shared materialization row-builder (Task 3.2): both the 2.2
     * association-apply (timeless, null competence ⇒ dateless) and the 3.1
     * competence-open (dated ⇒ computed due) call this — no duplicated
     * cascade/status/date logic. `target_date` stays derived (no column).
     *
     * @return array<string, mixed>
     */
    public static function materializedRow(
        WorkProcess $process,
        WorkProcessTaskDefinition $definition,
        int $accountId,
        int $clientId,
        ?string $competence,
        int $firstPosition,
        \DateTimeInterface $now,
    ): array {
        $dates = WorkDueDates::forTask($process, $definition, $competence);

        return [
            'account_id' => $accountId,
            'work_process_id' => $process->id,
            'client_id' => $clientId,
            'work_process_task_definition_id' => $definition->id,
            'title' => $definition->title,
            'status' => $process->cascade_execution && (int) $definition->position !== $firstPosition ? 'backlog' : 'todo',
            'position' => $definition->position,
            'priority' => $definition->priority ?? 'medium',
            'assigned_user_id' => $definition->default_assigned_user_id,
            'department_id' => $definition->department_id,
            'competence' => $competence,
            'start_at' => null,
            'due_on' => $dates['due_on'],
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Derived prazo-meta (Task 3.2): due minus the process lead, live from
     * the current process config — never stored, never scattered math.
     * Central accessor backing every task payload (list/detail/tree/board).
     */
    public function getTargetDateAttribute(): ?string
    {
        if ($this->due_on === null) {
            return null;
        }

        $lead = $this->process?->target_lead_days;

        if ($lead === null) {
            return null;
        }

        $lead = (int) $lead;

        if ($lead < 0) {
            return null;
        }

        try {
            $due = Carbon::parse($this->due_on);
        } catch (\Throwable) {
            return null;
        }

        return $due->subDays($lead)->format('Y-m-d');
    }

    /**
     * Derived `target_date` rides every serialization so list/detail/tree
     * and board payloads share the single accessor above.
     *
     * @var list<string>
     */
    protected $appends = ['target_date'];

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
