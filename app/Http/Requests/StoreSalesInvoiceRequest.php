<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalesInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'should_be_paid_at' => ['required', 'date'],
            'comment' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.required' => 'Le client est obligatoire.',
            'customer_id.exists' => 'Le client sélectionné n\'existe pas.',
            'items.required' => 'Au moins un article est obligatoire.',
            'items.min' => 'La facture doit contenir au moins un article.',
            'items.*.product_id.required' => 'Le produit est obligatoire pour chaque article.',
            'items.*.product_id.exists' => 'Un produit sélectionné n\'existe pas.',
            'items.*.quantity.required' => 'La quantité est obligatoire pour chaque article.',
            'items.*.quantity.integer' => 'La quantité doit être un nombre entier.',
            'items.*.quantity.min' => 'La quantité doit être au moins 1.',
            'items.*.price.required' => 'Le prix est obligatoire pour chaque article.',
            'items.*.price.min' => 'Le prix doit être supérieur ou égal à 0.',
            'should_be_paid_at.required' => 'La date d\'échéance est obligatoire.',
            'should_be_paid_at.date' => 'La date d\'échéance n\'est pas valide.',
        ];
    }
}
