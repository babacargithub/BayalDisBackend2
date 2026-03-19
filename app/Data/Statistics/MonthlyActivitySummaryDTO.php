<?php

namespace App\Data\Statistics;

/**
 * Aggregated daily financial activity for a full calendar month.
 *
 * Contains one DailyActivityDTO for every calendar day of the month (including
 * zero-activity days such as weekends or holidays), plus pre-computed period totals
 * and averages.
 *
 * All monetary values are integers (XOF, no sub-units).
 */
readonly class MonthlyActivitySummaryDTO
{
    /**
     * @param  DailyActivityDTO[]  $dailyActivity  One entry per calendar day of the month
     *                                             (index 0 = day 1, index N−1 = last day).
     *                                             Zero-activity days are included with all metrics set to 0.
     */
    public function __construct(
        public int $year,
        public int $month,

        /** Total number of calendar days in the month (28, 29, 30, or 31). */
        public int $daysInMonth,

        /** Number of days in the month that had at least one invoice created. */
        public int $activeDaysCount,

        /** Per-day breakdown — always daysInMonth entries. */
        public array $dailyActivity,

        /** Total invoices created across the month. */
        public int $totalInvoicesCount,

        /** Total gross revenue across the month. */
        public int $totalSales,

        /** Total estimated profit across the month. */
        public int $totalEstimatedProfit,

        /** Total realized profit from payments received this month. */
        public int $totalRealizedProfit,

        /** Total delivery costs across the month. */
        public int $totalDeliveryCost,

        /** Total estimated commissions across the month. */
        public int $totalCommissions,

        /**
         * Net profit for the month.
         * Formula: totalRealizedProfit − totalCommissions − totalDeliveryCost.
         */
        public int $netProfit,

        /**
         * Average daily gross revenue across active days.
         * Formula: totalSales / activeDaysCount — zero when no active days.
         */
        public int $averageDailySales,

        /**
         * Average invoice total for the month.
         * Formula: totalSales / totalInvoicesCount — zero when no invoices.
         */
        public int $averageInvoiceTotal,
    ) {}

    public function toArray(): array
    {
        return [
            'year' => $this->year,
            'month' => $this->month,
            'days_in_month' => $this->daysInMonth,
            'active_days_count' => $this->activeDaysCount,
            'daily_activity' => array_map(
                fn (DailyActivityDTO $dayDto) => $dayDto->toArray(),
                $this->dailyActivity
            ),
            'total_invoices_count' => $this->totalInvoicesCount,
            'total_sales' => $this->totalSales,
            'total_estimated_profit' => $this->totalEstimatedProfit,
            'total_realized_profit' => $this->totalRealizedProfit,
            'total_delivery_cost' => $this->totalDeliveryCost,
            'total_commissions' => $this->totalCommissions,
            'net_profit' => $this->netProfit,
            'average_daily_sales' => $this->averageDailySales,
            'average_invoice_total' => $this->averageInvoiceTotal,
        ];
    }
}
