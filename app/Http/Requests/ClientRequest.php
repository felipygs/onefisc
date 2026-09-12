<?php

namespace App\Http\Requests;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->attributes->get('account') ?? $this->user()?->account;

        if (! $account) {
            return false;
        }

        return $this->user()?->can('operate-clients', $account) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $accountId = $this->attributes->get('account')?->id ?? $this->user()?->account_id;
        $clientId = $this->route('client') instanceof Client ? $this->route('client')->id : null;

        return [
            'cnpj' => [
                'required', 'string', 'size:14',
                Rule::unique('clients', 'cnpj')
                    ->where('account_id', $accountId)
                    ->ignore($clientId),
            ],
            'razao_social' => ['required', 'string', 'max:255'],
            'regime' => ['required', 'string', 'in:simples,presumido,real,mei'],
            'contador_responsavel' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cnpj.required' => 'O CNPJ é obrigatório.',
            'razao_social.required' => 'A razão social é obrigatória.',
            'regime.required' => 'O regime tributário é obrigatório.',
            'contador_responsavel.required' => 'O contador responsável é obrigatório.',
        ];
    }
}
