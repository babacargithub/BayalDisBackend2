<?php

namespace App\Data\Abc;

/**
 * Daily cost breakdown across all business cost categories.
 *
 * Vehicle daily costs are the sum of each vehicle's own daily rate
 * (each vehicle is divided by its own working_days_per_month).
 *
 * Fixed costs and commercial salaries are divided by the average
 * working days per month across all active vehicles (fallback: 26).
 */
final class AbcDailyCostBreakdownDTO
{
    public function __construct(
        public readonly int $dailyFixedCosts,
        public readonly int $dailyCommercialSalaries,
        public readonly int $dailyVehicleCosts,
        public readonly int $averageWorkingDaysPerMonth,
    ) {}

    public function dailyTotalOverallCost(): int
    {
        return $this->dailyFixedCosts + $this->dailyCommercialSalaries + $this->dailyVehicleCosts;
    }

    public function toArray(): array
    {
        return [
            'daily_fixed_costs' => $this->dailyFixedCosts,
            'daily_commercial_salaries' => $this->dailyCommercialSalaries,
            'daily_vehicle_costs' => $this->dailyVehicleCosts,
            'daily_total_overall_cost' => $this->dailyTotalOverallCost(),
            'average_working_days_per_month' => $this->averageWorkingDaysPerMonth,
        ];
    }
}
