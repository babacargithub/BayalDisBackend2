<?php

namespace App\Services;

use App\Data\Goal\GoalAttainmentDTO;
use App\Data\Grade\GradeDTO;
use App\Data\Grade\MetricVerdictDTO;
use App\Enums\GoalMetric;
use App\Enums\GradeTranslationStrategy;
use App\Enums\VerdictStatus;

/**
 * Unified metric translation service — converts a raw KPI value into either a
 * 0–20 school grade or a plain French "Verdict de Terrain" sentence.
 *
 * TWO TRANSLATION STRATEGIES — the caller picks the one that fits the context:
 *
 *   Grade strategy  (translateToGrade / translateGoalAttainmentToGrade)
 *     → Returns a GradeDTO (grade, mention, formatted string like "14.5/20")
 *     → Used in the manager backoffice, coaching sessions, bulletin de notes
 *     → Standalone grade works for percentage/score metrics; GoalRelativeOnly
 *       metrics (revenue, outstanding) require a goal for grade translation
 *
 *   Verdict strategy  (translateToVerdict / translateGoalAttainmentToVerdict)
 *     → Returns a MetricVerdictDTO (full French sentence + VerdictStatus)
 *     → Used in the mobile salesperson app — always requires a target value
 *     → Median metrics (MedianDaysToPayment, MedianDaysDelinquent) return null
 *       because "médiane" has no actionable meaning for a field salesperson
 *
 * Callers that need both (e.g. manager backoffice showing grade AND sentence)
 * call both methods independently and combine the results themselves.
 */
