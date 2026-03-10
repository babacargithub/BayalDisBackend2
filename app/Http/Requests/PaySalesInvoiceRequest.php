<?php

namespace App\Http\Requests;

use App\Models\Vente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaySalesInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
            'payment_method' => [
                'required',
                'string',
                Rule::in([
                    Vente::PAYMENT_METHOD_CASH,
                    Vente::PAYMENT_METHOD_WAVE,
                    Vente::PAYMENT_METHOD_OM,
                    strtoupper(Vente::PAYMENT_METHOD_CASH),
                    strtoupper(Vente::PAYMENT_METHOD_WAVE),
                    strtoupper(Vente::PAYMENT_METHOD_OM),
                ]),
            ],
            'comment' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Le montant est obligatoire',
            'amount.integer' => 'Le montant doit être un nombre entier',
            'amount.min' => 'Le montant doit être au moins 1',
            'payment_method.required' => 'Vous devez choisir un moyen de paiement',
            'payment_method.in' => 'La méthode de paiement doit être Cash, Wave ou Om',
        ];
    }
}
