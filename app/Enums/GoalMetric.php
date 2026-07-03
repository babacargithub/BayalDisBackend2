<?php

namespace App\Enums;

/**
 * Enumeration of all metrics that can be targeted by a Goal.
 *
 * Each case maps to a snake_case property name that exists on one of the KPI DTOs.
 * The string value is stored in the database — adding new cases requires no migration.
 *
 * Beyond metadata, each case also owns its verdict sentence template and value formatter,
 * so the sentence logic stays co-located with the metric it describes.
 */
enum GoalMetric: string
{
    case TotalRevenue = 'total_revenue';
    case AverageBasketSize = 'average_basket_size';
    case TotalOutstandingAmount = 'total_outstanding_amount';
    case PushScore = 'push_score';
    case VisitStrikeRate = 'visit_strike_rate';
    case AcquisitionStrikeRate = 'acquisition_strike_rate';
    case OnTimePaymentRate = 'on_time_payment_rate';
    case CollectionEffectivenessIndex = 'collection_effectiveness_index';
    case CustomerRetentionRate = 'customer_retention_rate';
    case ChurningRate = 'churning_rate';
    case AverageDaysToPayment = 'average_days_to_payment';
    case MedianDaysToPayment = 'median_days_to_payment';
    case AverageDaysDelinquent = 'average_days_delinquent';
    case MedianDaysDelinquent = 'median_days_delinquent';

    // =========================================================================
    // Metadata — labels and descriptions (French, for API consumers)
    // =========================================================================

    public function label(): string
    {
        return match ($this) {
            self::TotalRevenue => 'Chiffre d\'Affaires',
            self::AverageBasketSize => 'Panier Moyen',
            self::TotalOutstandingAmount => 'Montant Impayé',
            self::PushScore => 'Score de Push Produit',
            self::VisitStrikeRate => 'Taux de Frappe (Tournées)',
            self::AcquisitionStrikeRate => 'Taux de Frappe (Acquisition)',
            self::OnTimePaymentRate => 'Taux de Paiement dans les Délais',
            self::CollectionEffectivenessIndex => 'Indice d\'Efficacité de Recouvrement (IER)',
            self::CustomerRetentionRate => 'Taux de Fidélisation',
            self::ChurningRate => 'Taux de Churn',
            self::AverageDaysToPayment => 'Délai Moyen de Paiement',
            self::MedianDaysToPayment => 'Délai Médian de Paiement',
            self::AverageDaysDelinquent => 'Retard Moyen de Paiement',
            self::MedianDaysDelinquent => 'Retard Médian de Paiement',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::TotalRevenue => 'Montant total des ventes réalisées sur la période.',
            self::AverageBasketSize => 'Valeur moyenne d\'une facture sur la période.',
            self::TotalOutstandingAmount => 'Total des factures non encore réglées dans le portefeuille.',
            self::PushScore => 'Nombre moyen de produits distincts vendus par facture.',
            self::VisitStrikeRate => 'Pourcentage de clients visités ayant effectué un achat ou paiement.',
            self::AcquisitionStrikeRate => 'Pourcentage de nouveaux clients créés ayant acheté immédiatement.',
            self::OnTimePaymentRate => 'Pourcentage de factures réglées avant ou à la date d\'échéance.',
            self::CollectionEffectivenessIndex => 'Pourcentage des créances exigibles effectivement encaissées.',
            self::CustomerRetentionRate => 'Pourcentage de clients actifs de la période précédente ayant racheté.',
            self::ChurningRate => 'Pourcentage de clients actifs précédemment devenus inactifs.',
            self::AverageDaysToPayment => 'Nombre moyen de jours entre la création de la facture et son règlement.',
            self::MedianDaysToPayment => 'Délai médian (en jours) entre la création de la facture et son règlement.',
            self::AverageDaysDelinquent => 'Nombre moyen de jours de retard après la date d\'échéance.',
            self::MedianDaysDelinquent => 'Retard médian (en jours) après la date d\'échéance.',
        };
    }

    // =========================================================================
    // Direction and scale
    // =========================================================================

    /** Whether a lower value is better for this metric (churn, days, outstanding). */
    public function lowerIsBetter(): bool
    {
        return match ($this) {
            self::ChurningRate,
            self::TotalOutstandingAmount,
            self::AverageDaysToPayment,
            self::MedianDaysToPayment,
            self::AverageDaysDelinquent,
            self::MedianDaysDelinquent => true,
            default => false,
        };
    }

    /**
     * Which formula MetricTranslatorService should use to convert a raw value into a 0–20 grade.
     *
     * GoalRelativeOnly: no universal standalone scale — grade only available via goal attainment.
     */
    public function gradeTranslationStrategy(): GradeTranslationStrategy
    {
        return match ($this) {
            self::TotalRevenue,
            self::AverageBasketSize,
            self::TotalOutstandingAmount => GradeTranslationStrategy::GoalRelativeOnly,

            self::ChurningRate => GradeTranslationStrategy::LowerPercentage,

            self::PushScore => GradeTranslationStrategy::BoundedScore,

            self::AverageDaysToPayment,
            self::MedianDaysToPayment,
            self::AverageDaysDelinquent,
            self::MedianDaysDelinquent => GradeTranslationStrategy::LowerBounded,

            self::VisitStrikeRate,
            self::AcquisitionStrikeRate,
            self::OnTimePaymentRate,
            self::CollectionEffectivenessIndex,
            self::CustomerRetentionRate => GradeTranslationStrategy::HigherPercentage,
        };
    }

