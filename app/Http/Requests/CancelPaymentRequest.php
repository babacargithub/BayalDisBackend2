<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cancellation_reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'cancellation_reason.required' => "Le motif d'annulation est obligatoire.",
            'cancellation_reason.string' => "Le motif d'annulation doit être un texte.",
            'cancellation_reason.max' => "Le motif d'annulation ne peut pas dépasser 500 caractères.",
        ];
    }
}
