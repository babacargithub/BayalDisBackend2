<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddInvoiceItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'price' => ['required', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.required' => 'Le produit est obligatoire.',
            'product_id.exists' => 'Le produit sélectionné n\'existe pas.',
            'quantity.required' => 'La quantité est obligatoire.',
            'quantity.integer' => 'La quantité doit être un nombre entier.',
            'quantity.min' => 'La quantité doit être au moins 1.',
            'price.required' => 'Le prix est obligatoire.',
            'price.integer' => 'Le prix doit être un nombre entier.',
            'price.min' => 'Le prix doit être supérieur ou égal à 0.',
        ];
    }
}
