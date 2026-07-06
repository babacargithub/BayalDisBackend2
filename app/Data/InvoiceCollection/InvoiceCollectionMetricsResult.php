<?php

namespace App\Data\InvoiceCollection;

use App\Data\MetricDistribution;

/**
 * Wraps the result of AccountReceivablesMetricsService::buildMetrics().
 *
 * Always contains the summary metrics. Optionally contains per-metric frequency
 * distributions when the caller requested them (includeDistributions = true).
 */
readonly class InvoiceCollectionMetricsResult
{
    /**
     * @param  array<string, MetricDistribution>|null  $distributions
     *                                                                 Keyed by metric property name (e.g. 'average_days_delinquent').
     *                                                                 Null when distributions were not requested.
     */
    public function __construct(
        public InvoiceCollectionMetricsDTO $metrics,
        public ?array $distributions = null,
    ) {}
}
