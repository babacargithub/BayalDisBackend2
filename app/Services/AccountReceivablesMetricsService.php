<?php

namespace App\Services;

use App\Data\InvoiceCollection\InvoiceCollectionMetricsDTO;
use App\Data\InvoiceCollection\InvoiceCollectionMetricsResult;
use App\Data\Vente\VenteStatsFilter;
use App\Enums\SalesInvoiceStatus;
use App\Models\SalesInvoice;
use App\Services\Concerns\AppliesInvoiceScopeFromFilter;
use App\Services\Concerns\BuildsMetricDistributions;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Computes accounts-receivable collection metrics for any scope expressible via VenteStatsFilter.
 *
 * Scope-neutral: the caller constructs a VenteStatsFilter that targets the desired scope
 * (a commercial, a car load, a team, a customer, a beat, or the whole business) and passes
 * it in. The service itself has no opinion on who the result belongs to.
 *
 * This service is read-only — it never mutates data.
 *
 * DRY note: SalesInvoice query scoping is centralised in the AppliesInvoiceScopeFromFilter
 * trait, which is the single source of truth for VenteStatsFilter → SalesInvoice query
 * translation. SalesInvoiceStatsService and PaymentService can be progressively refactored
 * to use the same trait.
 */
readonly class AccountReceivablesMetricsService
{
    use AppliesInvoiceScopeFromFilter;
    use BuildsMetricDistributions;

    /**
     * Compute the full set of AR collection metrics for the given scope and period.
     *
     * Period-based metrics (DSO, ADD, on-time rate, CEI) use invoices created within
     * filter->startDate / filter->endDate. When no date range is set, all invoices in scope
     * are used.
     *
     * The outstanding amount is always a real-time snapshot — it ignores the date range and
     * reflects all currently unpaid invoices in the entity scope.
     * Pass $includeDistributions = true to also receive per-metric frequency distributions
     * (histogram data) in the result. The distributions are computed from the same dataset
     * as the summary metrics, so no extra DB queries are made.
     */
    public function buildMetrics(
        VenteStatsFilter $filter,
        bool $includeDistributions = false,
    ): InvoiceCollectionMetricsResult {
        $fullyPaidInvoices = $this->fetchFullyPaidInvoicesWithLastPaymentDate($filter);

        [$daysToPaymentValues, $daysDelinquentValues, $onTimeInvoicesCount] =
            $this->computePerInvoiceMetricValues($fullyPaidInvoices);

        $fullyPaidInvoicesCount = count($fullyPaidInvoices);

        $metrics = new InvoiceCollectionMetricsDTO(
            averageDaysToPayment: $this->computeMean($daysToPaymentValues),
            medianDaysToPayment: $this->computeMedian($daysToPaymentValues),
            averageDaysDelinquent: $this->computeMean($daysDelinquentValues),
            medianDaysDelinquent: $this->computeMedian($daysDelinquentValues),
            onTimePaymentRate: $fullyPaidInvoicesCount > 0
                ? round($onTimeInvoicesCount / $fullyPaidInvoicesCount * 100, 1)
                : 0.0,
            collectionEffectivenessIndex: $this->computeCEI($filter),
            totalInvoicesCount: $this->buildPeriodScopedQuery($filter)->count(),
            fullyPaidInvoicesCount: $fullyPaidInvoicesCount,
            onTimeInvoicesCount: $onTimeInvoicesCount,
            totalOutstandingAmount: $this->computeOutstandingAmount($filter),
        );

        $distributions = $includeDistributions ? [
            'average_days_to_payment' => $this->buildDistribution('average_days_to_payment', $daysToPaymentValues),
            'average_days_delinquent' => $this->buildDistribution('average_days_delinquent', $daysDelinquentValues),
        ] : null;

        return new InvoiceCollectionMetricsResult(
            metrics: $metrics,
            distributions: $distributions,
        );
    }

    // =========================================================================
    // Private — data fetching
    // =========================================================================

    /**
     * Fetch all FULLY_PAID invoices in scope, augmented with the date of their last
     * non-cancelled payment via a correlated subquery.
     *
     * Only columns needed for metric computation are selected to keep memory usage low.
     */
    private function fetchFullyPaidInvoicesWithLastPaymentDate(VenteStatsFilter $filter): Collection
    {
        return $this->buildPeriodScopedQuery($filter)
            ->where('status', SalesInvoiceStatus::FullyPaid)
            ->select(['id', 'created_at', 'should_be_paid_at'])
            ->selectRaw(
                '(SELECT MAX(p.created_at) FROM payments p'
                .' WHERE p.sales_invoice_id = sales_invoices.id'
                .' AND p.cancelled_at IS NULL) as last_payment_at',
            )
            ->get();
    }

    // =========================================================================
    // Private — per-invoice metric computation
    // =========================================================================

    /**
     * Walk the fully-paid invoice collection and derive the three raw metric arrays.
     *
     * Returns a tuple: [daysToPaymentValues, daysDelinquentValues, onTimeInvoicesCount].
     *
     * An invoice is skipped when last_payment_at is null (edge case: no non-cancelled
     * payment recorded despite FULLY_PAID status — should not happen in practice).
     *
     * An invoice contributes to daysDelinquentValues and the on-time count only when
     * should_be_paid_at is set; without a due date the delinquency signal is undefined.
     *
     * @return array{0: int[], 1: int[], 2: int}
     */
    private function computePerInvoiceMetricValues(Collection $fullyPaidInvoices): array
    {
        $daysToPaymentValues = [];
        $daysDelinquentValues = [];
        $onTimeInvoicesCount = 0;

        foreach ($fullyPaidInvoices as $invoice) {
            if ($invoice->last_payment_at === null) {
                continue;
            }

            $paymentDate = Carbon::parse($invoice->last_payment_at)->startOfDay();
            $invoiceCreatedAt = Carbon::parse($invoice->created_at)->startOfDay();

            $daysToPaymentValues[] = (int) $invoiceCreatedAt->diffInDays($paymentDate);

            if ($invoice->should_be_paid_at !== null) {
                $dueDate = Carbon::parse($invoice->should_be_paid_at)->startOfDay();

                $daysDelinquentValues[] = max(0, (int) $dueDate->diffInDays($paymentDate, false));

                if ($paymentDate->lte($dueDate)) {
                    $onTimeInvoicesCount++;
                }
            }
        }

        return [$daysToPaymentValues, $daysDelinquentValues, $onTimeInvoicesCount];
    }

    // =========================================================================
    // Private — aggregate queries
    // =========================================================================

    /**
     * Compute the current outstanding balance (total_amount − total_payments) across all
     * non-FULLY_PAID invoices in the entity scope. Date range is intentionally excluded
     * because outstanding is a real-time snapshot, not a period metric.
     */
    private function computeOutstandingAmount(VenteStatsFilter $filter): int
    {
        return (int) $this->buildEntityScopedQuery($filter)
            ->whereNotIn('status', [SalesInvoiceStatus::FullyPaid])
            ->selectRaw('COALESCE(SUM(total_amount - total_payments), 0) as outstanding')
            ->value('outstanding');
    }

    /**
     * Compute the Collection Effectiveness Index (CEI) for the given scope and period.
     *
     * Formula:
     *   Collectible = Beginning AR + Period Sales − Current Not-Yet-Due AR
     *   Collected   = Beginning AR + Period Sales − Ending AR
     *   CEI         = Collected / Collectible × 100
     *
     * When no date range is set (all-time), Beginning AR = 0 and the formula simplifies to:
     *   CEI = Total Collected / (Total Sales − Not-Yet-Due Outstanding) × 100
     *
     * Returns 0.0 when nothing was collectible in the period.
     */
    private function computeCEI(VenteStatsFilter $filter): float
    {
        $periodSales = (int) $this->buildPeriodScopedQuery($filter)->sum('total_amount');

        $currentNotYetDueAR = (int) $this->buildEntityScopedQuery($filter)
            ->whereNotIn('status', [SalesInvoiceStatus::FullyPaid])
            ->where('should_be_paid_at', '>', now())
            ->selectRaw('COALESCE(SUM(total_amount - total_payments), 0) as ar')
            ->value('ar');

        if ($filter->startDate === null && $filter->endDate === null) {
            $totalCollected = (int) $this->buildEntityScopedQuery($filter)
                ->selectRaw('COALESCE(SUM(total_payments), 0) as collected')
                ->value('collected');

            $collectible = $periodSales - $currentNotYetDueAR;

            return $collectible > 0
                ? round($totalCollected / $collectible * 100, 1)
                : 0.0;
        }

        $beginningAR = $filter->startDate !== null
            ? (int) $this->buildEntityScopedQuery($filter)
                ->whereNotIn('status', [SalesInvoiceStatus::FullyPaid])
                ->where('created_at', '<', $filter->startDate)
                ->selectRaw('COALESCE(SUM(total_amount - total_payments), 0) as ar')
                ->value('ar')
            : 0;

        $endingAR = (int) $this->buildEntityScopedQuery($filter)
            ->whereNotIn('status', [SalesInvoiceStatus::FullyPaid])
            ->when(
                $filter->endDate !== null,
                fn (Builder $q) => $q->where('created_at', '<=', $filter->endDate),
            )
            ->selectRaw('COALESCE(SUM(total_amount - total_payments), 0) as ar')
            ->value('ar');

        $collectible = $beginningAR + $periodSales - $currentNotYetDueAR;
        $collected = $beginningAR + $periodSales - $endingAR;

        return $collectible > 0
            ? round($collected / $collectible * 100, 1)
            : 0.0;
    }

    // =========================================================================
    // Private — query builders (delegate to trait)
    // =========================================================================

    /** Scope to invoices in the entity scope + date range from the filter. */
    private function buildPeriodScopedQuery(VenteStatsFilter $filter): Builder
    {
        return $this->applyFullScopeToInvoiceQuery(SalesInvoice::query(), $filter);
    }

    /** Scope to invoices in the entity scope only, no date range. */
    private function buildEntityScopedQuery(VenteStatsFilter $filter): Builder
    {
        return $this->applyEntityScopeToInvoiceQuery(SalesInvoice::query(), $filter);
    }
}
