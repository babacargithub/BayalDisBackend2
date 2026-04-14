<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BorrowFromAccountRequest extends FormRequest
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
            'debtor_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'creditor_account_id' => ['required', 'integer', 'exists:accounts,id', 'different:debtor_account_id'],
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'debtor_account_id.required' => 'Le compte emprunteur est obligatoire.',
            'debtor_account_id.exists' => 'Le compte emprunteur sélectionné est introuvable.',
            'creditor_account_id.required' => 'Le compte prêteur est obligatoire.',
            'creditor_account_id.exists' => 'Le compte prêteur sélectionné est introuvable.',
            'creditor_account_id.different' => 'Le compte emprunteur et le compte prêteur doivent être différents.',
            'amount.required' => 'Le montant est obligatoire.',
            'amount.min' => 'Le montant doit être supérieur à zéro.',
            'reason.required' => 'Le motif de l\'emprunt est obligatoire.',
        ];
    }
}
