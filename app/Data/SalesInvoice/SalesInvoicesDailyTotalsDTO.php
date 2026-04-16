<?php

namespace App\Data\SalesInvoice;

use Illuminate\Support\Collection;

/**
 * Aggregated financial totals for a set of daily sales invoices.
 *
 * All monetary values are integers (XOF, no sub-units).
 * Built by summing the individual SalesInvoiceDailySummaryDTO values.
 */
readonly class SalesInvoicesDailyTotalsDTO
{
    public function __construct(
        public int $invoicesCount,
        public int $totalAmount,
        public int $totalPayments,
        public int $totalCommissions,
        public int $totalEstimatedProfit,
        public int $totalRealizedProfit,
        public int $totalDeliveryCost,
    ) {}

    /**
     * Net profit realized on this set of invoices.
     *
     * Formula: total_realized_profit − total_commissions − total_delivery_cost
     */
    public function netProfit(): int
    {
        return $this->totalRealizedProfit - $this->totalCommissions - $this->totalDeliveryCost;
    }

    /**
     * Build the totals DTO from a collection of timeline item DTOs.
     *
     * Only invoice rows contribute to the totals; payment rows are skipped
     * so that collecting a past-invoice payment does not double-count revenue.
     *
     * @param  Collection<int, DailySalesInvoiceItemDTO>  $timelineItems
     */
    public static function fromSummaries(Collection $timelineItems): self
    {
        $invoiceItems = $timelineItems->filter(fn (DailySalesInvoiceItemDTO $item) => $item->isInvoice());
        $paymentItems = $timelineItems->filter(fn (DailySalesInvoiceItemDTO $item) => ! $item->isInvoice());

        return new self(
            invoicesCount: $invoiceItems->count(),
            totalAmount: (int) $invoiceItems->sum('totalAmount'),
            totalPayments: (int) $invoiceItems->sum('totalPayments') + (int) $paymentItems->sum('paymentAmount'),
            totalCommissions: (int) $invoiceItems->sum('estimatedCommercialCommission'),
            totalEstimatedProfit: (int) $invoiceItems->sum('totalEstimatedProfit'),
            totalRealizedProfit: (int) $invoiceItems->sum('totalRealizedProfit') + (int) $paymentItems->sum('paymentRealizedProfit'),
            totalDeliveryCost: (int) $invoiceItems->sum('deliveryCost'),
        );
    }

    public function toArray(): array
    {
        return [
            'invoices_count' => $this->invoicesCount,
            'total_amount' => $this->totalAmount,
            'total_payments' => $this->totalPayments,
            'total_commissions' => $this->totalCommissions,
            'total_estimated_profit' => $this->totalEstimatedProfit,
            'total_realized_profit' => $this->totalRealizedProfit,
            'total_delivery_cost' => $this->totalDeliveryCost,
            'net_profit' => $this->netProfit(),
        ];
    }
}
