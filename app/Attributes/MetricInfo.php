<?php

namespace App\Attributes;

use Attribute;

/**
 * Decorates a metric property with its French label and description.
 *
 * Applied to DTO properties that represent KPI metrics so that API consumers
 * (e.g. the mobile app) can display the metric's meaning to commercials without
 * hard-coding labels on the client side.
 *
 * Usage:
 *   #[MetricInfo(
 *       label: 'Délai Moyen de Paiement',
 *       description: 'Nombre moyen de jours entre la création d\'une facture et son règlement complet.',
 *   )]
 *   public float $averageDaysToPayment,
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class MetricInfo
{
    public function __construct(
        /** Short display label shown as the metric title in the UI. */
        public string $label,

        /** Plain-French explanation of what the metric measures and how to interpret it. */
        public string $description,
    ) {}
}
