<?php

namespace App\Data\Vente;

/**
 * Computed sales statistics for a single product over a given period.
 *
 * All money values are integers (XOF). Percentages are floats rounded to 2 decimal places.
 */
readonly class ProductSalesStatsDTO
{
    public function __construct(
        public int $productId,
        public string $productName,

        /** Total units sold across all invoice items in the period. */
        public int $totalQuantitySold,

        /** Number of distinct customers who bought this product in the period. */
        public int $distinctCustomersCount,

        /** Gross revenue = SUM(price × quantity) for this product. */
        public int $totalAmountSold,

        /** Estimated profit = SUM(ventes.profit) for this product. */
        public int $totalEstimatedProfit,

        /** This product's share of total gross revenue (0–100). */
        public float $salesContributionPercentage,

        /** This product's share of total estimated profit (0–100). */
        public float $profitContributionPercentage,
    ) {}

    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'product_name' => $this->productName,
            'total_quantity_sold' => $this->totalQuantitySold,
            'distinct_customers_count' => $this->distinctCustomersCount,
            'total_amount_sold' => $this->totalAmountSold,
            'total_estimated_profit' => $this->totalEstimatedProfit,
            'sales_contribution_percentage' => $this->salesContributionPercentage,
            'profit_contribution_percentage' => $this->profitContributionPercentage,
        ];
    }
}
