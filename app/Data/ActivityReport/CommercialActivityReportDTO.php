<?php

namespace App\Data\ActivityReport;

use Illuminate\Support\Str;

/**
 * Holds the computed activity statistics for a commercial over a given period.
 *
 * Totals are derived from stored columns on sales_invoices (total_amount, total_payments)
 * and from the payments table for payment-method breakdowns, so no raw vente queries
 * are needed and the numbers are always consistent with the invoice lifecycle.
 *
 * All money values are integers (XOF).
 * All counts are integers.
 */
readonly class CommercialActivityReportDTO
{
    public function __construct(
        /** Gross revenue = SUM(sales_invoices.total_amount) for invoices created in the period. */
        public int $totalSales,

        /** Total cash collected = SUM(payments.amount) for payments made in the period. */
        public int $totalPayments,

        /** New non-prospect customers created in the period for this commercial. */
        public int $newConfirmedCustomersCount,

        /** New prospect customers created in the period for this commercial. */
        public int $newProspectCustomersCount,

        /**
         * Outstanding balance on invoices created in the period.
         * Computed as SUM(total_amount - total_payments) across those invoices.
         */
        public int $totalUnpaidAmount,

        /** Portion of totalPayments collected via Wave transfer. */
        public int $totalPaymentsWave,

        /** Portion of totalPayments collected via Orange Money. */
        public int $totalPaymentsOm,

        /** Portion of totalPayments collected in cash. */
        public int $totalPaymentsCash,
    ) {}

    /**
     * Serialise to a snake_case array for JSON API responses.
     * Automatically reflects all public properties — no manual update needed when adding fields.
     */
    public function toSnakeCaseArray(): array
    {
        return collect(get_object_vars($this))
            ->mapWithKeys(fn ($value, $camelCaseKey) => [
                Str::snake($camelCaseKey) => $value,
            ])
            ->all();
    }
}
