<?php

namespace App\Data\Geographic;

/**
 * Full geographic activity report covering all zones and their lignes.
 *
 * Includes grand totals aggregated across all zones and an optional period label
 * describing the filter applied (e.g. "Mars 2026", "2026", or null for all time).
 *
 * All monetary values are integers (XOF, no sub-units).
 */
readonly class GeographicActivityDTO
{
    /**
     * @param  ZoneStatsDTO[]  $zoneStats  All zones, sorted by name. May include a
     *                                     synthetic "Sans zone" entry for lignes not
     *                                     linked to any zone.
     */
    public function __construct(
        public array $zoneStats,

        /** Human-readable description of the active period filter, or null for all time. */
        public ?string $periodLabel,

        // ── Grand totals across all zones ─────────────────────────────────────

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
        public float $overallProfitabilityRate,
        public float $overallCollectionRate,
    ) {}

    public function toArray(): array
    {
        return [
            'zone_stats' => array_map(
                fn (ZoneStatsDTO $zoneDto) => $zoneDto->toArray(),
                $this->zoneStats
            ),
            'period_label' => $this->periodLabel,
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
            'overall_profitability_rate' => $this->overallProfitabilityRate,
            'overall_collection_rate' => $this->overallCollectionRate,
        ];
    }
}
