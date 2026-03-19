<?php

namespace App\Services;

use App\Data\Geographic\GeographicActivityDTO;
use App\Data\Geographic\LigneSectorSummaryDTO;
use App\Data\Geographic\LigneStatsDTO;
use App\Data\Geographic\SectorStatsDTO;
use App\Data\Geographic\TopCustomerDTO;
use App\Data\Geographic\ZoneStatsDTO;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds geographic activity reports broken down by Zone → Ligne.
 *
 * Uses exactly three database queries regardless of the number of zones or lignes:
 *   1. All lignes with their zone name (always unfiltered — structural data).
 *   2. Customer counts per ligne (always unfiltered — current state of customers).
 *   3. Invoice aggregates per ligne (filtered by period when provided).
 *
 * The three result sets are merged in PHP, which keeps performance O(n) on lignes
 * while eliminating N+1 queries entirely.
 *
 * This service is read-only — it never mutates data.
 */
class GeographicStatsService
{
    /**
     * Build the complete geographic activity report.
     *
     * Pass $startDate/$endDate to restrict financial metrics to a specific period.
     * Customer counts are always current (unfiltered by period).
     *
     * @param  Carbon|null  $startDate  Start of the period (inclusive). Null = all time.
     * @param  Carbon|null  $endDate  End of the period (inclusive). Null = all time.
     * @param  string|null  $periodLabel  Human-readable period description for the UI.
     */
    public function buildGeographicActivity(
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
        ?string $periodLabel = null,
    ): GeographicActivityDTO {
        $allLignes = $this->fetchAllLignesWithZone();
        $customerStatsByLigneId = $this->fetchCustomerCountsByLigne();
        $invoiceStatsByLigneId = $this->fetchInvoiceAggregatesByLigne($startDate, $endDate);

        // ── Build LigneStatsDTO for every ligne and bucket them by zone ────────

        /** @var array<int, array{zone_id: int, zone_name: string, lignes: LigneStatsDTO[]}> */
        $zoneBuckets = [];

        foreach ($allLignes as $ligne) {
            $customerStats = $customerStatsByLigneId->get($ligne->id);
            $invoiceStats = $invoiceStatsByLigneId->get($ligne->id);

            $confirmedCount = (int) ($customerStats?->confirmed_count ?? 0);
            $prospectCount = (int) ($customerStats?->prospect_count ?? 0);
            $totalCustomers = (int) ($customerStats?->total_customers ?? 0);

            $invoicesCount = (int) ($invoiceStats?->invoices_count ?? 0);
            $totalSales = (int) ($invoiceStats?->total_sales ?? 0);
            $totalEstimatedProfit = (int) ($invoiceStats?->total_estimated_profit ?? 0);
            $totalPaymentsCollected = (int) ($invoiceStats?->total_payments_collected ?? 0);
            $totalRealizedProfit = (int) ($invoiceStats?->total_realized_profit ?? 0);
            $totalCommissions = (int) ($invoiceStats?->total_commissions ?? 0);
            $totalDeliveryCost = (int) ($invoiceStats?->total_delivery_cost ?? 0);

            $netProfit = $totalRealizedProfit - $totalCommissions - $totalDeliveryCost;
            $profitabilityRate = $totalSales > 0
                ? round(($netProfit / $totalSales) * 100, 1)
                : 0.0;
            $collectionRate = $totalSales > 0
                ? round(($totalPaymentsCollected / $totalSales) * 100, 1)
                : 0.0;

            $ligneStats = new LigneStatsDTO(
                ligneId: $ligne->id,
                ligneName: $ligne->name,
                zoneId: $ligne->zone_id ?? 0,
                zoneName: $ligne->zone_name ?? 'Sans zone',
                confirmedCustomersCount: $confirmedCount,
                prospectCustomersCount: $prospectCount,
                totalCustomersCount: $totalCustomers,
                invoicesCount: $invoicesCount,
                totalSales: $totalSales,
                totalEstimatedProfit: $totalEstimatedProfit,
                totalRealizedProfit: $totalRealizedProfit,
                totalPaymentsCollected: $totalPaymentsCollected,
                totalCommissions: $totalCommissions,
                totalDeliveryCost: $totalDeliveryCost,
                netProfit: $netProfit,
                profitabilityRate: $profitabilityRate,
                collectionRate: $collectionRate,
                profitabilityGrade: $this->computeProfitabilityGrade($profitabilityRate),
            );

            $zoneKey = $ligne->zone_id ?? 0;
            if (! isset($zoneBuckets[$zoneKey])) {
                $zoneBuckets[$zoneKey] = [
                    'zone_id' => $ligne->zone_id ?? 0,
                    'zone_name' => $ligne->zone_name ?? 'Sans zone',
                    'lignes' => [],
                ];
            }
            $zoneBuckets[$zoneKey]['lignes'][] = $ligneStats;
        }

        // ── Build ZoneStatsDTO for each zone ───────────────────────────────────

        $zoneStatsList = [];

        foreach ($zoneBuckets as $bucket) {
            /** @var LigneStatsDTO[] $ligneStatsList */
            $ligneStatsList = $bucket['lignes'];

            $zoneConfirmed = (int) array_sum(array_map(fn ($l) => $l->confirmedCustomersCount, $ligneStatsList));
            $zoneProspect = (int) array_sum(array_map(fn ($l) => $l->prospectCustomersCount, $ligneStatsList));
            $zoneTotalCustomers = (int) array_sum(array_map(fn ($l) => $l->totalCustomersCount, $ligneStatsList));
            $zoneInvoicesCount = (int) array_sum(array_map(fn ($l) => $l->invoicesCount, $ligneStatsList));
            $zoneTotalSales = (int) array_sum(array_map(fn ($l) => $l->totalSales, $ligneStatsList));
            $zoneTotalEstimatedProfit = (int) array_sum(array_map(fn ($l) => $l->totalEstimatedProfit, $ligneStatsList));
            $zoneTotalRealizedProfit = (int) array_sum(array_map(fn ($l) => $l->totalRealizedProfit, $ligneStatsList));
            $zoneTotalPayments = (int) array_sum(array_map(fn ($l) => $l->totalPaymentsCollected, $ligneStatsList));
            $zoneTotalCommissions = (int) array_sum(array_map(fn ($l) => $l->totalCommissions, $ligneStatsList));
            $zoneTotalDelivery = (int) array_sum(array_map(fn ($l) => $l->totalDeliveryCost, $ligneStatsList));

            $zoneNetProfit = $zoneTotalRealizedProfit - $zoneTotalCommissions - $zoneTotalDelivery;
            $zoneProfitabilityRate = $zoneTotalSales > 0
                ? round(($zoneNetProfit / $zoneTotalSales) * 100, 1)
                : 0.0;
            $zoneCollectionRate = $zoneTotalSales > 0
                ? round(($zoneTotalPayments / $zoneTotalSales) * 100, 1)
                : 0.0;

            $zoneStatsList[] = new ZoneStatsDTO(
                zoneId: $bucket['zone_id'],
                zoneName: $bucket['zone_name'],
                ligneStats: $ligneStatsList,
                confirmedCustomersCount: $zoneConfirmed,
                prospectCustomersCount: $zoneProspect,
                totalCustomersCount: $zoneTotalCustomers,
                invoicesCount: $zoneInvoicesCount,
                totalSales: $zoneTotalSales,
                totalEstimatedProfit: $zoneTotalEstimatedProfit,
                totalRealizedProfit: $zoneTotalRealizedProfit,
                totalPaymentsCollected: $zoneTotalPayments,
                totalCommissions: $zoneTotalCommissions,
                totalDeliveryCost: $zoneTotalDelivery,
                netProfit: $zoneNetProfit,
                profitabilityRate: $zoneProfitabilityRate,
                collectionRate: $zoneCollectionRate,
                profitabilityGrade: $this->computeProfitabilityGrade($zoneProfitabilityRate),
            );
        }

        // ── Grand totals ───────────────────────────────────────────────────────

        $grandConfirmed = (int) array_sum(array_map(fn ($z) => $z->confirmedCustomersCount, $zoneStatsList));
        $grandProspect = (int) array_sum(array_map(fn ($z) => $z->prospectCustomersCount, $zoneStatsList));
        $grandTotalCustomers = (int) array_sum(array_map(fn ($z) => $z->totalCustomersCount, $zoneStatsList));
        $grandInvoicesCount = (int) array_sum(array_map(fn ($z) => $z->invoicesCount, $zoneStatsList));
        $grandTotalSales = (int) array_sum(array_map(fn ($z) => $z->totalSales, $zoneStatsList));
        $grandTotalEstimatedProfit = (int) array_sum(array_map(fn ($z) => $z->totalEstimatedProfit, $zoneStatsList));
        $grandTotalRealizedProfit = (int) array_sum(array_map(fn ($z) => $z->totalRealizedProfit, $zoneStatsList));
        $grandTotalPayments = (int) array_sum(array_map(fn ($z) => $z->totalPaymentsCollected, $zoneStatsList));
        $grandTotalCommissions = (int) array_sum(array_map(fn ($z) => $z->totalCommissions, $zoneStatsList));
        $grandTotalDelivery = (int) array_sum(array_map(fn ($z) => $z->totalDeliveryCost, $zoneStatsList));

        $grandNetProfit = $grandTotalRealizedProfit - $grandTotalCommissions - $grandTotalDelivery;
        $grandProfitabilityRate = $grandTotalSales > 0
            ? round(($grandNetProfit / $grandTotalSales) * 100, 1)
            : 0.0;
        $grandCollectionRate = $grandTotalSales > 0
            ? round(($grandTotalPayments / $grandTotalSales) * 100, 1)
            : 0.0;

        return new GeographicActivityDTO(
            zoneStats: $zoneStatsList,
            periodLabel: $periodLabel,
            confirmedCustomersCount: $grandConfirmed,
            prospectCustomersCount: $grandProspect,
            totalCustomersCount: $grandTotalCustomers,
            invoicesCount: $grandInvoicesCount,
            totalSales: $grandTotalSales,
            totalEstimatedProfit: $grandTotalEstimatedProfit,
            totalRealizedProfit: $grandTotalRealizedProfit,
            totalPaymentsCollected: $grandTotalPayments,
            totalCommissions: $grandTotalCommissions,
            totalDeliveryCost: $grandTotalDelivery,
            netProfit: $grandNetProfit,
            overallProfitabilityRate: $grandProfitabilityRate,
            overallCollectionRate: $grandCollectionRate,
        );
    }

