<?php

namespace App\Data\Statistics;

/**
 * Represents the aggregated financial activity for a single calendar day.
 *
 * Invoice-based metrics (sales, estimated profit, commissions, delivery) are keyed
 * to the day the invoice was created. Realized profit is keyed to the day the payment
 * was received — matching the behaviour of SalesInvoiceStatsService::buildStatsForPeriod().
 *
 * All monetary values are integers (XOF, no sub-units).
 */
readonly class DailyActivityDTO
{
    public function __construct(
        /** Calendar date in Y-m-d format (e.g. "2026-03-15"). */
        public string $date,

        /** Number of sales invoices created on this day. */
        public int $invoicesCount,

        /** Gross revenue: SUM(total_amount) for invoices created on this day. */
        public int $totalSales,

        /**
         * Estimated profit: SUM(total_estimated_profit) for invoices created on this day.
         * Represents full potential profit assuming all invoices are eventually fully paid.
         */
        public int $totalEstimatedProfit,

        /**
         * Realized profit: SUM(payments.profit) for payments received on this day.
         * Profit actually earned from money collected today.
         */
        public int $totalRealizedProfit,

        /** Total delivery costs: SUM(delivery_cost) for invoices created on this day. */
        public int $totalDeliveryCost,

        /** Total estimated commissions: SUM(estimated_commercial_commission) for invoices created on this day. */
        public int $totalCommissions,

        /**
         * Net profit for the day.
         * Formula: totalRealizedProfit − totalCommissions − totalDeliveryCost.
         * Negative when the day ran at a loss.
         */
        public int $netProfit,

        /**
         * Average invoice amount for the day.
         * Formula: totalSales / invoicesCount — zero when invoicesCount is 0.
         */
        public int $invoiceAverageTotal,

        /** True when netProfit < 0 (the day ended with a financial deficit). */
        public bool $isDeficit,
    ) {}

    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'invoices_count' => $this->invoicesCount,
            'total_sales' => $this->totalSales,
            'total_estimated_profit' => $this->totalEstimatedProfit,
            'total_realized_profit' => $this->totalRealizedProfit,
            'total_delivery_cost' => $this->totalDeliveryCost,
            'total_commissions' => $this->totalCommissions,
            'net_profit' => $this->netProfit,
            'invoice_average_total' => $this->invoiceAverageTotal,
            'is_deficit' => $this->isDeficit,
        ];
    }
}
