<?php

namespace App\Data\Geographic;

/**
 * Aggregated financial and customer statistics for a single Sector.
 *
 * Sectors sit one level below Lignes in the geographic hierarchy:
 * Zone → Ligne → Sector → Customer.
 *
 * Customer counts reflect the current database state (not period-filtered).
 * Financial metrics are filtered by the requested period when one is provided.
 *
 * All monetary values are integers (XOF, no sub-units).
 * Rates are floats representing percentages (e.g. 14.5 = 14.5 %).
 */
readonly class SectorStatsDTO
{
    /**
     * @param  TopCustomerDTO[]  $topCustomers  Up to 5 customers ranked by revenue volume.
     *                                          Also used to render the frequency ranking in
     *                                          the UI (re-sorted client-side by invoicesCount).
     */
    public function __construct(
        public int $sectorId,
        public string $sectorName,
        public int $ligneId,
        public string $ligneName,
        public int $zoneId,
        public string $zoneName,

        // ── Customers (current state, not period-filtered) ────────────────────

        public int $confirmedCustomersCount,
        public int $prospectCustomersCount,
        public int $totalCustomersCount,

        /**
         * Customers who placed 2 or more orders in the period.
         * Key churn/retention indicator — zero means no repeat buyers.
         */
        public int $recurringCustomersCount,

        /**
         * Recurring customers as a share of total customers.
         * Formula: (recurringCustomersCount / totalCustomersCount) × 100.
         * Zero when there are no customers.
         */
        public float $recurringCustomersRate,

        /** Top customers ranked by revenue (topCustomers[0] = highest spender). */
        public array $topCustomers,

        // ── Sales (period-filtered when a period is provided) ─────────────────

        public int $invoicesCount,
        public int $totalSales,
        public int $totalEstimatedProfit,
        public int $totalRealizedProfit,
        public int $totalPaymentsCollected,
        public int $totalCommissions,
        public int $totalDeliveryCost,

        // ── Computed metrics ──────────────────────────────────────────────────

        /** Net profit = totalRealizedProfit − totalCommissions − totalDeliveryCost. */
        public int $netProfit,

        /** (netProfit / totalSales) × 100 — zero when totalSales is 0. */
        public float $profitabilityRate,

        /** (totalPaymentsCollected / totalSales) × 100 — zero when totalSales is 0. */
        public float $collectionRate,

        /** A (≥ 15 %), B (≥ 8 %), C (≥ 3 %), D (≥ 0 %), F (< 0 %). */
        public string $profitabilityGrade,
    ) {}

    public function toArray(): array
    {
        return [
            'sector_id' => $this->sectorId,
            'sector_name' => $this->sectorName,
            'ligne_id' => $this->ligneId,
            'ligne_name' => $this->ligneName,
            'zone_id' => $this->zoneId,
            'zone_name' => $this->zoneName,
            'confirmed_customers_count' => $this->confirmedCustomersCount,
            'prospect_customers_count' => $this->prospectCustomersCount,
            'total_customers_count' => $this->totalCustomersCount,
            'recurring_customers_count' => $this->recurringCustomersCount,
            'recurring_customers_rate' => $this->recurringCustomersRate,
            'top_customers' => array_map(
                fn (TopCustomerDTO $customer) => $customer->toArray(),
                $this->topCustomers
            ),
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
