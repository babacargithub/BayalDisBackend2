<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'account_type' => ['required', Rule::enum(AccountType::class)],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'commercial_id' => ['nullable', 'integer', 'exists:commercials,id'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Le nom du compte est obligatoire.',
            'account_type.required' => 'Le type de compte est obligatoire.',
            'account_type.enum' => 'Le type de compte sélectionné est invalide.',
            'vehicle_id.exists' => 'Le véhicule sélectionné est introuvable.',
            'commercial_id.exists' => 'Le commercial sélectionné est introuvable.',
        ];
    }
}
