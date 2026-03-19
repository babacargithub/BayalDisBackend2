<?php

namespace App\Data\Geographic;

/**
 * Aggregated financial and customer statistics for a Zone, containing all of its Lignes.
 *
 * All monetary values are integers (XOF, no sub-units).
 */
readonly class ZoneStatsDTO
{
    /**
     * @param  LigneStatsDTO[]  $ligneStats  All lignes belonging to this zone, sorted by name.
     */
    public function __construct(
        public int $zoneId,
        public string $zoneName,
        public array $ligneStats,

        // ── Aggregated from all lignes ────────────────────────────────────────

        public int $confirmedCustomersCount,
        public int $prospectCustomersCount,
        public int $totalCustomersCount,
        public int $invoicesCount,
        public int $totalSales,
        public int $totalEstimatedProfit,
        public int $totalRealizedProfit,
        public int $totalPaymentsCollected,
        public int $totalCommissions,
        public int $totalDeliveryCost,
        public int $netProfit,
        public float $profitabilityRate,
        public float $collectionRate,
        public string $profitabilityGrade,
    ) {}

    public function toArray(): array
    {
        return [
            'zone_id' => $this->zoneId,
            'zone_name' => $this->zoneName,
            'ligne_stats' => array_map(
                fn (LigneStatsDTO $ligneDto) => $ligneDto->toArray(),
                $this->ligneStats
            ),
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
