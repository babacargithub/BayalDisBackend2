<?php

namespace App\Data\Goal;

use App\Models\Goal;

/**
 * Pairs a Goal with its live computed actual value and attainment rate.
 *
 * Never persisted — computed on demand by GoalAttainmentService.
 * The mobile app uses this to render progress bars and achieved/not-achieved badges.
 */
readonly class GoalAttainmentDTO
{
    public function __construct(
        public Goal $goal,
        public float $targetValue,
        public float $actualValue,

        /** Percentage of target reached: (actualValue / targetValue) × 100. */
        public float $attainmentRate,

        /** True when attainmentRate >= 100 (or ≤ 100 for lower-is-better metrics like churn). */
        public bool $achieved,
    ) {}

    public function toArray(): array
    {
        return [
            'goal_id' => $this->goal->id,
            'assignee_type' => $this->goal->assignee_type->value,
            'assignee_id' => $this->goal->assignee_id,
            'metric' => $this->goal->metric->value,
            'metric_label' => $this->goal->metric->label(),
            'period_start' => $this->goal->period_start->toDateString(),
            'period_end' => $this->goal->period_end->toDateString(),
            'target_value' => $this->targetValue,
            'actual_value' => $this->actualValue,
            'attainment_rate' => $this->attainmentRate,
            'achieved' => $this->achieved,
        ];
    }
}
