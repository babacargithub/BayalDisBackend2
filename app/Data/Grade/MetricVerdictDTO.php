<?php

namespace App\Data\Grade;

use App\Enums\GoalMetric;
use App\Enums\VerdictStatus;

/**
 * A "Verdict de Terrain" — a metric value expressed as a plain French sentence
 * anchored to a concrete target, designed to be read by a field salesperson.
 *
 * Example:
 *   "Tes clients te paient en moyenne en 6.9 jours.
 *    L'objectif est 7 jours maximum. ✅ Objectif atteint."
 *
 * Never instantiate directly — use MetricTranslatorService::translateToVerdict().
 */
readonly class MetricVerdictDTO
{
    public function __construct(
        public GoalMetric $metric,
        public float $actualValue,
        public float $targetValue,

        /** The full human sentence including the status signal at the end. */
        public string $sentence,

        public VerdictStatus $status,
    ) {}

    public function toArray(): array
    {
        return [
            'metric' => $this->metric->value,
            'metric_label' => $this->metric->label(),
            'actual_value' => $this->actualValue,
            'target_value' => $this->targetValue,
            'sentence' => $this->sentence,
            'status' => $this->status->name,
            'status_label' => $this->status->label(),
            'emoji' => $this->status->emoji(),
        ];
    }
}
