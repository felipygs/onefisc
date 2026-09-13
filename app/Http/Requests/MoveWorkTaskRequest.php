<?php

namespace App\Http\Requests;

use App\Models\WorkTask;
use App\Policies\WorkTaskPolicy;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Http\FormRequest;

class MoveWorkTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Same scoped pattern as the update request: foreign or unassigned
        // ids 404 here, before the move policy can answer 403.
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);
        $task = WorkTaskPolicy::scopeVisible(
            WorkTask::query()->where('work_tasks.account_id', $account->id),
            $this->user()
        )->findOrFail($this->route('task'));

        return $this->user()?->can('move', $task) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:backlog,todo,in_progress,done'],
            'position' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'O status é obrigatório.',
            'status.in' => 'O status deve ser backlog, todo, in_progress ou done.',
            'position.required' => 'A posição é obrigatória.',
            'position.integer' => 'A posição deve ser um número inteiro.',
            'position.min' => 'A posição não pode ser negativa.',
        ];
    }
}
