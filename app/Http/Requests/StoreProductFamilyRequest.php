<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductFamilyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:product_families,name'],
            'product_category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Le nom de la famille est obligatoire.',
            'name.unique' => 'Une famille avec ce nom existe déjà.',
            'name.max' => 'Le nom de la famille ne doit pas dépasser 255 caractères.',
            'product_category_id.exists' => 'La catégorie sélectionnée n\'existe pas.',
        ];
    }
}
