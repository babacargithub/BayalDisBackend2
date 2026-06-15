<?php

namespace App\Http\Requests\Api;

use App\Models\Customer;
use App\Models\Vente;
use App\Rules\CustomerBulkPaymentOneMinuteRateLimitRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkPayCustomerInvoicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    private function resolveCustomerId(): ?int
    {
        $routeParam = $this->route('customer');

        if ($routeParam instanceof Customer) {
            return $routeParam->id;
        }

        return $routeParam ? (int) $routeParam : null;
    }

    public function rules(): array
    {
        $customerId = $this->resolveCustomerId();

        $amountRules = ['required', 'integer', 'min:1'];

        if ($customerId !== null) {
            $amountRules[] = new CustomerBulkPaymentOneMinuteRateLimitRule($customerId);
        }

        return [
            'amount' => $amountRules,
            'payment_method' => [
                'required',
                'string',
                Rule::in([
                    Vente::PAYMENT_METHOD_CASH,
                    Vente::PAYMENT_METHOD_WAVE,
                    Vente::PAYMENT_METHOD_OM,
                ]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Le montant est obligatoire',
            'amount.integer' => 'Le montant doit être un nombre entier',
            'amount.min' => 'Le montant doit être au moins 1 F',
            'payment_method.required' => 'Vous devez choisir un moyen de paiement',
            'payment_method.in' => 'La méthode de paiement doit être CASH, WAVE ou OM',
        ];
    }
}
