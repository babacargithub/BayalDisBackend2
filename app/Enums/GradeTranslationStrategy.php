<?php

namespace App\Enums;

/**
 * Determines how a raw metric value is converted into a 0–20 grade.
 *
 * Each GoalMetric case reports which strategy applies via gradeTranslationStrategy().
 * The MetricGradeTranslator dispatches to the correct formula based on this value.
 */
enum GradeTranslationStrategy
{
    /** Percentage where higher is better (e.g. onTimePaymentRate). grade = value / 5 */
    case HigherPercentage;

    /** Percentage where lower is better (e.g. churningRate). grade = (100 − value) / 5 */
    case LowerPercentage;

    /** A score with a defined perfect ceiling (e.g. pushScore). grade = min(value / ceiling, 1) × 20 */
    case BoundedScore;

    /**
     * A value where 0 is perfect and a defined ceiling is worst (e.g. averageDaysToPayment).
     * grade = max(0, 1 − value / ceiling) × 20
     * Ceiling is read from config('bayal.ar.worst_acceptable_days_to_payment').
     */
    case LowerBounded;

    /**
     * Only meaningful relative to a goal target (e.g. totalRevenue, averageBasketSize).
     * Standalone translation returns null — no absolute scale exists without a reference.
     */
    case GoalRelativeOnly;
}
