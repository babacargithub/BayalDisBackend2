<?php

namespace App\Data\Statistics;

/**
 * Aggregated monthly activity for a full calendar year.
 *
 * Contains one MonthlyTotalsDTO for every month of the year (January–December,
 * including months with zero activity), plus pre-computed year totals and averages.
 *
 * All monetary values are integers (XOF, no sub-units).
 */
readonly class YearlyActivitySummaryDTO
{
    /**
     * @param  MonthlyTotalsDTO[]  $monthlyTotals  Always exactly 12 entries (index 0 = January).
     */
    public function __construct(
        public int $year,

        /** Per-month breakdown — always 12 entries. */
        public array $monthlyTotals,

        /** Total invoices created across the year. */
        public int $totalInvoicesCount,

        /** Total gross revenue across the year. */
        public int $totalSales,

        /** Total estimated profit across the year. */
        public int $totalEstimatedProfit,

        /** Total realized profit from payments received this year. */
        public int $totalRealizedProfit,

        /** Total delivery costs across the year. */
        public int $totalDeliveryCost,

        /** Total estimated commissions across the year. */
        public int $totalCommissions,

        /**
         * Net profit for the year.
         * Formula: totalRealizedProfit − totalCommissions − totalDeliveryCost.
         */
        public int $netProfit,

        /**
         * Average monthly gross revenue across active months.
         * Formula: totalSales / activeMonthsCount — zero when no active months.
         */
        public int $averageMonthlySales,

        /**
         * Average invoice total for the year.
         * Formula: totalSales / totalInvoicesCount — zero when no invoices.
         */
        public int $averageInvoiceTotal,

        /** Number of months in the year that had at least one invoice. */
        public int $activeMonthsCount,
    ) {}

    public function toArray(): array
    {
        return [
            'year' => $this->year,
            'monthly_totals' => array_map(
                fn (MonthlyTotalsDTO $monthDto) => $monthDto->toArray(),
                $this->monthlyTotals
            ),
            'total_invoices_count' => $this->totalInvoicesCount,
            'total_sales' => $this->totalSales,
            'total_estimated_profit' => $this->totalEstimatedProfit,
            'total_realized_profit' => $this->totalRealizedProfit,
            'total_delivery_cost' => $this->totalDeliveryCost,
            'total_commissions' => $this->totalCommissions,
            'net_profit' => $this->netProfit,
            'average_monthly_sales' => $this->averageMonthlySales,
            'average_invoice_total' => $this->averageInvoiceTotal,
            'active_months_count' => $this->activeMonthsCount,
        ];
    }
}
