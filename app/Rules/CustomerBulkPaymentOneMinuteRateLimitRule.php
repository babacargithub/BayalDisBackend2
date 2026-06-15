<?php

namespace App\Rules;

use App\Models\Payment;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;

/**
 * Rejects a bulk customer payment if the same total amount was already distributed
 * across this customer's invoices less than one minute ago.
 *
 * Guards against accidental double-submission on poor field connectivity:
 * the mobile app may fire the same bulk-pay request twice before the first
 * response arrives. Since one bulk payment fans out to multiple individual
 * payments, we detect it by summing all payments on the customer's invoices
 * within the last minute and comparing to the submitted total.
 */
readonly class CustomerBulkPaymentOneMinuteRateLimitRule implements ValidationRule
{
    public function __construct(private int $customerId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $recentPaymentsSumForCustomerInvoices = (int) Payment::query()
            ->whereHas(
                'salesInvoice',
                fn (Builder $query) => $query->where('customer_id', $this->customerId)
            )
            ->where('created_at', '>=', now()->subMinute())
            ->sum('amount');

        if ($recentPaymentsSumForCustomerInvoices === (int) $value) {
            $fail(
                'Un paiement groupé de ce montant a déjà été enregistré pour ce client '.
                "il y a moins d'une minute. Veuillez patienter au moins 1 minute ".
                "avant d'enregistrer un nouveau paiement groupé."
            );
        }
    }
}
