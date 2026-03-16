<?php

namespace App\Data\Commission;

/**
 * Represents the commission earned on a single product within a payment.
 *
 * A single payment can cover an invoice with multiple products; this DTO
 * captures the breakdown per product line.
 */
readonly class CommissionPaymentLineData
{
    public function __construct(
        public int $paymentId,
        public int $productId,
        /** Rate frozen at computation time, e.g. 0.0100 for 1%. */
        public float $rateApplied,
        /** XOF portion of the payment allocated to this product (prorated by revenue share). */
        public int $paymentAmountAllocated,
        /** commission_amount = round(paymentAmountAllocated × rateApplied) */
        public int $commissionAmount,
    ) {}
}
