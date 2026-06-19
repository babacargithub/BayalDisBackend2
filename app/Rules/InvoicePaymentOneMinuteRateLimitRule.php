<?php

namespace App\Rules;

use App\Data\Vente\VenteStatsFilter;
use App\Services\PaymentService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a payment if an identical amount was already recorded on the same
 * sales invoice less than one minute ago.
 *
 * Guards against accidental double-submission caused by poor connectivity
 * in the field: the mobile app may fire the same request twice before the
 * first response is received.
 */
readonly class InvoicePaymentOneMinuteRateLimitRule implements ValidationRule
{
    public function __construct(
        private int $salesInvoiceId,
        private PaymentService $paymentService,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $recentDuplicateExists = $this->paymentService
            ->paymentsQuery(VenteStatsFilter::new()->inDateInterval(now()->subMinute(), null))
            ->where('sales_invoice_id', $this->salesInvoiceId)
            ->where('amount', $value)
            ->exists();

        if ($recentDuplicateExists) {
            $fail(
                'Un paiement de ce montant a déjà été enregistré pour cette facture '.
                "il y a moins d'une minute. Veuillez patienter au moins 1 minute ".
                "avant d'enregistrer un nouveau paiement."
            );
        }
    }
}
