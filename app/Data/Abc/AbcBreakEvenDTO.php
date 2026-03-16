<?php

namespace App\Data\Abc;

/**
 * Break-even analysis derived from historical sales invoices.
 *
 * The average gross margin rate is computed across ALL sales invoices:
 *   margin_rate = SUM(total_estimated_profit) / SUM(total_amount)
 *
 * The daily sales required to cover costs is:
 *   required_revenue = daily_total_cost / margin_rate
 *
 * Null values indicate insufficient data (no invoices, zero revenue, or zero margin).
 */
final class AbcBreakEvenDTO
{
    public function __construct(
        public readonly float $averageGrossMarginRate,
        public readonly ?int $dailySalesRequiredToCoverCosts,
        public readonly int $totalInvoicedRevenue,
        public readonly int $totalEstimatedProfit,
    ) {}

    public function hasEnoughDataForBreakEven(): bool
    {
        return $this->averageGrossMarginRate > 0 && $this->dailySalesRequiredToCoverCosts !== null;
    }

    public function toArray(): array
    {
        return [
            'average_gross_margin_rate' => $this->averageGrossMarginRate,
            'daily_sales_required_to_cover_costs' => $this->dailySalesRequiredToCoverCosts,
            'total_invoiced_revenue' => $this->totalInvoicedRevenue,
            'total_estimated_profit' => $this->totalEstimatedProfit,
        ];
    }
}
