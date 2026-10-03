<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductFamilyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('product_families', 'name')->ignore($this->route('product_family')),
            ],
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
