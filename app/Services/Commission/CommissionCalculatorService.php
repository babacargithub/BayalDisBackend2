<?php

namespace App\Services\Commission;

use App\Data\Commission\CommissionPaymentLineData;
use App\Models\Commercial;
use App\Models\Payment;
use App\Models\SalesInvoice;
use App\Models\Vente;

/**
 * Computes commission payment lines for a single Payment.
 *
 * A payment may cover a multi-product invoice. Commission is allocated per
 * product proportionally to each product's revenue share within the invoice:
 *
 *   product_share = (item_price × item_quantity) / invoice_total_amount
 *   allocated = round(payment.amount × product_share)
 *   commission = round(allocated × rate)
 *
 * The rate used is resolved via CommissionRateResolverService (product-level
 * override first, then category-level default, then 0).
 */
readonly class CommissionCalculatorService
{
    public function __construct(
        private CommissionRateResolverService $commissionRateResolverService,
    ) {}

    /**
     * Returns one CommissionPaymentLineData per product present in the payment's invoice.
     * Products with a resolved rate of 0 are included with commission_amount = 0
     * so they can still count towards basket checks.
     *
     * Returns an empty array if the payment has no linked SalesInvoice or if the
     * invoice has no items.
     *
     * @return CommissionPaymentLineData[]
     */
    public function computePaymentLinesForCommercial(Payment $payment, Commercial $commercial): array
    {
        if ($payment->sales_invoice_id === null) {
            return [];
        }

        /** @var SalesInvoice|null $salesInvoice */
        $salesInvoice = SalesInvoice::with('items.product')->find($payment->sales_invoice_id);

        if ($salesInvoice === null || $salesInvoice->total_amount === 0) {
            return [];
        }

        $invoiceTotalAmount = $salesInvoice->total_amount;
        $paymentAmount = $payment->amount;

        $paymentLines = [];

        /** @var Vente $invoiceItem */
        foreach ($salesInvoice->items as $invoiceItem) {
            $product = $invoiceItem->product;

            if ($product === null) {
                continue;
            }

            $itemSubtotal = $invoiceItem->price * $invoiceItem->quantity;

            // Proportional allocation of the payment to this product line.
            $allocatedPaymentAmount = (int) round($paymentAmount * ($itemSubtotal / $invoiceTotalAmount));

            $rateApplied = $this->commissionRateResolverService
                ->resolveRateForCommercialAndProduct($commercial, $product);

            $commissionAmount = (int) round($allocatedPaymentAmount * $rateApplied);

            $paymentLines[] = new CommissionPaymentLineData(
                paymentId: $payment->id,
                productId: $product->id,
                rateApplied: $rateApplied,
                paymentAmountAllocated: $allocatedPaymentAmount,
                commissionAmount: $commissionAmount,
            );
        }

        return $paymentLines;
    }
}
