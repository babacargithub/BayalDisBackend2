<?php

namespace App\Data\Dashboard;

use Illuminate\Support\Str;

/**
 * Holds the computed financial and operational statistics for a given time period.
 * Used by DashboardController to ensure consistent shape across daily, weekly,
 * monthly, and all-time stat blocks.
 *
 * All money values are integers (XOF).
 * All counts are integers.
 */
readonly class DashboardStats
{
    public function __construct(
        public int $totalCustomers,
        public int $totalProspects,
        public int $totalConfirmedCustomers,

        /** Total number of sales invoices created in the period. */
        public int $salesInvoicesCount,

        /** Invoices where paid = true (fully settled). */
        public int $fullyPaidSalesInvoicesCount,

        /** Invoices where paid = false but at least one payment exists (partially settled). */
        public int $partiallyPaidSalesInvoicesCount,

        /** Invoices where paid = false and no payment has been recorded yet. */
        public int $unpaidSalesInvoicesCount,

        /** Gross revenue = SUM(price × quantity) across all invoice line items. */
        public int $totalSales,

        /**
         * Estimated profit = SUM(vente.profit).
         * The full potential profit if every invoice were paid in full.
         */
        public int $totalEstimatedProfit,

        /**
         * Realized profit = SUM(payment.profit).
         * Profit actually earned from money received, proportional to partial payments.
         */
        public int $totalRealizedProfit,

        /** Total cash received = SUM(payment.amount). */
        public int $totalPaymentsReceived,

        /** Total expenses = SUM(depense.amount) in the period. */
        public int $totalExpenses,

        /** Total commercial commissions = SUM(sales_invoices.estimated_commercial_commission). */
        public int $totalCommissions,

        /** Total delivery cost = SUM(sales_invoices.delivery_cost). */
        public int $totalDeliveryCost,

        /**
         * Net profit after commissions and delivery costs.
         * Formula: totalRealizedProfit - totalCommissions - totalDeliveryCost.
         */
        public int $netProfit,
    ) {}

    /**
     * Serialise to an array with snake_case keys (legacy Vue Dashboard component shape).
     */
    public function toArray(): array
    {
        return [
            'total_customers' => $this->totalCustomers,
            'total_prospects' => $this->totalProspects,
            'total_confirmed_customers' => $this->totalConfirmedCustomers,
            'sales_invoices_count' => $this->salesInvoicesCount,
            'fully_paid_sales_invoices_count' => $this->fullyPaidSalesInvoicesCount,
            'partially_paid_sales_invoices_count' => $this->partiallyPaidSalesInvoicesCount,
            'unpaid_sales_invoices_count' => $this->unpaidSalesInvoicesCount,
            'total_sales' => $this->totalSales,
            'total_estimated_profit' => $this->totalEstimatedProfit,
            'total_realized_profit' => $this->totalRealizedProfit,
            'total_payments_received' => $this->totalPaymentsReceived,
            'total_expenses' => $this->totalExpenses,
            'total_commissions' => $this->totalCommissions,
            'total_delivery_cost' => $this->totalDeliveryCost,
            'net_profit' => $this->netProfit,
        ];
    }

    /**
     * Serialise to an array with snake_case keys (for API responses and Vue components).
     * Automatically reflects all public properties — no manual update needed when adding new ones.
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
