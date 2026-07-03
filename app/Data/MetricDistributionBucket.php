<?php

namespace App\Data;

/**
 * Represents one bucket in a metric frequency distribution.
 *
 * For exact values (e.g. "3 jours"), minValue === maxValue.
 * For open-ended ranges (e.g. "60+ jours"), maxValue is null.
 */
readonly class MetricDistributionBucket
{
    public function __construct(
        /** Human-readable label for this bucket (e.g. "3 jours", "11–20 jours", "60+ jours"). */
        public string $label,

        /** Lower bound of the bucket (inclusive). */
        public int $minValue,

        /** Upper bound of the bucket (inclusive). Null for open-ended buckets (e.g. 60+). */
        public ?int $maxValue,

        /** Number of items falling in this bucket. */
        public int $count,

        /** Percentage of total items in this bucket, rounded to 1 decimal place. */
        public float $percentage,
    ) {}

    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'min_value' => $this->minValue,
            'max_value' => $this->maxValue,
            'count' => $this->count,
            'percentage' => $this->percentage,
        ];
    }
}
