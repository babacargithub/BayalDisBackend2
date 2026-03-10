<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['required', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Au moins un article est obligatoire',
            'items.array' => 'Les articles doivent être une liste',
            'items.min' => 'La commande doit contenir au moins un article',
            'items.*.product_id.required' => 'Le produit est obligatoire pour chaque article',
            'items.*.product_id.exists' => 'Un produit sélectionné n\'existe pas',
            'items.*.quantity.required' => 'La quantité est obligatoire pour chaque article',
            'items.*.quantity.integer' => 'La quantité doit être un nombre entier',
            'items.*.quantity.min' => 'La quantité doit être au moins 1',
            'items.*.price.required' => 'Le prix est obligatoire pour chaque article',
            'items.*.price.integer' => 'Le prix doit être un nombre entier',
            'items.*.price.min' => 'Le prix doit être supérieur ou égal à 0',
        ];
    }
}
