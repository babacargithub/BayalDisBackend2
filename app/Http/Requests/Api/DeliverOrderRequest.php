<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class DeliverOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'paid' => ['required', 'boolean'],
            'payment_method' => ['required_if:paid,true', 'nullable', 'string', 'in:CASH,WAVE,OM,FREE'],
            'should_be_paid_at' => ['required_if:paid,false', 'nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'paid.required' => 'Le statut de paiement est obligatoire',
            'paid.boolean' => 'Le statut de paiement doit être vrai ou faux',
            'payment_method.required_if' => 'Vous devez choisir un moyen de paiement si la commande est payée',
            'payment_method.in' => 'La méthode de paiement doit être CASH, WAVE, OM ou FREE',
            'should_be_paid_at.required_if' => 'Vous devez préciser l\'échéance car la commande n\'est pas payée',
            'should_be_paid_at.date' => 'La date d\'échéance n\'est pas valide',
        ];
    }
}
