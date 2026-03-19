<?php

namespace App\Data\Abc;

/**
 * Full profitability picture for a single CarLoad after deducting all ABC costs.
 *
 * Gross profit = SUM(ventes.profit) — product margin only (COGS-based).
 * Net profit   = gross profit − vehicle cost − storage allocation − overhead allocation.
 */
final class CarLoadProfitabilityDTO
{
    public function __construct(
        public readonly int $totalRevenue,
        public readonly int $totalGrossProfit,
        public readonly int $vehicleFixedCost,
        public readonly int $vehicleExpensesCost,
        public readonly int $storageAllocation,
        public readonly int $overheadAllocation,
        public readonly bool $isMonthFinalized,
    ) {}

    public function totalVehicleCost(): int
    {
        return $this->vehicleFixedCost + $this->vehicleExpensesCost;
    }

    public function totalFixedCostBurden(): int
    {
        return $this->totalVehicleCost() + $this->storageAllocation + $this->overheadAllocation;
    }

    public function netProfit(): int
    {
        return $this->totalGrossProfit - $this->totalFixedCostBurden();
    }

    public function grossMarginPercent(): float
    {
        if ($this->totalRevenue === 0) {
            return 0.0;
        }

        return round($this->totalGrossProfit / $this->totalRevenue * 100, 1);
    }

    public function netMarginPercent(): float
    {
        if ($this->totalRevenue === 0) {
            return 0.0;
        }

        return round($this->netProfit() / $this->totalRevenue * 100, 1);
    }

    /**
     * Minimum revenue needed to cover all fixed costs given the current gross margin rate.
     * Returns 0 if gross margin rate is zero (impossible to break even by selling more).
     */
    public function breakEvenRevenue(): int
    {
        if ($this->totalRevenue === 0 || $this->totalGrossProfit === 0) {
            return 0;
        }

        $grossMarginRate = $this->totalGrossProfit / $this->totalRevenue;

        if ($grossMarginRate <= 0) {
            return 0;
        }

        return (int) ceil($this->totalFixedCostBurden() / $grossMarginRate);
    }

    /**
     * Remaining revenue needed to reach break-even. Zero when already profitable.
     */
    public function remainingRevenueToBreakEven(): int
    {
        return max(0, $this->breakEvenRevenue() - $this->totalRevenue);
    }

    public function isDeficit(): bool
    {
        return $this->netProfit() < 0;
    }
}
