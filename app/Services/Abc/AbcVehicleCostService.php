<?php

namespace App\Services\Abc;

use App\Models\CarLoad;
use App\Models\Vehicle;

/**
 * Computes vehicle-related running costs for a CarLoad.
 *
 * Fixed costs (insurance, maintenance, repair reserve, depreciation, driver salary)
 * are derived from the Vehicle's monthly configuration and prorated over trip duration.
 *
 * Fuel costs are entered as actual receipts via CarLoadFuelEntry and summed directly.
 */
class AbcVehicleCostService
{
    /**
     * Daily fixed cost for a vehicle, excluding fuel.
     * Fuel is tracked per trip via CarLoadFuelEntry.
     */
    public function computeDailyFixedCost(Vehicle $vehicle): int
    {
        if ($vehicle->working_days_per_month === 0) {
            return 0;
        }

        $totalMonthlyFixedCost = $vehicle->insurance_monthly
            + $vehicle->maintenance_monthly
            + $vehicle->repair_reserve_monthly
            + $vehicle->depreciation_monthly
            + $vehicle->driver_salary_monthly;

        return (int) round($totalMonthlyFixedCost / $vehicle->working_days_per_month);
    }

    /**
     * Total fixed cost allocated to a CarLoad based on trip duration.
     * Uses load_date → return_date (or today if still active) to determine days.
     *
     * Prefers the stored fixed_daily_cost snapshot on the CarLoad (frozen at vehicle assignment)
     * over a live computation, so historical trip costs remain accurate even if the vehicle's
     * monthly rates are updated later.
     */
    public function computeFixedCostForCarLoad(CarLoad $carLoad): int
    {
        if ($carLoad->vehicle_id === null) {
            return 0;
        }

        $dailyRate = $carLoad->fixed_daily_cost;

        if ($dailyRate === null) {
            $vehicle = $carLoad->vehicle;

            if ($vehicle === null) {
                return 0;
            }

            $dailyRate = $this->computeDailyFixedCost($vehicle);
        }

        return $dailyRate * $this->computeTripDurationDays($carLoad);
    }

    /**
     * Total actual fuel cost entered for a CarLoad via fuel receipts.
     */
    public function computeFuelCostForCarLoad(CarLoad $carLoad): int
    {
        return (int) $carLoad->fuelEntries()->sum('amount');
    }

    /**
     * Total vehicle running cost for a CarLoad: fixed (prorated) + fuel (actual).
     */
    public function computeTotalVehicleCostForCarLoad(CarLoad $carLoad): int
    {
        return $this->computeFixedCostForCarLoad($carLoad)
            + $this->computeFuelCostForCarLoad($carLoad);
    }

    /**
     * Number of days the CarLoad was active.
     * Uses return_date if available, otherwise today.
     * Minimum of 1 day to avoid zero-cost trips.
     */
    private function computeTripDurationDays(CarLoad $carLoad): int
    {
        $startDate = $carLoad->load_date ?? now();
        $endDate = $carLoad->return_date ?? now();

        $durationDays = (int) $startDate->diffInDays($endDate);

        return max(1, $durationDays);
    }
}
