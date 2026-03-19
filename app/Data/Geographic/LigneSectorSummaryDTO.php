<?php

namespace App\Data\Geographic;

/**
 * Sector-level drill-down report for a single Ligne.
 *
 * Contains one SectorStatsDTO per sector belonging to the ligne, plus
 * aggregate totals summed across all sectors — mirroring the structure of
 * GeographicActivityDTO so the frontend KPI layer can be reused unchanged.
 *
 * All monetary values are integers (XOF, no sub-units).
 */
readonly class LigneSectorSummaryDTO
{
    /**
     * @param  SectorStatsDTO[]  $sectorStats  All sectors in this ligne, sorted by name.
     */
    public function __construct(
        public int $ligneId,
        public string $ligneName,
        public int $zoneId,
        public string $zoneName,

        public array $sectorStats,

        /** Human-readable period label, or null when the report covers all time. */
        public ?string $periodLabel,

        // ── Totals aggregated across all sectors in this ligne ────────────────

        public int $confirmedCustomersCount,
        public int $prospectCustomersCount,
        public int $totalCustomersCount,

        /** Customers with 2+ invoices in the period, summed across sectors. */
        public int $recurringCustomersCount,

        public int $invoicesCount,
        public int $totalSales,
        public int $totalEstimatedProfit,
        public int $totalRealizedProfit,
        public int $totalPaymentsCollected,
        public int $totalCommissions,
        public int $totalDeliveryCost,
        public int $netProfit,
        public float $overallProfitabilityRate,
        public float $overallCollectionRate,
    ) {}

    public function toArray(): array
    {
        return [
            'ligne_id' => $this->ligneId,
            'ligne_name' => $this->ligneName,
            'zone_id' => $this->zoneId,
            'zone_name' => $this->zoneName,
            'sector_stats' => array_map(
                fn (SectorStatsDTO $sector) => $sector->toArray(),
                $this->sectorStats
            ),
            'period_label' => $this->periodLabel,
            'confirmed_customers_count' => $this->confirmedCustomersCount,
            'prospect_customers_count' => $this->prospectCustomersCount,
            'total_customers_count' => $this->totalCustomersCount,
            'recurring_customers_count' => $this->recurringCustomersCount,
            'invoices_count' => $this->invoicesCount,
            'total_sales' => $this->totalSales,
            'total_estimated_profit' => $this->totalEstimatedProfit,
            'total_realized_profit' => $this->totalRealizedProfit,
            'total_payments_collected' => $this->totalPaymentsCollected,
            'total_commissions' => $this->totalCommissions,
            'total_delivery_cost' => $this->totalDeliveryCost,
            'net_profit' => $this->netProfit,
            'overall_profitability_rate' => $this->overallProfitabilityRate,
            'overall_collection_rate' => $this->overallCollectionRate,
        ];
    }
}
