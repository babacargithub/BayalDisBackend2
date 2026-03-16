<?php

namespace App\Data\Abc;

/**
 * Full monthly cost summary for a given period.
 *
 * Monthly totals:
 *  - fixedCostsTotal          — sum of MonthlyFixedCost entries for the period
 *  - commercialSalariesTotal  — sum of all Commercial.salary (current values)
 *  - vehicleCostsTotal        — sum of all Vehicle.total_monthly_fixed_cost (current values)
 *  - grandTotal()             — sum of the three monthly totals above
 *
 * Daily breakdown and break-even are nested DTOs.
 */
final class AbcMonthlyCostSummaryDTO
{
    public function __construct(
        public readonly int $periodYear,
        public readonly int $periodMonth,
        public readonly int $fixedCostsTotal,
        public readonly int $commercialSalariesTotal,
        public readonly int $vehicleCostsTotal,
        public readonly AbcDailyCostBreakdownDTO $dailyBreakdown,
        public readonly AbcBreakEvenDTO $breakEven,
    ) {}

    public function grandTotal(): int
    {
        return $this->fixedCostsTotal + $this->commercialSalariesTotal + $this->vehicleCostsTotal;
    }

    public function toArray(): array
    {
        return [
            'period_year' => $this->periodYear,
            'period_month' => $this->periodMonth,
            'fixed_costs_total' => $this->fixedCostsTotal,
            'commercial_salaries_total' => $this->commercialSalariesTotal,
            'vehicle_costs_total' => $this->vehicleCostsTotal,
            'grand_total' => $this->grandTotal(),
            'daily_breakdown' => $this->dailyBreakdown->toArray(),
            'break_even' => $this->breakEven->toArray(),
        ];
    }
}
