<?php

namespace App\Data\Payment;

/**
 * Result of a bulk multi-invoice payment for a single customer.
 *
 * - totalAmountDistributed : total XOF distributed across invoices (equals the input amount).
 * - invoicePayments        : per-invoice breakdown — which invoice received how much and whether
 *                            it is now fully settled.
 */
readonly class BulkCustomerPaymentResultData
{
    /**
     * @param  array<int, array{invoice_id: int, invoice_number: string, amount_paid: int, was_fully_paid: bool}>  $invoicePayments
     */
    public function __construct(
        public int $totalAmountDistributed,
        public array $invoicePayments,
    ) {}
}