    // =========================================================================
    // Sector drill-down
    // =========================================================================

    /**
     * Build a sector-level breakdown report for a single Ligne.
     *
     * Uses exactly four database queries regardless of the number of sectors:
     *   1. All sectors belonging to the ligne (structural data).
     *   2. Customer counts per sector (always unfiltered — current state).
     *   3. Invoice aggregates per sector (period-filtered when provided).
     *   4. All customer-level invoice aggregates within the ligne (used to build
     *      top-customer lists and derive recurring customer counts per sector).
     *
     * @param  int  $ligneId  The ligne whose sectors to analyse.
     */
    public function buildSectorActivity(
        int $ligneId,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
        ?string $periodLabel = null,
    ): LigneSectorSummaryDTO {
        $ligneInfo = $this->fetchLigneWithZone($ligneId);
        $allSectors = $this->fetchAllSectorsForLigne($ligneId);
        $customerStatsBySectorId = $this->fetchCustomerCountsBySector($ligneId);
        $invoiceStatsBySectorId = $this->fetchInvoiceAggregatesBySector($ligneId, $startDate, $endDate);

        // Customer aggregates (invoice count + revenue) give us both the top-customer
        // list and the recurring-customer count (customers with invoicesCount >= 2).
        $customerAggregates = $this->fetchAllCustomerAggregatesInLigne($ligneId, $startDate, $endDate);
        $customerAggregatesGroupedBySectorId = $customerAggregates->groupBy('sector_id');

        // ── Build SectorStatsDTO for every sector ────────────────────────────

        /** @var SectorStatsDTO[] $sectorStatsList */
        $sectorStatsList = [];

        foreach ($allSectors as $sector) {
            $customerStats = $customerStatsBySectorId->get($sector->id);
            $invoiceStats = $invoiceStatsBySectorId->get($sector->id);

            $confirmedCount = (int) ($customerStats?->confirmed_count ?? 0);
            $prospectCount = (int) ($customerStats?->prospect_count ?? 0);
            $totalCustomers = (int) ($customerStats?->total_customers ?? 0);

            // Derive recurring count from customer aggregates (customers with 2+ invoices).
            $sectorCustomerAggregates = $customerAggregatesGroupedBySectorId->get($sector->id) ?? collect();
            $recurringCustomersCount = $sectorCustomerAggregates
                ->filter(fn ($customer) => $customer->invoices_count >= 2)
                ->count();
            $recurringCustomersRate = $totalCustomers > 0
                ? round(($recurringCustomersCount / $totalCustomers) * 100, 1)
                : 0.0;

            // Top 5 customers ranked by revenue volume (most valuable first).
            $topCustomers = $sectorCustomerAggregates
                ->sortByDesc('total_sales')
                ->take(5)
                ->values()
                ->map(fn ($customer) => new TopCustomerDTO(
                    customerId: (int) $customer->customer_id,
                    customerName: $customer->customer_name,
                    invoicesCount: (int) $customer->invoices_count,
                    totalSales: (int) $customer->total_sales,
                    isRecurring: $customer->invoices_count >= 2,
                ))
                ->all();

            $invoicesCount = (int) ($invoiceStats?->invoices_count ?? 0);
            $totalSales = (int) ($invoiceStats?->total_sales ?? 0);
            $totalEstimatedProfit = (int) ($invoiceStats?->total_estimated_profit ?? 0);
            $totalPaymentsCollected = (int) ($invoiceStats?->total_payments_collected ?? 0);
            $totalRealizedProfit = (int) ($invoiceStats?->total_realized_profit ?? 0);
            $totalCommissions = (int) ($invoiceStats?->total_commissions ?? 0);
            $totalDeliveryCost = (int) ($invoiceStats?->total_delivery_cost ?? 0);

            $netProfit = $totalRealizedProfit - $totalCommissions - $totalDeliveryCost;
            $profitabilityRate = $totalSales > 0
                ? round(($netProfit / $totalSales) * 100, 1)
                : 0.0;
            $collectionRate = $totalSales > 0
                ? round(($totalPaymentsCollected / $totalSales) * 100, 1)
                : 0.0;

            $sectorStatsList[] = new SectorStatsDTO(
                sectorId: $sector->id,
                sectorName: $sector->name,
                ligneId: $ligneInfo->id,
                ligneName: $ligneInfo->name,
                zoneId: $ligneInfo->zone_id ?? 0,
                zoneName: $ligneInfo->zone_name ?? 'Sans zone',
                confirmedCustomersCount: $confirmedCount,
                prospectCustomersCount: $prospectCount,
                totalCustomersCount: $totalCustomers,
                recurringCustomersCount: $recurringCustomersCount,
                recurringCustomersRate: $recurringCustomersRate,
                topCustomers: $topCustomers,
                invoicesCount: $invoicesCount,
                totalSales: $totalSales,
                totalEstimatedProfit: $totalEstimatedProfit,
                totalRealizedProfit: $totalRealizedProfit,
                totalPaymentsCollected: $totalPaymentsCollected,
                totalCommissions: $totalCommissions,
                totalDeliveryCost: $totalDeliveryCost,
                netProfit: $netProfit,
                profitabilityRate: $profitabilityRate,
                collectionRate: $collectionRate,
                profitabilityGrade: $this->computeProfitabilityGrade($profitabilityRate),
            );
        }

        // ── Totals across all sectors ────────────────────────────────────────

        $totalConfirmed = (int) array_sum(array_map(fn ($s) => $s->confirmedCustomersCount, $sectorStatsList));
        $totalProspect = (int) array_sum(array_map(fn ($s) => $s->prospectCustomersCount, $sectorStatsList));
        $totalCustomersAll = (int) array_sum(array_map(fn ($s) => $s->totalCustomersCount, $sectorStatsList));
        $totalRecurring = (int) array_sum(array_map(fn ($s) => $s->recurringCustomersCount, $sectorStatsList));
        $totalInvoices = (int) array_sum(array_map(fn ($s) => $s->invoicesCount, $sectorStatsList));
        $totalSalesAll = (int) array_sum(array_map(fn ($s) => $s->totalSales, $sectorStatsList));
        $totalEstimatedProfitAll = (int) array_sum(array_map(fn ($s) => $s->totalEstimatedProfit, $sectorStatsList));
        $totalRealizedProfitAll = (int) array_sum(array_map(fn ($s) => $s->totalRealizedProfit, $sectorStatsList));
        $totalPaymentsAll = (int) array_sum(array_map(fn ($s) => $s->totalPaymentsCollected, $sectorStatsList));
        $totalCommissionsAll = (int) array_sum(array_map(fn ($s) => $s->totalCommissions, $sectorStatsList));
        $totalDeliveryCostAll = (int) array_sum(array_map(fn ($s) => $s->totalDeliveryCost, $sectorStatsList));
        $totalNetProfit = $totalRealizedProfitAll - $totalCommissionsAll - $totalDeliveryCostAll;

        $overallProfitabilityRate = $totalSalesAll > 0
            ? round(($totalNetProfit / $totalSalesAll) * 100, 1)
            : 0.0;
        $overallCollectionRate = $totalSalesAll > 0
            ? round(($totalPaymentsAll / $totalSalesAll) * 100, 1)
            : 0.0;

        return new LigneSectorSummaryDTO(
            ligneId: $ligneInfo->id,
            ligneName: $ligneInfo->name,
            zoneId: $ligneInfo->zone_id ?? 0,
            zoneName: $ligneInfo->zone_name ?? 'Sans zone',
            sectorStats: $sectorStatsList,
            periodLabel: $periodLabel,
            confirmedCustomersCount: $totalConfirmed,
            prospectCustomersCount: $totalProspect,
            totalCustomersCount: $totalCustomersAll,
            recurringCustomersCount: $totalRecurring,
            invoicesCount: $totalInvoices,
            totalSales: $totalSalesAll,
            totalEstimatedProfit: $totalEstimatedProfitAll,
            totalRealizedProfit: $totalRealizedProfitAll,
            totalPaymentsCollected: $totalPaymentsAll,
            totalCommissions: $totalCommissionsAll,
            totalDeliveryCost: $totalDeliveryCostAll,
            netProfit: $totalNetProfit,
            overallProfitabilityRate: $overallProfitabilityRate,
            overallCollectionRate: $overallCollectionRate,
        );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Fetch a single ligne with its zone name.
     *
     * @throws \Illuminate\Database\RecordsNotFoundException When the ligne does not exist.
     */
    private function fetchLigneWithZone(int $ligneId): object
    {
        return DB::table('lignes')
            ->select([
                'lignes.id',
                'lignes.name',
                'lignes.zone_id',
                DB::raw('zones.name as zone_name'),
            ])
            ->leftJoin('zones', 'zones.id', '=', 'lignes.zone_id')
            ->where('lignes.id', $ligneId)
            ->firstOrFail();
    }

    /**
     * Fetch all sectors belonging to a given ligne, ordered alphabetically.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function fetchAllSectorsForLigne(int $ligneId): \Illuminate\Support\Collection
    {
        return DB::table('sectors')
            ->select(['id', 'name', 'ligne_id'])
            ->where('ligne_id', $ligneId)
            ->orderBy('name')
            ->get();
    }

    /**
     * Count confirmed and prospect customers per sector for the given ligne.
     *
     * Returns a Collection keyed by sector_id. Only customers with a sector
     * assignment are counted.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function fetchCustomerCountsBySector(int $ligneId): \Illuminate\Support\Collection
    {
        // Join through sectors so that customers with sector_id set but ligne_id NULL
        // are still counted — sector membership is the authoritative link, not customers.ligne_id.
        return DB::table('customers as c')
            ->select([
                'c.sector_id',
                DB::raw('COUNT(*) as total_customers'),
                DB::raw('SUM(CASE WHEN c.is_prospect = 0 THEN 1 ELSE 0 END) as confirmed_count'),
                DB::raw('SUM(CASE WHEN c.is_prospect = 1 THEN 1 ELSE 0 END) as prospect_count'),
            ])
            ->join('sectors as sec', 'sec.id', '=', 'c.sector_id')
            ->where('sec.ligne_id', $ligneId)
            ->groupBy('c.sector_id')
            ->get()
            ->keyBy('sector_id');
    }

    /**
     * Aggregate invoice financial metrics per sector for the given ligne.
     *
     * Returns a Collection keyed by sector_id. Period filter is applied when
     * both $startDate and $endDate are provided.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function fetchInvoiceAggregatesBySector(
        int $ligneId,
        ?Carbon $startDate,
        ?Carbon $endDate,
    ): \Illuminate\Support\Collection {
        $query = DB::table('sales_invoices as si')
            ->select([
                'c.sector_id',
                DB::raw('COUNT(si.id) as invoices_count'),
                DB::raw('COALESCE(SUM(si.total_amount), 0) as total_sales'),
                DB::raw('COALESCE(SUM(si.total_estimated_profit), 0) as total_estimated_profit'),
                DB::raw('COALESCE(SUM(si.total_payments), 0) as total_payments_collected'),
                DB::raw('COALESCE(SUM(si.total_realized_profit), 0) as total_realized_profit'),
                DB::raw('COALESCE(SUM(si.estimated_commercial_commission), 0) as total_commissions'),
                DB::raw('COALESCE(SUM(COALESCE(si.delivery_cost, 0)), 0) as total_delivery_cost'),
            ])
            ->join('customers as c', 'c.id', '=', 'si.customer_id')
            ->join('sectors as sec', 'sec.id', '=', 'c.sector_id')
            ->where('sec.ligne_id', $ligneId);

        if ($startDate !== null && $endDate !== null) {
            $query->whereBetween('si.created_at', [$startDate, $endDate]);
        }

        return $query
            ->groupBy('c.sector_id')
            ->get()
            ->keyBy('sector_id');
    }

    /**
     * Fetch per-customer invoice aggregates for all customers in the given ligne.
     *
     * Each row contains: customer_id, customer_name, sector_id, invoices_count,
     * total_sales. Customers with zero invoices in the period are excluded.
     *
     * The result is ordered by total_sales DESC so that grouping and slicing
     * in PHP naturally produces the highest-volume customers first.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function fetchAllCustomerAggregatesInLigne(
        int $ligneId,
        ?Carbon $startDate,
        ?Carbon $endDate,
    ): \Illuminate\Support\Collection {
        $query = DB::table('sales_invoices as si')
            ->select([
                'c.id as customer_id',
                'c.name as customer_name',
                'c.sector_id',
                DB::raw('COUNT(si.id) as invoices_count'),
                DB::raw('COALESCE(SUM(si.total_amount), 0) as total_sales'),
            ])
            ->join('customers as c', 'c.id', '=', 'si.customer_id')
            ->join('sectors as sec', 'sec.id', '=', 'c.sector_id')
            ->where('sec.ligne_id', $ligneId);

        if ($startDate !== null && $endDate !== null) {
            $query->whereBetween('si.created_at', [$startDate, $endDate]);
        }

        return $query
            ->groupBy('c.id', 'c.name', 'c.sector_id')
            ->orderByDesc('total_sales')
            ->get();
    }

    /**
     * Fetch all lignes with their zone name, ordered zone → ligne alphabetically.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function fetchAllLignesWithZone(): \Illuminate\Support\Collection
    {
        return DB::table('lignes')
            ->select([
                'lignes.id',
                'lignes.name',
                'lignes.zone_id',
                DB::raw('zones.name as zone_name'),
            ])
            ->leftJoin('zones', 'zones.id', '=', 'lignes.zone_id')
            ->orderBy('zones.name')
            ->orderBy('lignes.name')
            ->get();
    }

    /**
     * Count confirmed and prospect customers per ligne (current state, no period filter).
     *
     * Returns a Collection keyed by ligne_id.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function fetchCustomerCountsByLigne(): \Illuminate\Support\Collection
    {
        return DB::table('customers')
            ->select([
                'ligne_id',
                DB::raw('COUNT(*) as total_customers'),
                DB::raw('SUM(CASE WHEN is_prospect = 0 THEN 1 ELSE 0 END) as confirmed_count'),
                DB::raw('SUM(CASE WHEN is_prospect = 1 THEN 1 ELSE 0 END) as prospect_count'),
            ])
            ->whereNotNull('ligne_id')
            ->groupBy('ligne_id')
            ->get()
            ->keyBy('ligne_id');
    }

    /**
     * Aggregate invoice financial metrics per ligne, optionally filtered by period.
     *
     * Uses denormalized stored-total columns on sales_invoices (total_amount,
     * total_payments, total_estimated_profit, total_realized_profit,
     * estimated_commercial_commission, delivery_cost) — no joins to ventes or payments.
     *
     * Returns a Collection keyed by ligne_id.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function fetchInvoiceAggregatesByLigne(
        ?Carbon $startDate,
        ?Carbon $endDate,
    ): \Illuminate\Support\Collection {
        $query = DB::table('sales_invoices as si')
            ->select([
                'c.ligne_id',
                DB::raw('COUNT(si.id) as invoices_count'),
                DB::raw('COALESCE(SUM(si.total_amount), 0) as total_sales'),
                DB::raw('COALESCE(SUM(si.total_estimated_profit), 0) as total_estimated_profit'),
                DB::raw('COALESCE(SUM(si.total_payments), 0) as total_payments_collected'),
                DB::raw('COALESCE(SUM(si.total_realized_profit), 0) as total_realized_profit'),
                DB::raw('COALESCE(SUM(si.estimated_commercial_commission), 0) as total_commissions'),
                DB::raw('COALESCE(SUM(COALESCE(si.delivery_cost, 0)), 0) as total_delivery_cost'),
            ])
            ->join('customers as c', 'c.id', '=', 'si.customer_id')
            ->whereNotNull('c.ligne_id');

        if ($startDate !== null && $endDate !== null) {
            $query->whereBetween('si.created_at', [$startDate, $endDate]);
        }

        return $query
            ->groupBy('c.ligne_id')
            ->get()
            ->keyBy('ligne_id');
    }

    /**
     * Derive a letter grade from a net margin rate percentage.
     *
     *  A  — rate ≥ 15%   (excellent)
     *  B  — rate ≥  8%   (good)
     *  C  — rate ≥  3%   (mediocre)
     *  D  — rate ≥  0%   (barely profitable)
     *  F  — rate  < 0%   (deficit)
     */
    private function computeProfitabilityGrade(float $profitabilityRate): string
    {
        return match (true) {
            $profitabilityRate >= 15.0 => 'A',
            $profitabilityRate >= 8.0 => 'B',
            $profitabilityRate >= 3.0 => 'C',
            $profitabilityRate >= 0.0 => 'D',
            default => 'F',
        };
    }
}
