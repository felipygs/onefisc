<?php

namespace App\Http\Requests;

use App\Models\WorkProcess;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkProcessClientsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Same scoped pattern as UpdateWorkProcessRequest: no implicit
        // binding, foreign or malformed ids 404 before the policy can 403.
        abort_unless(CurrentAccount::resolve() !== null, 404);
        $process = WorkProcess::query()->findOrFail($this->route('process'));

        return $this->user()?->can('update', $process) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = CurrentAccount::resolve();
        $accountId = $current !== null ? $current->id : $this->user()?->account_id;

        return [
            'client_ids' => ['nullable', 'array'],
            'client_ids.*' => [
                'integer',
                Rule::exists('clients', 'id')->where('account_id', $accountId),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_ids.array' => 'A lista de clientes deve ser um array.',
            'client_ids.*.integer' => 'Cada cliente deve ser um identificador válido.',
            'client_ids.*.exists' => 'Um dos clientes não pertence a esta Account.',
        ];
    }
}
