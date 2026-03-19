<?php

namespace App\Data\Geographic;

/**
 * Aggregated financial and customer statistics for a single Ligne (delivery route).
 *
 * Customer counts reflect the current state of the database (not period-filtered).
 * All financial metrics are filtered by the requested period when one is provided.
 *
 * All monetary values are integers (XOF, no sub-units).
 * profitabilityRate and collectionRate are floats representing percentages (e.g. 14.5 = 14.5%).
 */
readonly class LigneStatsDTO
{
    public function __construct(
        public int $ligneId,
        public string $ligneName,
        public int $zoneId,
        public string $zoneName,

        // ── Customers (current state, not period-filtered) ────────────────────

        /** Customers with is_prospect = false (have made at least one purchase). */
        public int $confirmedCustomersCount,

        /** Customers with is_prospect = true (no confirmed purchase yet). */
        public int $prospectCustomersCount,

        /** Total customers assigned to this ligne (confirmed + prospects). */
        public int $totalCustomersCount,

        // ── Sales (period-filtered when a period is provided) ─────────────────

        /** Number of sales invoices created for customers on this ligne. */
        public int $invoicesCount,

        /** Gross revenue: SUM(total_amount) across all invoices on this ligne. */
        public int $totalSales,

        /** Estimated profit: SUM(total_estimated_profit) — full potential profit. */
        public int $totalEstimatedProfit,

        /**
         * Realized profit: SUM(total_realized_profit) from invoices on this ligne.
         * This is the profit derived from payments already collected.
         */
        public int $totalRealizedProfit,

        /** Total cash collected: SUM(total_payments) across all invoices on this ligne. */
        public int $totalPaymentsCollected,

        /** Total estimated commissions: SUM(estimated_commercial_commission). */
        public int $totalCommissions,

        /** Total delivery costs: SUM(delivery_cost). */
        public int $totalDeliveryCost,

        // ── Computed metrics ──────────────────────────────────────────────────

        /**
         * Net profit for this ligne.
         * Formula: totalRealizedProfit − totalCommissions − totalDeliveryCost.
         * Negative when the ligne runs at a loss.
         */
        public int $netProfit,

        /**
         * Profitability rate: net margin as a percentage.
         * Formula: (netProfit / totalSales) × 100 — zero when totalSales is 0.
         * Rounded to one decimal place.
         */
        public float $profitabilityRate,

        /**
         * Collection rate: percentage of billed revenue actually collected.
         * Formula: (totalPaymentsCollected / totalSales) × 100 — zero when totalSales is 0.
         * Rounded to one decimal place.
         */
        public float $collectionRate,

        /**
         * Profitability grade derived from profitabilityRate:
         * A (≥ 15%), B (≥ 8%), C (≥ 3%), D (≥ 0%), F (< 0%).
         */
        public string $profitabilityGrade,
    ) {}

    public function toArray(): array
    {
        return [
            'ligne_id' => $this->ligneId,
            'ligne_name' => $this->ligneName,
            'zone_id' => $this->zoneId,
            'zone_name' => $this->zoneName,
            'confirmed_customers_count' => $this->confirmedCustomersCount,
            'prospect_customers_count' => $this->prospectCustomersCount,
            'total_customers_count' => $this->totalCustomersCount,
            'invoices_count' => $this->invoicesCount,
            'total_sales' => $this->totalSales,
            'total_estimated_profit' => $this->totalEstimatedProfit,
            'total_realized_profit' => $this->totalRealizedProfit,
            'total_payments_collected' => $this->totalPaymentsCollected,
            'total_commissions' => $this->totalCommissions,
            'total_delivery_cost' => $this->totalDeliveryCost,
            'net_profit' => $this->netProfit,
            'profitability_rate' => $this->profitabilityRate,
            'collection_rate' => $this->collectionRate,
            'profitability_grade' => $this->profitabilityGrade,
        ];
    }
}
