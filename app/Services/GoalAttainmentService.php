<?php

namespace App\Services;

use App\Data\Goal\GoalAttainmentDTO;
use App\Data\Vente\VenteStatsFilter;
use App\Enums\GoalAssigneeType;
use App\Enums\GoalMetric;
use App\Models\Goal;
use App\Models\Team;
use Illuminate\Support\Collection;

/**
 * Computes goal attainment by pairing each Goal with its live actual metric value.
 *
 * Responsibilities:
 *  1. Build a VenteStatsFilter correctly scoped to the goal's assignee and period.
 *  2. Delegate metric resolution to GoalMetricResolverFactory.
 *  3. Compute the attainment rate (direction-aware: lower-is-better metrics inverted).
 *  4. Return a GoalAttainmentDTO — never mutate the Goal.
 */
readonly class GoalAttainmentService
{
    public function __construct(
        private GoalMetricResolverFactory $goalMetricResolverFactory,
    ) {}

    public function computeAttainment(Goal $goal): GoalAttainmentDTO
    {
        $filter = $this->buildFilterFromGoal($goal);
        $actualValue = $this->goalMetricResolverFactory->resolveActualValue($goal->metric, $filter);
        $targetValue = $goal->target_value;
        $attainmentRate = $this->computeAttainmentRate($goal->metric, $actualValue, $targetValue);

        return new GoalAttainmentDTO(
            goal: $goal,
            targetValue: $targetValue,
            actualValue: $actualValue,
            attainmentRate: $attainmentRate,
            achieved: $attainmentRate >= 100.0,
        );
    }

    /**
     * Compute attainment for a collection of goals — useful for rendering a dashboard
     * showing all goals for a given assignee in one shot.
     *
     * @param  Collection<int, Goal>  $goals
     * @return Collection<int, GoalAttainmentDTO>
     */
    public function computeAttainmentForMultipleGoals(Collection $goals): Collection
    {
        return $goals->map(fn (Goal $goal) => $this->computeAttainment($goal));
    }

    // =========================================================================
    // Private — filter construction
    // =========================================================================

    /**
     * Build a VenteStatsFilter scoped to the goal's assignee and period boundaries.
     *
     * Commercial goals → filter by commercial_id.
     * Team goals       → filter by team_id (fetches Team model for type safety).
     * Company goals    → no entity scope, all data in the period.
     */
    private function buildFilterFromGoal(Goal $goal): VenteStatsFilter
    {
        $filter = VenteStatsFilter::regardlessOfPaymentStatus()
            ->inDateInterval(
                $goal->period_start->copy()->startOfDay(),
                $goal->period_end->copy()->endOfDay(),
            );

        return match ($goal->assignee_type) {
            GoalAssigneeType::Commercial => $filter->thatAreMadeByCommercial($goal->assignee_id),
            GoalAssigneeType::Team => $filter->thatAreForTeam(Team::findOrFail($goal->assignee_id)),
            GoalAssigneeType::Company => $filter,
        };
    }

    // =========================================================================
    // Private — attainment rate computation
    // =========================================================================

    /**
     * Compute the attainment rate as a percentage, direction-aware.
     *
     * Higher-is-better: rate = actual / target × 100
     * Lower-is-better:  rate = target / actual × 100
     *   (e.g. churn target 5%, actual 3% → rate 166.7% → achieved)
     *   (e.g. churn target 5%, actual 8% → rate 62.5%  → not achieved)
     *
     * Edge cases:
     *   - targetValue ≤ 0 → return 0.0 (goal not valid for measurement).
     *   - lower-is-better + actualValue ≤ 0 → return 100.0 (perfect achievement).
     */
    private function computeAttainmentRate(GoalMetric $metric, float $actualValue, float $targetValue): float
    {
        if ($targetValue <= 0) {
            return 0.0;
        }

        if ($metric->lowerIsBetter()) {
            return $actualValue <= 0
                ? 100.0
                : round($targetValue / $actualValue * 100, 1);
        }

        return round($actualValue / $targetValue * 100, 1);
    }
}
