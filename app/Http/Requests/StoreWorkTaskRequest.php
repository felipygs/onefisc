<?php

namespace App\Http\Requests;

use App\Models\WorkTask;
use App\Policies\WorkTaskPolicy;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Same scoped pattern as the process requests: without an account the
        // global scope would not apply and existence could leak, so fail 404.
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);

        $user = $this->user();

        if ($user === null || ! $user->can('create', WorkTask::class)) {
            return false;
        }

        if (in_array($user->role, WorkTaskPolicy::MANAGING_ROLES, true)) {
            return true;
        }

        // Collaborators (`user`) create only under clients assigned to them.
        // A missing or foreign client denies here with 403; same-account
        // membership itself is validated with 422 by the rules below.
        $clientId = $this->input('client_id');

        if (! is_numeric($clientId)) {
            return false;
        }

        return (new WorkTaskPolicy)->isClientAssignee($user, (int) $clientId);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = CurrentAccount::resolve();
        $accountId = $current !== null ? $current->id : $this->user()?->account_id;

        return [
            'work_process_id' => [
                'required', 'integer',
                Rule::exists('work_processes', 'id')->where('account_id', $accountId),
            ],
            'client_id' => [
                'required', 'integer',
                Rule::exists('clients', 'id')->where('account_id', $accountId),
            ],
            'title' => ['required', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:backlog,todo,in_progress,done'],
            'position' => ['sometimes', 'integer', 'min:0'],
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
            'work_process_id.required' => 'O processo é obrigatório.',
            'work_process_id.exists' => 'O processo não pertence a esta Account.',
            'client_id.required' => 'A empresa é obrigatória.',
            'client_id.exists' => 'A empresa não pertence a esta Account.',
            'title.required' => 'O título é obrigatório.',
            'status.in' => 'O status deve ser backlog, todo, in_progress ou done.',
            'position.min' => 'A posição não pode ser negativa.',
            'priority.in' => 'A prioridade deve ser low, medium, high ou urgent.',
            'assigned_user_id.exists' => 'O responsável deve ser um membro da Account.',
            'department_id.exists' => 'O departamento deve pertencer à Account.',
            'start_at.date' => 'A data de início deve ser uma data válida.',
            'due_on.date' => 'A data de vencimento deve ser uma data válida.',
        ];
    }
}
