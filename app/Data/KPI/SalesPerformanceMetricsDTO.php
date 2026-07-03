<?php

namespace App\Data\KPI;

use App\Attributes\MetricInfo;
use App\Data\Concerns\HasMetricsMetadata;

/**
 * Holds computed sales performance metrics for a given scope and period.
 *
 * Scope-neutral: the caller decides whether this represents a single commercial,
 * a beat, a team, or the entire business — the DTO carries no reference to whom it belongs to.
 *
 * All money values are integers (XOF).
 * Count values are integers.
 */
readonly class SalesPerformanceMetricsDTO
{
    use HasMetricsMetadata;

    public function __construct(
        /**
         * FR: Chiffre d'Affaires Total
         *
         * Sum of total_amount across all invoices in scope for the period.
         * Expressed in XOF.
         */
        #[MetricInfo(
            label: 'Chiffre d\'Affaires Total',
            description: 'Montant total des ventes réalisées sur la période, exprimé en XOF. C\'est l\'indicateur de volume de vente le plus direct.',
        )]
        public int $totalRevenue,

        /**
         * FR: Nombre de Factures Émises
         *
         * Total number of invoices created in the period (all statuses).
         */
        #[MetricInfo(
            label: 'Nombre de Factures Émises',
            description: 'Nombre total de factures créées sur la période, tous statuts confondus. Indique le volume d\'activité commerciale.',
        )]
        public int $totalInvoicesCount,

        /**
         * FR: Nombre de Clients Servis
         *
         * Count of distinct customers who had at least one invoice in the period.
         */
        #[MetricInfo(
            label: 'Clients Servis',
            description: 'Nombre de clients distincts ayant reçu au moins une facture sur la période. Mesure la portée réelle de l\'activité commerciale.',
        )]
        public int $uniqueCustomersServedCount,

        /**
         * FR: Panier Moyen
         *
         * Average invoice value — totalRevenue ÷ totalInvoicesCount.
         * Tells you whether customers are buying more or less per transaction.
         * 0 when no invoices exist in the period.
         * Expressed in XOF.
         */
        #[MetricInfo(
            label: 'Panier Moyen',
            description: 'Valeur moyenne d\'une facture sur la période, en XOF. Une hausse du panier moyen signifie que les clients achètent davantage à chaque commande.',
        )]
        public int $averageBasketSize,

        /**
         * FR: Chiffre d'Affaires Moyen par Client
         *
         * Average revenue per distinct customer served — totalRevenue ÷ uniqueCustomersServedCount.
         * Detects route efficiency: a high figure means each customer relationship generates
         * significant value, regardless of how many customers were visited.
         * 0 when no customers were served. Expressed in XOF.
         */
        #[MetricInfo(
            label: 'Chiffre d\'Affaires Moyen par Client',
            description: 'Revenu moyen généré par client servi sur la période, en XOF. Permet de mesurer la valeur de chaque relation client indépendamment du volume de visites.',
        )]
        public int $averageRevenuePerCustomer,
    ) {}

}
