<?php

namespace App\Data\KPI;

use App\Attributes\MetricInfo;
use App\Data\Concerns\HasMetricsMetadata;
use App\Data\MetricDistribution;

/**
 * Holds computed product mix and basket composition metrics for a given scope and period.
 *
 * Scope-neutral: the caller decides whether this represents a single commercial,
 * a beat, a team, or the entire business — the DTO carries no reference to who it belongs to.
 *
 * Product mix metrics reveal whether customers are buying a diverse range of products
 * or concentrating on a single item. Basket breadth is particularly important in
 * this application because the commission system rewards selling across all required
 * product categories (basket bonus).
 *
 * Rate values are floats rounded to 1 decimal place.
 * Count values are integers.
 */
readonly class ProductMixMetricsDTO
{
    use HasMetricsMetadata;

    public function __construct(
        /**
         * FR: Score de Push Produit
         *
         * Push score — average number of distinct products sold per invoice in the period.
         * Rewards commercials for diversifying each customer's basket: a score of 3.0 means
         * the commercial sells 3 different products on average per visit.
         * The higher the score, the more effectively the commercial pushes the product range.
         * 0.0 when no invoices exist in the period.
         */
        #[MetricInfo(
            label: 'Score de Push Produit',
            description: 'Nombre moyen de produits distincts vendus par facture. Récompense les commerciaux qui diversifient le panier de chaque client : un score de 3 signifie que le commercial vend en moyenne 3 produits différents par visite. Plus le score est élevé, plus le commercial pousse efficacement la gamme.',
        )]
        public float $pushScore,

        /**
         * FR: Largeur Moyenne du Panier (Catégories)
         *
         * Average number of distinct product categories per invoice — basket breadth.
         * This is the key signal for the commission basket bonus: a high figure means
         * commercials are successfully cross-selling across categories.
         * 0.0 when no invoices exist in the period.
         */
        #[MetricInfo(
            label: 'Largeur Moyenne du Panier',
            description: 'Nombre moyen de catégories de produits différentes par facture. Un panier large signifie que le commercial vend efficacement plusieurs catégories à chaque client — ce qui est récompensé par le bonus panier de la commission.',
        )]
        public float $averageProductCategoriesPerInvoice,

        /**
         * FR: Références Vendues
         *
         * Total number of distinct products sold at least once in the period.
         * Indicates the breadth of the product catalogue actually being used.
         */
        #[MetricInfo(
            label: 'Références Vendues',
            description: 'Nombre de références produits distinctes vendues au moins une fois sur la période. Mesure la largeur de la gamme effectivement écoulée.',
        )]
        public int $totalDistinctProductsSoldCount,

        /**
         * FR: Catégories Vendues
         *
         * Total number of distinct product categories represented in sales for the period.
         * When compared against the total number of active categories, this reveals
         * which categories are being neglected.
         */
        #[MetricInfo(
            label: 'Catégories Vendues',
            description: 'Nombre de catégories de produits distinctes représentées dans les ventes de la période. Comparé au nombre total de catégories actives, cela révèle les catégories négligées.',
        )]
        public int $totalDistinctCategoriesSoldCount,

        /**
         * Frequency distribution of the push score across all invoices in the period.
         *
         * Each bucket shows how many invoices had exactly N distinct products (1, 2, 3, …).
         * Reveals whether the commercial pushes consistently or only occasionally.
         * Not annotated with MetricInfo — it is a composite object, not a scalar metric.
         */
        public MetricDistribution $pushScoreDistribution,
    ) {}
}
