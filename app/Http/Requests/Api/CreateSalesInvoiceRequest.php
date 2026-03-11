<?php

namespace App\Http\Requests\Api;

use App\Models\Vente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateSalesInvoiceRequest extends FormRequest
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
            'items.*.price' => ['required', 'integer', 'min:1'],
            'paid' => ['required', 'boolean'],
            'payment_method' => [
                Rule::requiredIf($this->boolean('paid')),
                'nullable',
                'string',
                Rule::in([
                    Vente::PAYMENT_METHOD_CASH,
                    Vente::PAYMENT_METHOD_WAVE,
                    Vente::PAYMENT_METHOD_OM,
                    strtoupper(Vente::PAYMENT_METHOD_CASH),
                    strtoupper(Vente::PAYMENT_METHOD_WAVE),
                    strtoupper(Vente::PAYMENT_METHOD_OM),
                ]),
            ],
            'should_be_paid_at' => [
                Rule::requiredIf(! $this->boolean('paid')),
                'nullable',
                'date',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.required' => 'Le client est obligatoire',
            'customer_id.exists' => 'Le client sélectionné n\'existe pas',
            'items.required' => 'Au moins un article est obligatoire',
            'items.array' => 'Les articles doivent être une liste',
            'items.min' => 'La facture doit contenir au moins un article',
            'items.*.product_id.required' => 'Le produit est obligatoire pour chaque article',
            'items.*.product_id.exists' => 'Un produit sélectionné n\'existe pas',
            'items.*.quantity.required' => 'La quantité est obligatoire pour chaque article',
            'items.*.quantity.integer' => 'La quantité doit être un nombre entier',
            'items.*.quantity.min' => 'La quantité doit être au moins 1',
            'items.*.price.required' => 'Le prix est obligatoire pour chaque article',
            'items.*.price.integer' => 'Le prix doit être un nombre entier',
            'items.*.price.min' => 'Le prix doit être supérieur à 0',
            'paid.required' => 'Le statut de paiement est obligatoire',
            'paid.boolean' => 'Le statut de paiement doit être vrai ou faux',
            'payment_method.required' => 'Vous devez choisir un moyen de paiement',
            'payment_method.in' => 'La méthode de paiement doit être Cash, Wave ou Om',
            'should_be_paid_at.required' => "Vous devez préciser l'échéance car la facture n'est pas payée",
            'should_be_paid_at.date' => "La date d'échéance n'est pas valide",
        ];
    }
}