readonly class MetricTranslatorService
{
    /**
     * Number of distinct products per invoice considered a perfect push score.
     * Reaching this average grades as 20/20. Adjust as business expectations evolve.
     */
    private const float PUSH_SCORE_PERFECT_CEILING = 5.0;

    /**
     * Worst acceptable number of days to payment — grades as 0/20.
     * Read from config at construction so it can be overridden via .env.
     */
    private int $worstAcceptableDaysToPayment;

    public function __construct()
    {
        $this->worstAcceptableDaysToPayment = config('bayal.ar.worst_acceptable_days_to_payment');
    }

    // =========================================================================
    // Grade strategy
    // =========================================================================

    /**
     * Translate a raw metric value into a 0–20 grade without a goal reference.
     *
     * Returns null for GoalRelativeOnly metrics (revenue, basket size, outstanding amount)
     * because they have no universal standalone scale.
     * Use translateGoalAttainmentToGrade() when a Goal is available.
     */
    public function translateToGrade(GoalMetric $metric, float $actualValue): ?GradeDTO
    {
        return match ($metric->gradeTranslationStrategy()) {
            GradeTranslationStrategy::GoalRelativeOnly => null,
            GradeTranslationStrategy::HigherPercentage => $this->gradeFromHigherPercentage($actualValue),
            GradeTranslationStrategy::LowerPercentage => $this->gradeFromLowerPercentage($actualValue),
            GradeTranslationStrategy::BoundedScore => $this->gradeFromBoundedScore($actualValue, self::PUSH_SCORE_PERFECT_CEILING),
            GradeTranslationStrategy::LowerBounded => $this->gradeFromLowerBounded($actualValue, $this->worstAcceptableDaysToPayment),
        };
    }

    /**
     * Translate a goal attainment rate into a 0–20 grade.
     *
     * Works for ALL metrics including monetary ones — the goal target is the reference.
     * Attainment is capped at 100% so overachievement stays at 20/20.
     */
    public function translateGoalAttainmentToGrade(GoalAttainmentDTO $attainment): GradeDTO
    {
        $grade = min($attainment->attainmentRate / 100.0, 1.0) * 20.0;

        return new GradeDTO(round($grade, 1));
    }

    // =========================================================================
    // Verdict strategy
    // =========================================================================

    /**
     * Translate a raw metric value into a plain French verdict sentence anchored to a target.
     *
     * Returns null when:
     *   - The metric is not verdictable (median metrics).
     *   - targetValue is zero or negative (no meaningful comparison possible).
     *
     * The sentence is built by GoalMetric::buildVerdictSentence(), which owns the
     * phrasing for each metric. The status signal (✅ / 🟡 / 🔴) is appended at the end.
     */
    public function translateToVerdict(GoalMetric $metric, float $actualValue, float $targetValue): ?MetricVerdictDTO
    {
        if (! $metric->isVerdictable() || $targetValue <= 0) {
            return null;
        }

        $status = $this->resolveVerdictStatus($metric, $actualValue, $targetValue);

        $sentence = $metric->buildVerdictSentence(
            formattedActual: $metric->formatValue($actualValue),
            formattedTarget: $metric->formatValue($targetValue),
            status: $status,
        );

        return new MetricVerdictDTO(
            metric: $metric,
            actualValue: $actualValue,
            targetValue: $targetValue,
            sentence: $sentence,
            status: $status,
        );
    }

    /**
     * Translate a goal attainment into a verdict sentence.
     *
     * Convenience wrapper over translateToVerdict() — pulls metric, actual, and target
     * directly from the GoalAttainmentDTO so callers don't need to unpack it.
     */
    public function translateGoalAttainmentToVerdict(GoalAttainmentDTO $attainment): ?MetricVerdictDTO
    {
        return $this->translateToVerdict(
            metric: $attainment->goal->metric,
            actualValue: $attainment->actualValue,
            targetValue: $attainment->targetValue,
        );
    }

    // =========================================================================
    // Private — grade formulas
    // =========================================================================

    /** Higher is better: 100% → 20/20, 0% → 0/20. */
    private function gradeFromHigherPercentage(float $percentage): GradeDTO
    {
        return new GradeDTO(round(min(max($percentage, 0.0), 100.0) / 5.0, 1));
    }

    /** Lower is better: 0% → 20/20, 100% → 0/20. */
    private function gradeFromLowerPercentage(float $percentage): GradeDTO
    {
        return new GradeDTO(round(max(0.0, 100.0 - $percentage) / 5.0, 1));
    }

    /** Score with a defined perfect ceiling: ceiling → 20/20, 0 → 0/20. */
    private function gradeFromBoundedScore(float $value, float $perfectCeiling): GradeDTO
    {
        return new GradeDTO(round(min($value / $perfectCeiling, 1.0) * 20.0, 1));
    }

    /** Days-based: 0 days → 20/20, reaching ceiling → 0/20, beyond ceiling stays at 0/20. */
    private function gradeFromLowerBounded(float $value, int $ceiling): GradeDTO
    {
        return new GradeDTO(round(max(0.0, 1.0 - $value / $ceiling) * 20.0, 1));
    }

    // =========================================================================
    // Private — verdict status resolution
    // =========================================================================

    /**
     * Determine the verdict status by comparing actual vs target.
     *
     * Tolerance bands (applied symmetrically regardless of direction):
     *   - Higher-is-better: OnTrack ≥ target, AtRisk ≥ 80% of target, BelowTarget < 80%
     *   - Lower-is-better:  OnTrack ≤ target, AtRisk ≤ 125% of target, BelowTarget > 125%
     */
    private function resolveVerdictStatus(GoalMetric $metric, float $actual, float $target): VerdictStatus
    {
        if ($metric->lowerIsBetter()) {
            if ($actual <= $target) {
                return VerdictStatus::OnTrack;
            }

            if ($actual <= $target * 1.25) {
                return VerdictStatus::AtRisk;
            }

            return VerdictStatus::BelowTarget;
        }

        if ($actual >= $target) {
            return VerdictStatus::OnTrack;
        }

        if ($actual >= $target * 0.80) {
            return VerdictStatus::AtRisk;
        }

        return VerdictStatus::BelowTarget;
    }
}
