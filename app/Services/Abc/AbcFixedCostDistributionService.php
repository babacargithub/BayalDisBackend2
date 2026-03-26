<?php

namespace App\Services\Abc;

use App\Data\Abc\CarLoadFixedCostAllocationDTO;
use App\Enums\MonthlyFixedCostPool;
use App\Models\CarLoad;
use App\Models\MonthlyFixedCost;

/**
 * Distributes monthly fixed costs (storage + overhead) equally across
 * all active vehicles in a given month.
 *
 * Distribution rule:
 *   per_vehicle_amount = total_pool_cost / number_of_active_vehicles
 *
 * "Active vehicle" = a vehicle that had at least one CarLoad with a load_date
 * in the target month.
 *
 * If a vehicle ran multiple CarLoads in the same month, each CarLoad receives
 * an equal share of that vehicle's allocation:
 *   per_carload_amount = per_vehicle_amount / carload_count_for_vehicle_this_month
 *
 * The effective_rate field is intentionally NOT used here — distribution is
 * equal per vehicle regardless of revenue.
 */
class AbcFixedCostDistributionService
{
    /**
     * Finalize a month: compute and store per_vehicle_amount for all cost entries.
     *
     * After finalization, the amounts are frozen — adding new CarLoads or vehicles
     * later will NOT change the stored per_vehicle_amount for that month.
     *
     * Only entries with finalized_at = null are processed.
     */
    public function finalizeMonth(int $year, int $month): void
    {
        $activeVehicleCount = $this->countActiveVehiclesInMonth($year, $month);

        if ($activeVehicleCount === 0) {
            return;
        }

        MonthlyFixedCost::query()
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->whereNull('finalized_at')
            ->each(function (MonthlyFixedCost $monthlyCost) use ($activeVehicleCount): void {
                $monthlyCost->update([
                    'per_vehicle_amount' => (int) round($monthlyCost->amount / $activeVehicleCount),
                    'active_vehicle_count' => $activeVehicleCount,
                    'finalized_at' => now(),
                ]);
            });
    }

    /**
     * Compute the allocated fixed cost (storage + overhead) for one CarLoad.
     *
     * If the month has been finalized, uses the stored per_vehicle_amount (frozen).
     * If the month is still open, falls back to the previous month's per_vehicle_amount
     * as an estimate — clearly flagged via the isMonthFinalized flag on the DTO.
     */
    public function computeAllocatedFixedCostsForCarLoad(CarLoad $carLoad): CarLoadFixedCostAllocationDTO
    {
        if ($carLoad->load_date === null) {
            return CarLoadFixedCostAllocationDTO::zero();
        }

        $year = $carLoad->load_date->year;
        $month = $carLoad->load_date->month;

        $vehicleCarLoadCountThisMonth = $this->countCarLoadsForVehicleInMonth(
            vehicleId: $carLoad->vehicle_id,
            year: $year,
            month: $month,
        );

        $storageAllocation = $this->computePoolAllocationForCarLoad(
            costPool: MonthlyFixedCostPool::Storage,
            year: $year,
            month: $month,
            vehicleCarLoadCountThisMonth: $vehicleCarLoadCountThisMonth,
        );

        $overheadAllocation = $this->computePoolAllocationForCarLoad(
            costPool: MonthlyFixedCostPool::Overhead,
            year: $year,
            month: $month,
            vehicleCarLoadCountThisMonth: $vehicleCarLoadCountThisMonth,
        );

        return new CarLoadFixedCostAllocationDTO(
            storageAllocation: $storageAllocation,
            overheadAllocation: $overheadAllocation,
        );
    }

    /**
     * Whether the monthly fixed costs for a given year/month have been finalized.
     */
    public function isMonthFinalized(int $year, int $month): bool
    {
        return MonthlyFixedCost::query()
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->whereNotNull('finalized_at')
            ->exists();
    }

    /**
     * Compute the allocation for one cost pool for one CarLoad.
     *
     * Priority:
     *   1. Current month's finalized per_vehicle_amount (when the month has been finalized).
     *   2. Most recent past month that has finalized per_vehicle_amount (fallback estimate).
     */
    private function computePoolAllocationForCarLoad(
        MonthlyFixedCostPool $costPool,
        int $year,
        int $month,
        int $vehicleCarLoadCountThisMonth,
    ): int {
        $perVehicleTotal = $this->getPerVehicleTotalForPool($costPool, $year, $month);

        if ($perVehicleTotal === 0) {
            $perVehicleTotal = $this->getFallbackPerVehicleTotalFromLatestFinalizedMonth($costPool, $year, $month);
        }

        if ($vehicleCarLoadCountThisMonth === 0) {
            return 0;
        }

        return (int) round($perVehicleTotal / $vehicleCarLoadCountThisMonth);
    }

    /**
     * Sum of per_vehicle_amount for all finalized entries in a given pool + period.
     */
    private function getPerVehicleTotalForPool(
        MonthlyFixedCostPool $costPool,
        int $year,
        int $month,
    ): int {
        return (int) MonthlyFixedCost::query()
            ->where('cost_pool', $costPool)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->whereNotNull('per_vehicle_amount')
            ->sum('per_vehicle_amount');
    }

    /**
     * Fallback: find the most recent past month (before the requested year/month)
     * that has finalized per_vehicle_amount data for the given pool, and use its total.
     * This covers gaps when the current month has not yet been finalized.
     */
    private function getFallbackPerVehicleTotalFromLatestFinalizedMonth(
        MonthlyFixedCostPool $costPool,
        int $year,
        int $month,
    ): int {
        $latestFinalizedEntry = MonthlyFixedCost::query()
            ->where('cost_pool', $costPool)
            ->whereNotNull('per_vehicle_amount')
            ->where(function ($query) use ($year, $month): void {
                $query->where('period_year', '<', $year)
                    ->orWhere(function ($query) use ($year, $month): void {
                        $query->where('period_year', $year)
                            ->where('period_month', '<', $month);
                    });
            })
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->first(['period_year', 'period_month']);

        if ($latestFinalizedEntry === null) {
            return 0;
        }

        return (int) MonthlyFixedCost::query()
            ->where('cost_pool', $costPool)
            ->where('period_year', $latestFinalizedEntry->period_year)
            ->where('period_month', $latestFinalizedEntry->period_month)
            ->whereNotNull('per_vehicle_amount')
            ->sum('per_vehicle_amount');
    }

    /**
     * Count how many distinct vehicles had at least one CarLoad in the given month.
     */
    private function countActiveVehiclesInMonth(int $year, int $month): int
    {
        return CarLoad::query()
            ->whereNotNull('vehicle_id')
            ->whereYear('load_date', $year)
            ->whereMonth('load_date', $month)
            ->distinct('vehicle_id')
            ->count('vehicle_id');
    }

    /**
     * Count how many CarLoads a specific vehicle ran in a given month.
     */
    private function countCarLoadsForVehicleInMonth(
        ?int $vehicleId,
        int $year,
        int $month,
    ): int {
        if ($vehicleId === null) {
            return 0;
        }

        return CarLoad::query()
            ->where('vehicle_id', $vehicleId)
            ->whereYear('load_date', $year)
            ->whereMonth('load_date', $month)
            ->count();
    }
}
