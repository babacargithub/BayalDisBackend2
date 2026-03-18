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
     * Build the totals DTO from a collection of individual summary DTOs.
     *
     * @param  Collection<int, SalesInvoiceDailySummaryDTO>  $summaries
     */
    public static function fromSummaries(Collection $summaries): self
    {
        return new self(
            invoicesCount: $summaries->count(),
            totalAmount: $summaries->sum('totalAmount'),
            totalPayments: $summaries->sum('totalPayments'),
            totalCommissions: $summaries->sum('estimatedCommercialCommission'),
            totalEstimatedProfit: $summaries->sum('totalEstimatedProfit'),
            totalRealizedProfit: $summaries->sum('totalRealizedProfit'),
            totalDeliveryCost: $summaries->sum('deliveryCost'),
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
