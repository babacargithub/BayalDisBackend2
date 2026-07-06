<?php

namespace App\Data;

/**
 * Holds the full frequency distribution of a single numeric metric.
 *
 * Exact values (0–10) are shown as individual buckets.
 * Values beyond the exact threshold are grouped into ranges (11–20, 21–30, 31–60, 60+)
 * to keep the payload compact when outliers exist.
 *
 * The mean and median are included so the consumer can display the distribution
 * alongside its summary statistics without a second request.
 */
readonly class MetricDistribution
{
    /**
     * @param  MetricDistributionBucket[]  $buckets
     */
    public function __construct(
        /** Snake_case name of the metric this distribution describes (matches InvoiceCollectionMetricsDTO property). */
        public string $metricProperty,

        /** Frequency buckets ordered from lowest to highest value. */
        public array $buckets,

        /** Total number of data points across all buckets (the common denominator). */
        public int $total,

        /** Arithmetic mean of all data points. Sensitive to outliers. */
        public float $mean,

        /** Median (P50) of all data points. Robust to outliers. */
        public float $median,
    ) {}

    public function toArray(): array
    {
        return [
            'metric_property' => $this->metricProperty,
            'buckets' => array_map(fn (MetricDistributionBucket $bucket) => $bucket->toArray(), $this->buckets),
            'total' => $this->total,
            'mean' => $this->mean,
            'median' => $this->median,
        ];
    }
}
