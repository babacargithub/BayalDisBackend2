<?php

namespace App\Services;

use App\Data\KPI\CustomerCoverageMetricsDTO;
use App\Data\KPI\KPIReport;
use App\Data\KPI\ProductMixMetricsDTO;
use App\Data\KPI\SalesPerformanceMetricsDTO;
use App\Data\Vente\VenteStatsFilter;
use App\Enums\ProspectionStatus;
use App\Models\BeatStop;
use App\Models\Customer;
use App\Models\CustomerProspectionEvent;
use App\Models\SalesInvoice;
use App\Models\Vente;
use App\Services\Concerns\AppliesInvoiceScopeFromFilter;
use App\Services\Concerns\BuildsMetricDistributions;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Computes the full KPI report for any scope expressible via VenteStatsFilter.
 *
 * Each metric domain is exposed as its own method so callers can fetch only what
 * they need (e.g. just sales metrics for a dashboard card). The buildReport() method
 * composes all domains into a single KPIReport.
 *
 * Scope-neutral: the caller constructs a VenteStatsFilter targeting the desired scope
 * (a commercial, a beat, a team, or the whole business). This service has no opinion
 * on who the result belongs to.
 *
 * This service is read-only — it never mutates data.
 */
readonly class KPIReportService
{
    use AppliesInvoiceScopeFromFilter;
    use BuildsMetricDistributions;

    public function __construct(
        private AccountReceivablesMetricsService $accountReceivablesMetricsService,
    ) {}

    // =========================================================================
    // Public — per-domain metric builders
    // =========================================================================

    public function buildSalesPerformanceMetrics(VenteStatsFilter $filter): SalesPerformanceMetricsDTO
    {
        $totalRevenue = (int) $this->buildPeriodScopedQuery($filter)->sum('total_amount');
        $totalInvoicesCount = $this->buildPeriodScopedQuery($filter)->count();
        $uniqueCustomersServedCount = $this->buildPeriodScopedQuery($filter)->distinct()->count('customer_id');

        return new SalesPerformanceMetricsDTO(
            totalRevenue: $totalRevenue,
            totalInvoicesCount: $totalInvoicesCount,
            uniqueCustomersServedCount: $uniqueCustomersServedCount,
            averageBasketSize: $totalInvoicesCount > 0
                ? (int) round($totalRevenue / $totalInvoicesCount)
                : 0,
            averageRevenuePerCustomer: $uniqueCustomersServedCount > 0
                ? (int) round($totalRevenue / $uniqueCustomersServedCount)
                : 0,
        );
    }

    public function buildCustomerCoverageMetrics(VenteStatsFilter $filter): CustomerCoverageMetricsDTO
    {
        // Customers who purchased in the period — the primary activity signal.
        $activeCustomerIds = $this->buildPeriodScopedQuery($filter)
            ->distinct()
            ->pluck('customer_id');

        // Customers visited during prospection in the period (no purchase yet).
        $prospectionVisitCustomerIds = $this->buildScopedProspectionEventQuery($filter)
            ->distinct()
            ->pluck('customer_id');

        // Visited = purchased + visited-but-not-bought (union, deduplicated).
        $visitedCustomerIds = $activeCustomerIds->merge($prospectionVisitCustomerIds)->unique();
        $visitedCustomersCount = $visitedCustomerIds->count();
        $activeCustomersCount = $activeCustomerIds->count();

        // New customers created in the period.
        $newCustomersQuery = $this->buildPeriodScopedCustomerQuery($filter);
        $newConfirmedCustomersCount = (clone $newCustomersQuery)->where('is_prospect', false)->count();
        $newProspectCustomersCount = (clone $newCustomersQuery)->where('is_prospect', true)->count();

        // Prospects formally converted (ProspectionStatus::Acquired event) in the period.
        $prospectsConvertedToConfirmedCount = $this->buildScopedProspectionEventQuery($filter)
            ->where('status', ProspectionStatus::Acquired)
            ->count();

        // Retention and churn — requires a date range for the previous comparable period.
        [$returningCustomersCount, $customerRetentionRate, $churningCustomersCount, $churningRate] =
            $this->computeRetentionAndChurnMetrics($filter, $activeCustomerIds);

        $visitStrikeRate = $visitedCustomersCount > 0
            ? round($activeCustomersCount / $visitedCustomersCount * 100, 1)
            : 0.0;

        $totalNewCustomers = $newConfirmedCustomersCount + $newProspectCustomersCount;
        $acquisitionStrikeRate = $totalNewCustomers > 0
            ? round($newConfirmedCustomersCount / $totalNewCustomers * 100, 1)
            : 0.0;

        return new CustomerCoverageMetricsDTO(
            visitedCustomersCount: $visitedCustomersCount,
            activeCustomersCount: $activeCustomersCount,
            visitStrikeRate: $visitStrikeRate,
            acquisitionStrikeRate: $acquisitionStrikeRate,
            returningCustomersCount: $returningCustomersCount,
            customerRetentionRate: $customerRetentionRate,
            newConfirmedCustomersCount: $newConfirmedCustomersCount,
            newProspectCustomersCount: $newProspectCustomersCount,
            prospectsConvertedToConfirmedCount: $prospectsConvertedToConfirmedCount,
            churningCustomersCount: $churningCustomersCount,
            churningRate: $churningRate,
        );
    }

    public function buildProductMixMetrics(VenteStatsFilter $filter): ProductMixMetricsDTO
    {
        $invoiceSubquery = $this->buildPeriodScopedQuery($filter)->select('id');

        // Per-invoice product and category counts via a single grouped query.
        $perInvoiceStats = Vente::query()
            ->whereIn('sales_invoice_id', $invoiceSubquery)
            ->where('type', Vente::TYPE_INVOICE)
            ->join('products', 'products.id', '=', 'ventes.product_id')
            ->selectRaw('sales_invoice_id, COUNT(DISTINCT ventes.product_id) as distinct_products, COUNT(DISTINCT products.product_category_id) as distinct_categories')
            ->groupBy('sales_invoice_id')
            ->get();

        $totalDistinctProductsSoldCount = Vente::query()
            ->whereIn('sales_invoice_id', $invoiceSubquery)
            ->where('type', Vente::TYPE_INVOICE)
            ->distinct()
            ->count('product_id');

        $totalDistinctCategoriesSoldCount = Vente::query()
            ->whereIn('sales_invoice_id', $invoiceSubquery)
            ->where('type', Vente::TYPE_INVOICE)
            ->join('products', 'products.id', '=', 'ventes.product_id')
            ->distinct()
            ->count('products.product_category_id');

        $pushScoreValues = $perInvoiceStats->pluck('distinct_products')->map(fn ($v) => (int) $v)->toArray();

        return new ProductMixMetricsDTO(
            pushScore: round((float) ($perInvoiceStats->avg('distinct_products') ?? 0), 1),
            averageProductCategoriesPerInvoice: round((float) ($perInvoiceStats->avg('distinct_categories') ?? 0), 1),
            totalDistinctProductsSoldCount: $totalDistinctProductsSoldCount,
            totalDistinctCategoriesSoldCount: $totalDistinctCategoriesSoldCount,
            pushScoreDistribution: $this->buildDistribution(
                metricProperty: 'push_score',
                values: $pushScoreValues,
                labelFormatter: static fn (int $value): string => match ($value) {
                    1 => '1 produit',
                    default => "{$value} produits",
                },
            ),
        );
    }

    /**
     * Compose all metric domains into a single KPIReport.
     *
     * Pass $includeProductMix = true to include the heavier product mix section.
     * It is excluded by default because it requires joining across ventes and products.
     */
    public function buildReport(VenteStatsFilter $filter, bool $includeProductMix = false): KPIReport
    {
        return new KPIReport(
            salesPerformance: $this->buildSalesPerformanceMetrics($filter),
            invoiceCollection: $this->accountReceivablesMetricsService->buildMetrics($filter)->metrics,
            customerCoverage: $this->buildCustomerCoverageMetrics($filter),
            productMix: $includeProductMix ? $this->buildProductMixMetrics($filter) : null,
        );
    }

    // =========================================================================
    // Private — retention and churn computation
    // =========================================================================

    /**
     * Compare the current period's active customer set against the previous comparable
     * period to derive retention and churn figures.
     *
     * Returns [returningCount, retentionRate, churningCount, churningRate].
     * All values are 0 / 0.0 when no date range is set on the filter — without a defined
     * period there is no "previous period" to compare against.
     *
     * @return array{0: int, 1: float, 2: int, 3: float}
     */
    private function computeRetentionAndChurnMetrics(
        VenteStatsFilter $filter,
        Collection $currentPeriodCustomerIds,
    ): array {
        if ($filter->startDate === null) {
            return [0, 0.0, 0, 0.0];
        }

        $periodLengthInDays = (int) Carbon::parse($filter->startDate)->diffInDays(
            $filter->endDate ?? now(),
        );

        $previousPeriodEnd = Carbon::parse($filter->startDate)->subDay()->endOfDay();
        $previousPeriodStart = $previousPeriodEnd->copy()->subDays($periodLengthInDays)->startOfDay();

        $previousPeriodFilter = clone $filter;
        $previousPeriodFilter->startDate = $previousPeriodStart;
        $previousPeriodFilter->endDate = $previousPeriodEnd;

        $previousPeriodCustomerIds = $this->buildPeriodScopedQuery($previousPeriodFilter)
            ->distinct()
            ->pluck('customer_id');

        $previousPeriodCount = $previousPeriodCustomerIds->count();

        if ($previousPeriodCount === 0) {
            return [0, 0.0, 0, 0.0];
        }

        $returningCustomersCount = $currentPeriodCustomerIds->intersect($previousPeriodCustomerIds)->count();
        $churningCustomersCount = $previousPeriodCustomerIds->diff($currentPeriodCustomerIds)->count();

        return [
            $returningCustomersCount,
            round($returningCustomersCount / $previousPeriodCount * 100, 1),
            $churningCustomersCount,
            round($churningCustomersCount / $previousPeriodCount * 100, 1),
        ];
    }

    // =========================================================================
    // Private — query builders
    // =========================================================================

    private function buildPeriodScopedQuery(VenteStatsFilter $filter): Builder
    {
        return $this->applyFullScopeToInvoiceQuery(SalesInvoice::query(), $filter);
    }

    /**
     * Scope a Customer query to the entity scope of the filter (commercial / beat / team).
     * Date range from the filter is applied as a constraint on customers.created_at.
     */
    private function buildPeriodScopedCustomerQuery(VenteStatsFilter $filter): Builder
    {
        $query = Customer::query();

        if ($filter->commercialId !== null) {
            $query->where('commercial_id', $filter->commercialId);
        }

        if ($filter->teamId !== null) {
            $query->whereHas(
                'commercial',
                fn (Builder $q) => $q->where('team_id', $filter->teamId),
            );
        }

        if ($filter->beatId !== null) {
            $query->whereIn(
                'id',
                BeatStop::where('beat_id', $filter->beatId)->select('customer_id'),
            );
        }

        if ($filter->startDate !== null) {
            $query->where('created_at', '>=', $filter->startDate);
        }

        if ($filter->endDate !== null) {
            $query->where('created_at', '<=', $filter->endDate);
        }

        return $query;
    }

    /**
     * Scope a CustomerProspectionEvent query to the entity scope and date range of the filter.
     * Beat scope is applied via the customer's beat membership, not directly on the event.
     */
    private function buildScopedProspectionEventQuery(VenteStatsFilter $filter): Builder
    {
        $query = CustomerProspectionEvent::query();

        if ($filter->commercialId !== null) {
            $query->where('commercial_id', $filter->commercialId);
        }

        if ($filter->teamId !== null) {
            $query->whereHas(
                'commercial',
                fn (Builder $q) => $q->where('team_id', $filter->teamId),
            );
        }

        if ($filter->beatId !== null) {
            $query->whereIn(
                'customer_id',
                BeatStop::where('beat_id', $filter->beatId)->select('customer_id'),
            );
        }

        if ($filter->startDate !== null) {
            $query->where('created_at', '>=', $filter->startDate);
        }

        if ($filter->endDate !== null) {
            $query->where('created_at', '<=', $filter->endDate);
        }

        return $query;
    }
}
