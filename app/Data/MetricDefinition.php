<?php

namespace App\Data;

/**
 * Represents the definition of a single KPI metric property.
 *
 * Returned by DTO metadata methods to give API consumers enough context
 * to display a metric's meaning without hard-coding labels client-side.
 */
readonly class MetricDefinition
{
    public function __construct(
        /** Snake_case property name as it appears in the API response (e.g. "average_days_to_payment"). */
        public string $property,

        /** Short French display label shown as the metric title in the UI. */
        public string $label,

        /** Plain-French explanation of what the metric measures and how to interpret it. */
        public string $description,
    ) {}

    public function toArray(): array
    {
        return [
            'property' => $this->property,
            'label' => $this->label,
            'description' => $this->description,
        ];
    }
}
