<?php

namespace App\Data\KPI;

use App\Attributes\MetricInfo;
use App\Data\Concerns\HasMetricsMetadata;

/**
 * Holds computed customer coverage and visit metrics for a given scope and period.
 *
 * Scope-neutral: the caller decides whether this represents a single commercial,
 * a beat, a team, or the entire business — the DTO carries no reference to who it belongs to.
 *
 * Coverage metrics describe how well the commercial is reaching and converting
 * their customer base, tracking the full customer lifecycle from prospect to confirmed
 * buyer to churn risk.
 *
 * Rate values are floats between 0.0 and 100.0, rounded to 1 decimal place.
 * Count values are integers.
 */
readonly class CustomerCoverageMetricsDTO
{
    use HasMetricsMetadata;

    public function __construct(
        /**
         * FR: Clients Visités
         *
         * Number of distinct customers who received at least one visit in the period.
         */
        #[MetricInfo(
            label: 'Clients Visités',
            description: 'Nombre de clients ayant reçu au moins une visite sur la période. Mesure l\'effort de prospection réel du commercial.',
        )]
        public int $visitedCustomersCount,

        /**
         * FR: Clients Actifs
         *
         * Number of distinct customers who placed at least one order (had at least one
         * invoice) in the period. A customer who was visited but did not buy is not active.
         */
        #[MetricInfo(
            label: 'Clients Actifs',
            description: 'Nombre de clients ayant passé au moins une commande sur la période. Un client visité mais sans achat ne compte pas comme actif.',
        )]
        public int $activeCustomersCount,

        /**
         * FR: Taux de Frappe (Tournées)
         *
         * Visit strike rate — activeCustomersCount ÷ visitedCustomersCount × 100.
         * During a round: the percentage of visited customers who engaged in a sale
         * or payment. A low rate signals that visits are not converting into transactions.
         * 0.0 when no customers were visited.
         */
        #[MetricInfo(
            label: 'Taux de Frappe (Tournées)',
            description: 'Lors des tournées : pourcentage de clients visités ayant effectué un achat ou un paiement. Un taux faible indique que les visites ne se transforment pas en transactions — il faut revoir l\'argumentaire ou le ciblage.',
        )]
        public float $visitStrikeRate,

        /**
         * FR: Taux de Frappe (Acquisition)
         *
         * Acquisition strike rate — newConfirmedCustomersCount ÷ (newConfirmedCustomersCount
         * + newProspectCustomersCount) × 100.
         * During prospecting: of all new customers created in the period, the percentage
         * who made their first purchase immediately rather than remaining a prospect.
         * A high rate means the commercial is creating customers who buy right away.
         * 0.0 when no new customers were created in the period.
         */
        #[MetricInfo(
            label: 'Taux de Frappe (Acquisition)',
            description: 'Lors de la prospection : parmi tous les nouveaux clients créés sur la période, pourcentage ayant effectué un achat immédiatement plutôt que de rester en statut prospect. Un taux élevé signifie que le commercial convertit directement ses nouveaux contacts en acheteurs.',
        )]
        public float $acquisitionStrikeRate,

        /**
         * FR: Clients Fidèles
         *
         * Number of customers who were active in the previous comparable period
         * and remained active in the current period.
         */
        #[MetricInfo(
            label: 'Clients Fidèles',
            description: 'Nombre de clients ayant acheté à la fois sur la période précédente et sur la période actuelle. Indique la capacité du commercial à fidéliser sa clientèle.',
        )]
        public int $returningCustomersCount,

        /**
         * FR: Taux de Fidélisation
         *
         * Customer retention rate — returningCustomersCount ÷ previousPeriodActiveCustomersCount × 100.
         * Measures how well the commercial retains customers between periods.
         * A declining retention rate is an early warning of customer churn.
         * 0.0 when no customers were active in the previous period.
         */
        #[MetricInfo(
            label: 'Taux de Fidélisation',
            description: 'Pourcentage de clients actifs de la période précédente qui ont à nouveau acheté sur la période actuelle. Un taux en baisse est un signal précoce de perte de clientèle.',
        )]
        public float $customerRetentionRate,

        /**
         * FR: Nouveaux Clients Confirmés
         *
         * Number of customers who became confirmed buyers for the first time in this period
         * (i.e. they had no invoice prior to this period but purchased now).
         * Tracks genuine new customer acquisition — not just visits, but actual first sales.
         */
        #[MetricInfo(
            label: 'Nouveaux Clients Confirmés',
            description: 'Nombre de clients ayant effectué leur premier achat sur la période. Mesure la capacité du commercial à acquérir de nouveaux clients actifs.',
        )]
        public int $newConfirmedCustomersCount,

        /**
         * FR: Nouveaux Prospects
         *
         * Number of customers visited for the first time in this period who did not yet
         * make a purchase. They are in the pipeline — visited but not yet converted.
         */
        #[MetricInfo(
            label: 'Nouveaux Prospects',
            description: 'Nombre de clients visités pour la première fois sur la période sans avoir encore acheté. Ce sont des clients en attente de conversion.',
        )]
        public int $newProspectCustomersCount,

        /**
         * FR: Prospects Convertis
         *
         * Number of prospects from previous periods who placed their first order
         * in the current period. Measures the pipeline conversion effectiveness.
         */
        #[MetricInfo(
            label: 'Prospects Convertis',
            description: 'Nombre de prospects des périodes précédentes ayant finalement passé commande sur la période actuelle. Mesure l\'efficacité de transformation du pipeline commercial.',
        )]
        public int $prospectsConvertedToConfirmedCount,

        /**
         * FR: Clients en Risque de Perte (Churn)
         *
         * Number of customers who were active in the previous period but placed no order
         * in the current period. A leading indicator of customer loss — these clients
         * need immediate re-engagement.
         */
        #[MetricInfo(
            label: 'Clients en Risque de Perte',
            description: 'Nombre de clients actifs lors de la période précédente n\'ayant passé aucune commande sur la période actuelle. Ce sont des clients à risque qui nécessitent une relance urgente.',
        )]
        public int $churningCustomersCount,

        /**
         * FR: Taux de Churn
         *
         * Churning rate — churningCustomersCount ÷ previousPeriodActiveCustomersCount × 100.
         * The percentage of previously active customers who stopped buying.
         * A rising churn rate is a critical warning signal — it outweighs new customer gains
         * when the base is shrinking.
         * 0.0 when no customers were active in the previous period.
         */
        #[MetricInfo(
            label: 'Taux de Churn',
            description: 'Pourcentage de clients actifs de la période précédente n\'ayant pas acheté sur la période actuelle. Un taux en hausse est un signal critique : le commercial perd sa base client plus vite qu\'il n\'en acquiert de nouveaux.',
        )]
        public float $churningRate,
    ) {}
}
