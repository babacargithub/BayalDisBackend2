<?php

namespace App\Data\Statistics;

/**
 * Aggregated financial totals for a single calendar month, used in the yearly breakdown view.
 *
 * Mirrors the shape of DailyActivityDTO so both the monthly-view table (days)
 * and the yearly-view table (months) share the same column layout in the frontend.
 *
 * All monetary values are integers (XOF, no sub-units).
 */
readonly class MonthlyTotalsDTO
{
    public function __construct(
        /** Month number 1–12. */
        public int $monthNumber,

        /** Number of sales invoices created during this month. */
        public int $invoicesCount,

        /** Gross revenue: SUM(total_amount) for invoices created this month. */
        public int $totalSales,

        /** Estimated profit: SUM(total_estimated_profit) for invoices created this month. */
        public int $totalEstimatedProfit,

        /**
         * Realized profit: SUM(payments.profit) for payments received this month.
         * Keyed to payment date, not invoice date.
         */
        public int $totalRealizedProfit,

        /** Total delivery costs: SUM(delivery_cost) for invoices created this month. */
        public int $totalDeliveryCost,

        /** Total estimated commissions: SUM(estimated_commercial_commission) for invoices created this month. */
        public int $totalCommissions,

        /**
         * Net profit for the month.
         * Formula: totalRealizedProfit − totalCommissions − totalDeliveryCost.
         */
        public int $netProfit,

        /**
         * Average invoice total for the month.
         * Formula: totalSales / invoicesCount — zero when invoicesCount is 0.
         */
        public int $invoiceAverageTotal,

        /** True when netProfit < 0. */
        public bool $isDeficit,

        /** Number of calendar days within this month that had at least one invoice. */
        public int $activeDaysCount,
    ) {}

    public function toArray(): array
    {
        return [
            'month_number' => $this->monthNumber,
            'invoices_count' => $this->invoicesCount,
            'total_sales' => $this->totalSales,
            'total_estimated_profit' => $this->totalEstimatedProfit,
            'total_realized_profit' => $this->totalRealizedProfit,
            'total_delivery_cost' => $this->totalDeliveryCost,
            'total_commissions' => $this->totalCommissions,
            'net_profit' => $this->netProfit,
            'invoice_average_total' => $this->invoiceAverageTotal,
            'is_deficit' => $this->isDeficit,
            'active_days_count' => $this->activeDaysCount,
        ];
    }
}
