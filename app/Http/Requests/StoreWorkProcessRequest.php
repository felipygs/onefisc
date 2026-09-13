<?php

namespace App\Http\Requests;

use App\Models\WorkProcess;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', WorkProcess::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = CurrentAccount::resolve();
        $accountId = $current !== null ? $current->id : $this->user()?->account_id;

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', 'in:active,archived'],
            'recurrence_interval' => ['nullable', 'integer', 'min:0'],
            'recurrence_unit' => ['nullable', 'string', 'in:none,day,week,month,year'],
            'due_mode' => ['nullable', 'string', 'in:fixed_day,estimated'],
            'due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'estimated_duration_days' => ['nullable', 'integer', 'min:0'],
            'competence_offset' => ['nullable', 'string', 'in:due_month,previous_month'],
            'target_lead_days' => ['nullable', 'integer', 'min:0'],
            'cascade_execution' => ['nullable', 'boolean'],
            'association_regimes' => ['nullable', 'array'],
            'association_regimes.*' => ['string'],
            'association_tag_ids' => ['nullable', 'array'],
            'association_tag_ids.*' => ['integer'],
            'extra_client_ids' => ['nullable', 'array'],
            'extra_client_ids.*' => ['integer'],
            'excluded_client_ids' => ['nullable', 'array'],
            'excluded_client_ids.*' => ['integer'],
            'definitions' => ['nullable', 'array'],
            'definitions.*.title' => ['required', 'string', 'max:255'],
            'definitions.*.position' => ['nullable', 'integer', 'min:0'],
            'definitions.*.description' => ['nullable', 'string'],
            'definitions.*.due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'definitions.*.competence_offset' => ['nullable', 'string', 'in:due_month,previous_month'],
            'definitions.*.priority' => ['nullable', 'string', 'in:low,medium,high,urgent'],
            'definitions.*.default_assigned_user_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('account_id', $accountId),
            ],
            'definitions.*.department_id' => [
                'nullable', 'integer',
                Rule::exists('account_departments', 'id')->where('account_id', $accountId),
            ],
            'definitions.*.requires_document' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'O título é obrigatório.',
            'status.in' => 'O status deve ser ativo ou arquivado.',
            'recurrence_interval.min' => 'O intervalo de recorrência não pode ser negativo.',
            'recurrence_unit.in' => 'A unidade de recorrência é inválida.',
            'due_mode.in' => 'O modo de vencimento é inválido.',
            'due_day.min' => 'O dia de vencimento deve ser entre 1 e 31.',
            'due_day.max' => 'O dia de vencimento deve ser entre 1 e 31.',
            'estimated_duration_days.min' => 'A duração estimada não pode ser negativa.',
            'competence_offset.in' => 'O deslocamento de competência é inválido.',
            'target_lead_days.min' => 'A antecedência da meta não pode ser negativa.',
            'definitions.*.title.required' => 'Cada item do checklist precisa de um título.',
            'definitions.*.due_day.min' => 'O dia de vencimento do item deve ser entre 1 e 31.',
            'definitions.*.due_day.max' => 'O dia de vencimento do item deve ser entre 1 e 31.',
            'definitions.*.default_assigned_user_id.exists' => 'O responsável padrão deve ser um membro da Account.',
            'definitions.*.department_id.exists' => 'O departamento deve pertencer à Account.',
        ];
    }
}
