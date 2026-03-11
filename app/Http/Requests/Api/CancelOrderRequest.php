<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CancelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'comment' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'comment.required' => 'La raison de l\'annulation est obligatoire',
        ];
    }
}
