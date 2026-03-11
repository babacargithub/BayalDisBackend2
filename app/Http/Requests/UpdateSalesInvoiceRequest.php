<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSalesInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'paid' => ['sometimes', 'boolean'],
            'should_be_paid_at' => ['nullable', 'date'],
            'comment' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'paid.boolean' => 'Le statut de paiement doit être vrai ou faux.',
            'should_be_paid_at.date' => 'La date d\'échéance n\'est pas valide.',
        ];
    }
}