    // =========================================================================
    // Verdict de Terrain — plain sentence translation
    // =========================================================================

    /**
     * Whether this metric supports verdict translation.
     *
     * Median metrics are excluded: "médiane" is a statistical concept that a field
     * salesperson cannot act on. They remain available for grade translation and
     * manager dashboards only.
     */
    public function isVerdictable(): bool
    {
        return ! in_array($this, [self::MedianDaysToPayment, self::MedianDaysDelinquent]);
    }

    /**
     * Format a raw value into the human-readable unit string used in verdict sentences.
     * The unit varies by metric: XOF for money, jours for days, % for rates, etc.
     */
    public function formatValue(float $value): string
    {
        return match ($this) {
            self::TotalRevenue,
            self::AverageBasketSize,
            self::TotalOutstandingAmount => number_format($value, 0, ',', ' ').' XOF',

            self::PushScore => number_format($value, 1).' produits distincts',

            self::AverageDaysToPayment,
            self::MedianDaysToPayment,
            self::AverageDaysDelinquent,
            self::MedianDaysDelinquent => self::formatDays($value),

            self::VisitStrikeRate,
            self::AcquisitionStrikeRate,
            self::OnTimePaymentRate,
            self::CollectionEffectivenessIndex,
            self::CustomerRetentionRate,
            self::ChurningRate => self::formatPercentage($value),
        };
    }

    /**
     * Build the full verdict sentence for this metric.
     *
     * The sentence includes the status signal (emoji + label) at the end so the
     * commercial can scan the end of the sentence for the verdict without reading everything.
     *
     * Called by MetricTranslatorService — never call directly.
     */
    public function buildVerdictSentence(string $formattedActual, string $formattedTarget, VerdictStatus $status): string
    {
        $signal = $status->emoji().' '.$status->label().'.';

        return match ($this) {
            self::TotalRevenue => "Tu as réalisé {$formattedActual} de chiffre d'affaires. L'objectif est {$formattedTarget}. {$signal}",

            self::AverageBasketSize => "Ton panier moyen est de {$formattedActual} par facture. L'objectif est {$formattedTarget}. {$signal}",

            self::TotalOutstandingAmount => "Tu as {$formattedActual} non encaissés chez tes clients. L'objectif est de rester sous {$formattedTarget}. {$signal}",

            self::PushScore => "Tu vends {$formattedActual} en moyenne par facture. L'objectif est {$formattedTarget}. {$signal}",

            self::VisitStrikeRate => "{$formattedActual} de tes clients visités ont acheté ou payé. L'objectif est {$formattedTarget}. {$signal}",

            self::AcquisitionStrikeRate => "{$formattedActual} de tes nouveaux clients ont acheté immédiatement. L'objectif est {$formattedTarget}. {$signal}",

            self::OnTimePaymentRate => "{$formattedActual} de tes factures ont été réglées dans les délais. L'objectif est {$formattedTarget}. {$signal}",

            self::CollectionEffectivenessIndex => "Tu as encaissé {$formattedActual} de ce qui était exigible. L'objectif est {$formattedTarget}. {$signal}",

            self::CustomerRetentionRate => "{$formattedActual} de tes clients du mois précédent ont racheté. L'objectif est {$formattedTarget}. {$signal}",

            self::ChurningRate => "{$formattedActual} de tes anciens clients sont devenus inactifs. L'objectif est de rester sous {$formattedTarget}. {$signal}",

            self::AverageDaysToPayment => "Tes clients te paient en moyenne en {$formattedActual}. L'objectif est {$formattedTarget} maximum. {$signal}",

            self::AverageDaysDelinquent => "Quand un client paie en retard, il dépasse l'échéance de {$formattedActual} en moyenne. L'objectif est {$formattedTarget} maximum. {$signal}",

            // Median metrics are not verdictable — guarded by isVerdictable() upstream.
            self::MedianDaysToPayment,
            self::MedianDaysDelinquent => '',
        };
    }

    // =========================================================================
    // Private — value formatting helpers
    // =========================================================================

    private static function formatDays(float $value): string
    {
        $formatted = fmod($value, 1.0) === 0.0
            ? number_format($value, 0)
            : number_format($value, 1);

        return $formatted.($value <= 1.0 ? ' jour' : ' jours');
    }

    private static function formatPercentage(float $value): string
    {
        $rounded = round($value, 1);

        return (fmod($rounded, 1.0) === 0.0 ? (int) $rounded : $rounded).'%';
    }
}
