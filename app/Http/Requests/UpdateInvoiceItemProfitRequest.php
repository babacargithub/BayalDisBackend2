<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceItemProfitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'profit' => ['required', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'profit.required' => 'Le bénéfice est obligatoire.',
            'profit.integer' => 'Le bénéfice doit être un nombre entier.',
        ];
    }
}
