<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransferBetweenAccountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'to_account_id' => ['required', 'integer', 'exists:accounts,id', 'different:from_account_id'],
            'amount' => ['required', 'integer', 'min:1'],
            'label' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'from_account_id.required' => 'Le compte source est obligatoire.',
            'from_account_id.exists' => 'Le compte source sélectionné est introuvable.',
            'to_account_id.required' => 'Le compte destination est obligatoire.',
            'to_account_id.exists' => 'Le compte destination sélectionné est introuvable.',
            'to_account_id.different' => 'Le compte source et le compte destination doivent être différents.',
            'amount.required' => 'Le montant est obligatoire.',
            'amount.min' => 'Le montant doit être supérieur à zéro.',
            'label.required' => 'Le libellé est obligatoire.',
        ];
    }
}
