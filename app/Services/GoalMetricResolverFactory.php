<?php

namespace App\Services;

use App\Data\Vente\VenteStatsFilter;
use App\Enums\GoalMetric;

/**
 * Resolves the live actual value for any GoalMetric given a pre-built VenteStatsFilter.
 *
 * The factory delegates to the correct KPI service depending on the metric domain:
 *   - Sales performance metrics  → KPIReportService::buildSalesPerformanceMetrics()
 *   - Customer coverage metrics  → KPIReportService::buildCustomerCoverageMetrics()
 *   - Product mix metrics        → KPIReportService::buildProductMixMetrics()
 *   - AR / collection metrics    → AccountReceivablesMetricsService::buildMetrics()
 *
 * The caller is responsible for constructing a filter that is already scoped to the
 * goal's assignee (commercial / team / company) and period. This class has no knowledge
 * of Goal assignee logic.
 */
readonly class GoalMetricResolverFactory
{
    public function __construct(
        private KPIReportService $kpiReportService,
        private AccountReceivablesMetricsService $accountReceivablesMetricsService,
    ) {}

    public function resolveActualValue(GoalMetric $metric, VenteStatsFilter $filter): float
    {
        return match ($metric) {
            GoalMetric::TotalRevenue => (float) $this->kpiReportService
                ->buildSalesPerformanceMetrics($filter)->totalRevenue,

            GoalMetric::AverageBasketSize => (float) $this->kpiReportService
                ->buildSalesPerformanceMetrics($filter)->averageBasketSize,

            GoalMetric::TotalOutstandingAmount => (float) $this->accountReceivablesMetricsService
                ->buildMetrics($filter)->metrics->totalOutstandingAmount,

            GoalMetric::PushScore => $this->kpiReportService
                ->buildProductMixMetrics($filter)->pushScore,

            GoalMetric::VisitStrikeRate => $this->kpiReportService
                ->buildCustomerCoverageMetrics($filter)->visitStrikeRate,

            GoalMetric::AcquisitionStrikeRate => $this->kpiReportService
                ->buildCustomerCoverageMetrics($filter)->acquisitionStrikeRate,

            GoalMetric::NewConfirmedCustomers => (float) $this->kpiReportService
                ->buildCustomerCoverageMetrics($filter)->newConfirmedCustomersCount,

            GoalMetric::CustomerRetentionRate => $this->kpiReportService
                ->buildCustomerCoverageMetrics($filter)->customerRetentionRate,

            GoalMetric::ChurningRate => $this->kpiReportService
                ->buildCustomerCoverageMetrics($filter)->churningRate,

            GoalMetric::OnTimePaymentRate => $this->accountReceivablesMetricsService
                ->buildMetrics($filter)->metrics->onTimePaymentRate,

            GoalMetric::CollectionEffectivenessIndex => $this->accountReceivablesMetricsService
                ->buildMetrics($filter)->metrics->collectionEffectivenessIndex,

            GoalMetric::AverageDaysToPayment => $this->accountReceivablesMetricsService
                ->buildMetrics($filter)->metrics->averageDaysToPayment,

            GoalMetric::MedianDaysToPayment => $this->accountReceivablesMetricsService
                ->buildMetrics($filter)->metrics->medianDaysToPayment,

            GoalMetric::AverageDaysDelinquent => $this->accountReceivablesMetricsService
                ->buildMetrics($filter)->metrics->averageDaysDelinquent,

            GoalMetric::MedianDaysDelinquent => $this->accountReceivablesMetricsService
                ->buildMetrics($filter)->metrics->medianDaysDelinquent,
        };
    }
}
