<?php

namespace App\Data\KPI;

use App\Data\InvoiceCollection\InvoiceCollectionMetricsDTO;

/**
 * Top-level KPI report aggregating all metric domains for a given scope and period.
 *
 * Scope-neutral: the caller constructs a SalesFilterBuilder that targets the desired
 * scope (a commercial, a beat, a team, or the whole business) and passes it to
 * KPIReportService::buildReport(). The report itself has no opinion on who it belongs to.
 *
 * productMix is optional because computing it requires joining to ventes/order_items
 * and may be skipped when only summary metrics are needed (e.g. dashboard cards).
 */
readonly class KPIReport
{
    public function __construct(
        public SalesPerformanceMetricsDTO $salesPerformance,
        public InvoiceCollectionMetricsDTO $invoiceCollection,
        public CustomerCoverageMetricsDTO $customerCoverage,
        public ?ProductMixMetricsDTO $productMix = null,
    ) {}

    /**
     * Serialise all metric domains to a single snake_case array for JSON API responses.
     *
     * Each domain is nested under its own key so the mobile consumer can selectively
     * read only the section it needs without parsing the full payload.
     */
    public function toArray(): array
    {
        return [
            'sales_performance' => $this->salesPerformance->toSnakeCaseArray(),
            'invoice_collection' => $this->invoiceCollection->toSnakeCaseArray(),
            'customer_coverage' => $this->customerCoverage->toSnakeCaseArray(),
            'product_mix' => $this->productMix?->toSnakeCaseArray(),
        ];
    }
}
