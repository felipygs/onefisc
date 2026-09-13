<?php

namespace App\Http\Requests;

use App\Models\WorkTask;
use App\Policies\WorkTaskPolicy;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Scoped lookup runs after account middleware, so foreign ids fail
        // with 404 here instead of leaking a 403 from the policy. The query
        // also carries collaborator visibility, so tasks of unassigned
        // clients 404 for `user` collaborators instead of 403.
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);
        $task = WorkTaskPolicy::scopeVisible(
            WorkTask::query()->where('work_tasks.account_id', $account->id),
            $this->user()
        )->findOrFail($this->route('task'));

        return $this->user()?->can('update', $task) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = CurrentAccount::resolve();
        $accountId = $current !== null ? $current->id : $this->user()?->account_id;

        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:backlog,todo,in_progress,done'],
            'priority' => ['sometimes', 'string', 'in:low,medium,high,urgent'],
            'assigned_user_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('users', 'id')->where('account_id', $accountId),
            ],
            'department_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('account_departments', 'id')->where('account_id', $accountId),
            ],
            'start_at' => ['sometimes', 'nullable', 'date'],
            'due_on' => ['sometimes', 'nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'O título é obrigatório.',
            'status.in' => 'O status deve ser backlog, todo, in_progress ou done.',
            'priority.in' => 'A prioridade deve ser low, medium, high ou urgent.',
            'assigned_user_id.exists' => 'O responsável deve ser um membro da Account.',
            'department_id.exists' => 'O departamento deve pertencer à Account.',
            'start_at.date' => 'A data de início deve ser uma data válida.',
            'due_on.date' => 'A data de vencimento deve ser uma data válida.',
        ];
    }
}
