<?php

namespace App\Services\Abc;

use App\Data\Abc\AbcBreakEvenDTO;
use App\Data\Abc\AbcDailyCostBreakdownDTO;
use App\Data\Abc\AbcMonthlyCostSummaryDTO;
use App\Models\Commercial;
use App\Models\MonthlyFixedCost;
use App\Models\SalesInvoice;
use App\Models\Vehicle;

/**
 * Computes the full monthly cost summary for a given period.
 *
 * All arithmetic lives here so the frontend receives pre-computed values
 * and can be tested independently of the UI.
 */
class CostAggregatesService
{
    private const FALLBACK_WORKING_DAYS_PER_MONTH = 26;

    public function computeForPeriod(int $year, int $month): AbcMonthlyCostSummaryDTO
    {
        $fixedCostsTotal = $this->computeFixedCostsTotalForPeriod($year, $month);
        $commercialSalariesTotal = $this->computeCommercialSalariesTotal();

        [$vehicleCostsTotal, $dailyVehicleCosts, $averageWorkingDaysPerMonth] = $this->computeVehicleAggregates();

        $dailyFixedCosts = $averageWorkingDaysPerMonth > 0
            ? (int) round($fixedCostsTotal / $averageWorkingDaysPerMonth)
            : 0;

        $dailyCommercialSalaries = $averageWorkingDaysPerMonth > 0
            ? (int) round($commercialSalariesTotal / $averageWorkingDaysPerMonth)
            : 0;

        $dailyBreakdown = new AbcDailyCostBreakdownDTO(
            dailyFixedCosts: $dailyFixedCosts,
            dailyCommercialSalaries: $dailyCommercialSalaries,
            dailyVehicleCosts: $dailyVehicleCosts,
            averageWorkingDaysPerMonth: $averageWorkingDaysPerMonth,
        );

        $breakEven = $this->computeBreakEven($dailyBreakdown->dailyTotalOverallCost());

        return new AbcMonthlyCostSummaryDTO(
            periodYear: $year,
            periodMonth: $month,
            fixedCostsTotal: $fixedCostsTotal,
            commercialSalariesTotal: $commercialSalariesTotal,
            vehicleCostsTotal: $vehicleCostsTotal,
            dailyBreakdown: $dailyBreakdown,
            breakEven: $breakEven,
        );
    }

    private function computeFixedCostsTotalForPeriod(int $year, int $month): int
    {
        return (int) MonthlyFixedCost::query()
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->sum('amount');
    }

    private function computeCommercialSalariesTotal(): int
    {
        return (int) Commercial::query()->sum('salary');
    }

    /**
     * Returns [vehicleCostsTotal, dailyVehicleCosts, averageWorkingDaysPerMonth].
     *
     * - vehicleCostsTotal: sum of each vehicle's total_monthly_fixed_cost
     * - dailyVehicleCosts: sum of each vehicle's daily_fixed_cost (each vehicle
     *   divides by its own working_days_per_month, so vehicles with more days
     *   are not penalised by a global average)
     * - averageWorkingDaysPerMonth: used to prorate fixed costs and salaries;
     *   falls back to FALLBACK_WORKING_DAYS_PER_MONTH when no vehicles exist
     *
     * @return array{int, int, int}
     */
    private function computeVehicleAggregates(): array
    {
        $vehicles = Vehicle::query()->get();

        if ($vehicles->isEmpty()) {
            return [0, 0, self::FALLBACK_WORKING_DAYS_PER_MONTH];
        }

        $vehicleCostsTotal = 0;
        $dailyVehicleCosts = 0;
        $totalWorkingDays = 0;

        foreach ($vehicles as $vehicle) {
            $vehicleCostsTotal += $vehicle->total_monthly_fixed_cost;
            $dailyVehicleCosts += $vehicle->daily_fixed_cost;
            $totalWorkingDays += $vehicle->working_days_per_month;
        }

        $averageWorkingDaysPerMonth = (int) round($totalWorkingDays / $vehicles->count());

        return [$vehicleCostsTotal, $dailyVehicleCosts, $averageWorkingDaysPerMonth];
    }

    private function computeBreakEven(int $dailyTotalOverallCost): AbcBreakEvenDTO
    {
        $totalInvoicedRevenue = (int) SalesInvoice::query()->sum('total_amount');
        $totalEstimatedProfit = (int) SalesInvoice::query()->sum('total_estimated_profit');

        if ($totalInvoicedRevenue === 0) {
            return new AbcBreakEvenDTO(
                averageGrossMarginRate: 0.0,
                dailySalesRequiredToCoverCosts: null,
                totalInvoicedRevenue: 0,
                totalEstimatedProfit: $totalEstimatedProfit,
            );
        }

        $averageGrossMarginRate = round($totalEstimatedProfit / $totalInvoicedRevenue, 4);

        $dailySalesRequiredToCoverCosts = $averageGrossMarginRate > 0
            ? (int) round($dailyTotalOverallCost / $averageGrossMarginRate)
            : null;

        return new AbcBreakEvenDTO(
            averageGrossMarginRate: $averageGrossMarginRate,
            dailySalesRequiredToCoverCosts: $dailySalesRequiredToCoverCosts,
            totalInvoicedRevenue: $totalInvoicedRevenue,
            totalEstimatedProfit: $totalEstimatedProfit,
        );
    }
}
