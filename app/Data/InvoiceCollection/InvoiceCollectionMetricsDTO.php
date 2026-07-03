<?php

namespace App\Data\InvoiceCollection;

use App\Attributes\MetricInfo;
use App\Data\Concerns\HasMetricsMetadata;

/**
 * Holds computed accounts-receivable collection metrics for a given set of invoices
 * over a specific period.
 *
 * Scope-neutral: the caller decides whether this represents a single commercial,
 * a zone, a customer, or the entire business — the DTO carries no reference to
 * who it belongs to.
 *
 * Period-based metrics (DSO, ADD, on-time rate, CEI) cover FULLY_PAID invoices
 * in the requested date range. The outstanding amount is a real-time snapshot
 * of unpaid balance across all non-FULLY_PAID invoices in scope, regardless of period.
 *
 * All money values are integers (XOF).
 * Rate and index values are floats between 0.0 and 100.0, rounded to 1 decimal place.
 * Day averages are floats rounded to 1 decimal place.
 */
readonly class InvoiceCollectionMetricsDTO
{
    use HasMetricsMetadata;

    public function __construct(
        /**
         * FR: Délai Moyen de Paiement (DMP)
         *
         * Days Sales Outstanding — average number of days between invoice creation
         * and full payment, across all FULLY_PAID invoices in the period.
         * 0.0 when no fully paid invoices exist in the period.
         */
        #[MetricInfo(
            label: 'Délai Moyen de Paiement (DMP)',
            description: 'Nombre moyen de jours entre la création d\'une facture et son règlement complet. Plus ce chiffre est bas, plus les clients paient rapidement.',
        )]
        public float $averageDaysToPayment,

        /**
         * FR: Délai Médian de Paiement
         *
         * Median days to full payment — the midpoint value when all FULLY_PAID invoices
         * are sorted by days_to_payment. Unaffected by outliers: one customer who took
         * 90 days does not distort this figure.
         * 0.0 when no fully paid invoices exist in the period.
         */
        #[MetricInfo(
            label: 'Délai Médian de Paiement',
            description: 'La moitié des factures sont réglées en moins de X jours. Contrairement à la moyenne, cette valeur n\'est pas influencée par les très grands retards isolés.',
        )]
        public float $medianDaysToPayment,

        /**
         * FR: Retard Moyen de Paiement (RMP)
         *
         * Average Days Delinquent — average number of days invoices were paid past
         * their due date (should_be_paid_at). Early or on-time payments contribute 0.
         * 0.0 when no fully paid invoices exist in the period.
         */
        #[MetricInfo(
            label: 'Retard Moyen de Paiement (RMP)',
            description: 'Nombre moyen de jours de retard par rapport à la date d\'échéance. Un paiement avant échéance compte pour 0. Plus ce chiffre est proche de zéro, plus les clients sont ponctuels.',
        )]
        public float $averageDaysDelinquent,

        /**
         * FR: Retard Médian de Paiement
         *
         * Median days delinquent — the midpoint value when all FULLY_PAID invoices are
         * sorted by days_delinquent. Unaffected by outliers: one customer who paid
         * 60 days late does not distort this figure.
         * 0.0 when no fully paid invoices exist in the period.
         */
        #[MetricInfo(
            label: 'Retard Médian de Paiement',
            description: 'La moitié des factures en retard sont réglées avec moins de X jours de retard. Plus fiable que la moyenne car elle n\'est pas faussée par quelques clients très mauvais payeurs.',
        )]
        public float $medianDaysDelinquent,

        /**
         * FR: Taux de Paiement dans les Délais
         *
         * On-Time Payment Rate — percentage of FULLY_PAID invoices that were settled
         * on or before their should_be_paid_at date.
         * Range: 0.0–100.0. 0.0 when no fully paid invoices exist.
         */
        #[MetricInfo(
            label: 'Taux de Paiement dans les Délais',
            description: 'Pourcentage de factures réglées avant ou à la date d\'échéance. Un taux de 100 % signifie que tous les clients ont payé à temps.',
        )]
        public float $onTimePaymentRate,

        /**
         * FR: Indice d'Efficacité de Recouvrement (IER)
         *
         * Collection Effectiveness Index — percentage of collectible receivables
         * (beginning AR + period sales minus current not-yet-due AR) that were
         * actually collected during the period.
         * Range: 0.0–100.0. 0.0 when nothing was collectible in the period.
         */
        #[MetricInfo(
            label: 'Indice d\'Efficacité de Recouvrement (IER)',
            description: 'Pourcentage des créances exigibles effectivement encaissées sur la période. Un indice de 100 % signifie que tout ce qui était dû a été collecté. C\'est l\'indicateur le plus global de la performance de recouvrement.',
        )]
        public float $collectionEffectivenessIndex,

        /**
         * FR: Nombre Total de Factures
         *
         * Total number of invoices in scope for the period (all statuses).
         * Used as the denominator context for management reporting.
         */
        #[MetricInfo(
            label: 'Nombre Total de Factures',
            description: 'Nombre total de factures émises sur la période, tous statuts confondus (réglées, partiellement réglées, impayées).',
        )]
        public int $totalInvoicesCount,

        /**
         * FR: Nombre de Factures Entièrement Réglées
         *
         * Number of FULLY_PAID invoices in the period.
         * This is the denominator for averageDaysToPayment, averageDaysDelinquent,
         * and onTimePaymentRate.
         */
        #[MetricInfo(
            label: 'Factures Entièrement Réglées',
            description: 'Nombre de factures intégralement payées sur la période. Ce chiffre sert de base de calcul pour le délai moyen, le retard moyen et le taux de ponctualité.',
        )]
        public int $fullyPaidInvoicesCount,

        /**
         * FR: Nombre de Factures Payées dans les Délais
         *
         * Number of invoices paid on or before their should_be_paid_at date.
         * Raw count behind onTimePaymentRate — useful for display ("7 / 10 on time").
         */
        #[MetricInfo(
            label: 'Factures Payées dans les Délais',
            description: 'Nombre de factures réglées avant ou à la date d\'échéance prévue. Utile pour afficher le ratio brut, par exemple "7 factures sur 10 payées à temps".',
        )]
        public int $onTimeInvoicesCount,

        /**
         * FR: Encours Total (Créances Impayées)
         *
         * Current outstanding balance — SUM(total_amount - total_payments) across all
         * non-FULLY_PAID invoices in scope. Real-time snapshot, not period-filtered.
         * Expressed in XOF.
         */
        #[MetricInfo(
            label: 'Encours Total (Créances Impayées)',
            description: 'Montant total restant dû sur toutes les factures non entièrement réglées. C\'est la somme que le commercial doit encore recouvrer auprès de ses clients, exprimée en XOF.',
        )]
        public int $totalOutstandingAmount,
    ) {}

}
