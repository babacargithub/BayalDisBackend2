<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class AddCustomersToBeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_ids' => ['required', 'array'],
            'customer_ids.*' => ['required', 'integer', 'exists:customers,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_ids.required' => 'La liste des clients est obligatoire',
            'customer_ids.array' => 'La liste des clients doit être un tableau',
            'customer_ids.*.integer' => 'Chaque identifiant client doit être un entier',
            'customer_ids.*.exists' => 'Un ou plusieurs clients n\'existent pas',
        ];
    }
}
