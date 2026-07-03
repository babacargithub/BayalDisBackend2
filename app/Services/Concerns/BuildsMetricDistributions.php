<?php

namespace App\Services\Concerns;

use App\Data\MetricDistribution;
use App\Data\MetricDistributionBucket;
use Closure;

/**
 * Shared distribution builder logic for metric services.
 *
 * Builds MetricDistribution histograms from flat arrays of integer values.
 * Exact individual buckets are used for values 0–10. Values beyond 10 are grouped
 * into ranges (11–20, 21–30, 31–60, 60+) to keep the payload compact when outliers exist.
 *
 * The label format is configurable via a Closure so the same infrastructure can serve
 * different metric domains (days, products, units, etc.) without duplication.
 */
trait BuildsMetricDistributions
{
    /**
     * Build a MetricDistribution from a flat array of integer values.
     *
     * @param  Closure(int): string|null  $labelFormatter  Custom label for each bucket value.
     *                                                     Receives the bucket's exact value (for 0–10 buckets)
     *                                                     or the min value (for grouped ranges).
     *                                                     Defaults to French day labels ("1 jour", "3 jours", …).
     */
    private function buildDistribution(
        string $metricProperty,
        array $values,
        ?Closure $labelFormatter = null,
    ): MetricDistribution {
        $total = count($values);

        if ($total === 0) {
            return new MetricDistribution(
                metricProperty: $metricProperty,
                buckets: [],
                total: 0,
                mean: 0.0,
                median: 0.0,
            );
        }

        sort($values);
        $valueCounts = array_count_values($values);

        return new MetricDistribution(
            metricProperty: $metricProperty,
            buckets: $this->buildDistributionBuckets($valueCounts, $total, $labelFormatter),
            total: $total,
            mean: $this->computeMean($values),
            median: $this->computeMedian($values),
        );
    }

    /**
     * @param  array<int, int>  $valueCounts  Map of value → count from array_count_values().
     * @param  Closure(int): string|null  $labelFormatter
     * @return MetricDistributionBucket[]
     */
    private function buildDistributionBuckets(
        array $valueCounts,
        int $total,
        ?Closure $labelFormatter = null,
    ): array {
        $labelFormatter ??= static fn (int $value): string => match ($value) {
            0 => '0 jour',
            1 => '1 jour',
            default => "{$value} jours",
        };

        $buckets = [];

        // Exact buckets for values 0–10. The 0-value bucket is always included.
        for ($value = 0; $value <= 10; $value++) {
            $count = $valueCounts[$value] ?? 0;

            if ($count > 0 || $value === 0) {
                $buckets[] = new MetricDistributionBucket(
                    label: $labelFormatter($value),
                    minValue: $value,
                    maxValue: $value,
                    count: $count,
                    percentage: round($count / $total * 100, 1),
                );
            }
        }

        // Grouped ranges for 11–20, 21–30, 31–60.
        foreach ([[11, 20], [21, 30], [31, 60]] as [$min, $max]) {
            $count = 0;
            for ($value = $min; $value <= $max; $value++) {
                $count += $valueCounts[$value] ?? 0;
            }

            if ($count > 0) {
                $buckets[] = new MetricDistributionBucket(
                    label: $labelFormatter($min).' – '.$labelFormatter($max),
                    minValue: $min,
                    maxValue: $max,
                    count: $count,
                    percentage: round($count / $total * 100, 1),
                );
            }
        }

        // Open-ended bucket for values beyond 60.
        $overflowCount = 0;
        foreach ($valueCounts as $value => $count) {
            if ($value > 60) {
                $overflowCount += $count;
            }
        }

        if ($overflowCount > 0) {
            $buckets[] = new MetricDistributionBucket(
                label: $labelFormatter(61).'+',
                minValue: 61,
                maxValue: null,
                count: $overflowCount,
                percentage: round($overflowCount / $total * 100, 1),
            );
        }

        return $buckets;
    }

    /** Arithmetic mean, rounded to 1 decimal place. Returns 0.0 for empty input. */
    private function computeMean(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }

        return round(array_sum($values) / count($values), 1);
    }

    /**
     * Median (P50), rounded to 1 decimal place. Returns 0.0 for empty input.
     * Immune to outliers — one very large value does not shift this figure.
     */
    private function computeMedian(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }

        sort($values);
        $count = count($values);
        $middle = (int) floor($count / 2);

        return $count % 2 === 0
            ? round(($values[$middle - 1] + $values[$middle]) / 2, 1)
            : round((float) $values[$middle], 1);
    }
}
