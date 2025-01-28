<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string'],
            'resulted_in_sale' => ['required', 'boolean'],
            'gps_coordinates' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'resulted_in_sale.required' => 'Le résultat de la visite est obligatoire',
            'resulted_in_sale.boolean' => 'Le résultat de la visite doit être vrai ou faux',
        ];
    }
} 