<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone_number' => ['required', 'numeric', 'digits:9', 'unique:customers,phone_number'],
            'owner_number' => ['required', 'numeric', 'digits:9'],
            'gps_coordinates' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'address' => ['nullable', 'string', 'max:255'],
            'customer_category_id' => ['nullable', 'exists:customer_categories,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Le nom est obligatoire',
            'name.string' => 'Le nom doit être une chaîne de caractères',
            'name.max' => 'Le nom ne doit pas dépasser 255 caractères',
            'phone_number.required' => 'Le numéro de téléphone est obligatoire',
            'phone_number.numeric' => 'Le numéro de téléphone doit être numérique',
            'phone_number.digits' => 'Le numéro de téléphone doit contenir 9 chiffres',
            'phone_number.unique' => 'Ce numéro de téléphone est déjà utilisé',
            'owner_number.required' => 'Le numéro du propriétaire est obligatoire',
            'owner_number.numeric' => 'Le numéro du propriétaire doit être numérique',
            'owner_number.digits' => 'Le numéro du propriétaire doit contenir 9 chiffres',
            'gps_coordinates.required' => 'Les coordonnées GPS sont obligatoires',
            'customer_category_id.exists' => 'La catégorie sélectionnée n\'existe pas',
        ];
    }
}
