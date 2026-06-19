<?php

namespace App\Http\Requests\Api;

use App\Models\SalesInvoice;
use App\Models\Vente;
use App\Rules\InvoicePaymentOneMinuteRateLimitRule;
use App\Services\PaymentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaySalesInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Resolve the SalesInvoice ID from whichever route parameter is present.
     *
     * Web route uses {salesInvoice}, API route uses {vente}.
     * The parameter may already be a resolved model or a raw integer key.
     */
    private function resolveSalesInvoiceId(): ?int
    {
        // Web route uses {salesInvoice}; modern API route uses {invoice}; legacy API route uses {vente}.
        $routeParam = $this->route('salesInvoice') ?? $this->route('invoice') ?? $this->route('vente');

        if ($routeParam instanceof SalesInvoice) {
            return $routeParam->id;
        }

        return $routeParam ? (int) $routeParam : null;
    }

    public function rules(): array
    {
        $salesInvoiceId = $this->resolveSalesInvoiceId();

        $amountRules = ['required', 'integer', 'min:1'];

        if ($salesInvoiceId !== null) {
            $amountRules[] = new InvoicePaymentOneMinuteRateLimitRule($salesInvoiceId, app(PaymentService::class));
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
            'comment' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Le montant est obligatoire',
            'amount.integer' => 'Le montant doit être un nombre entier',
            'amount.min' => 'Le montant doit être au moins 1',
            'payment_method.required' => 'Vous devez choisir un moyen de paiement',
            'payment_method.in' => 'La méthode de paiement doit être CASH, WAVE ou OM',
        ];
    }
}
