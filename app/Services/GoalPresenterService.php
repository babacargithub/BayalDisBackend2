<?php

namespace App\Services;

use App\Data\Goal\GoalAttainmentDTO;
use App\Enums\GoalMetric;
use App\Models\Commercial;
use App\Models\Goal;
use Illuminate\Support\Collection;

/**
 * Transforms GoalAttainmentDTOs into plain arrays ready for Inertia / JSON serialization.
 *
 * Keeps all presentation-layer mapping out of controllers and DTOs.
 * Controllers call compute → serialize as two distinct steps.
 */
readonly class GoalPresenterService
{
    public function __construct(
        private GoalAttainmentService $goalAttainmentService,
    ) {}

    /**
     * Compute attainment for each goal in the collection and serialize all of them.
     *
     * @param  Collection<int, Goal>  $goals
     * @return array<int, array<string, mixed>>
     */
    public function computeAndSerializeGoals(Collection $goals): array
    {
        return $this->goalAttainmentService
            ->computeAttainmentForMultipleGoals($goals)
            ->map(fn (GoalAttainmentDTO $dto) => $this->serializeAttainmentDTO($dto))
            ->values()
            ->toArray();
    }

    /**
     * Compute attainment for a single goal and serialize it.
     *
     * @return array<string, mixed>
     */
    public function computeAndSerializeGoal(Goal $goal): array
    {
        return $this->serializeAttainmentDTO(
            $this->goalAttainmentService->computeAttainment($goal),
        );
    }

    /**
     * Build the list of available metric definitions for the create/edit form.
     *
     * @return array<int, array<string, mixed>>
     */
    public function serializeAvailableMetrics(): array
    {
        return collect(GoalMetric::cases())
            ->map(fn (GoalMetric $metric) => [
                'value' => $metric->value,
                'label' => $metric->label(),
                'description' => $metric->description(),
                'lower_is_better' => $metric->lowerIsBetter(),
            ])
            ->values()
            ->toArray();
    }

    /**
     * Build the list of goals that can be linked as children of $parentGoal.
     *
     * A goal is linkable when:
     *  - it has no parent yet (top-level goal)
     *  - it is not the parent goal itself
     *
     * @return array<int, array<string, mixed>>
     */
    public function serializeLinkableGoals(Goal $parentGoal): array
    {
        return Goal::query()
            ->whereNull('parent_goal_id')
            ->where('id', '!=', $parentGoal->id)
            ->with('commercial')
            ->orderBy('period_end', 'desc')
            ->get()
            ->map(fn (Goal $goal) => [
                'id' => $goal->id,
                'label' => $goal->metric->label(),
                'assignee_name' => $goal->commercial?->name ?? 'Entreprise',
                'period_start' => $goal->period_start->toDateString(),
                'period_end' => $goal->period_end->toDateString(),
                'display_title' => $goal->metric->label().' — '.($goal->commercial?->name ?? 'Entreprise'),
                'display_subtitle' => $goal->period_start->format('d/m/Y').' → '.$goal->period_end->format('d/m/Y'),
            ])
            ->values()
            ->toArray();
    }

    /**
     * Build the commercial list for the assignee selector.
     *
     * @return array<int, array<string, mixed>>
     */
    public function serializeCommerciauxList(): array
    {
        return Commercial::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->toArray();
    }

    // =========================================================================
    // Private
    // =========================================================================

    /**
     * @return array<string, mixed>
     */
    private function serializeAttainmentDTO(GoalAttainmentDTO $dto): array
    {
        return [
            'goal_id' => $dto->goal->id,
            'metric' => $dto->goal->metric->value,
            'metric_label' => $dto->goal->metric->label(),
            'metric_description' => $dto->goal->metric->description(),
            'lower_is_better' => $dto->goal->metric->lowerIsBetter(),
            'assignee_type' => $dto->goal->assignee_type->value,
            'assignee_id' => $dto->goal->assignee_id,
            'assignee_name' => $dto->goal->commercial?->name ?? 'Entreprise',
            'period_start' => $dto->goal->period_start->toDateString(),
            'period_end' => $dto->goal->period_end->toDateString(),
            'target_value' => $dto->targetValue,
            'actual_value' => $dto->actualValue,
            'attainment_rate' => $dto->attainmentRate,
            'achieved' => $dto->achieved,
            'formatted_actual' => $dto->goal->metric->formatValue($dto->actualValue),
            'formatted_target' => $dto->goal->metric->formatValue($dto->targetValue),
            'child_goals_count' => $dto->goal->child_goals_count ?? 0,
            'parent_goal_id' => $dto->goal->parent_goal_id,
        ];
    }
}
