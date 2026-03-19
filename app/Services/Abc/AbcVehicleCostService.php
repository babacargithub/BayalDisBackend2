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
 * Variable expenses (fuel, parking, fines, washing, etc.) are entered as actual
 * receipts via CarLoadExpense and summed directly.
 */
class AbcVehicleCostService
{
    /**
     * Daily fixed cost for a vehicle, excluding fuel.
     * Variable expenses are tracked per trip via CarLoadExpense.
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
     * Total actual variable expenses entered for a CarLoad (fuel, parking, fines, etc.).
     */
    public function computeVariableExpensesForCarLoad(CarLoad $carLoad): int
    {
        return (int) $carLoad->expenses()->sum('amount');
    }

    /**
     * Total vehicle running cost for a CarLoad: fixed (prorated) + variable expenses (actual).
     */
    public function computeTotalVehicleCostForCarLoad(CarLoad $carLoad): int
    {
        return $this->computeFixedCostForCarLoad($carLoad)
            + $this->computeVariableExpensesForCarLoad($carLoad);
    }

    /**
     * Daily total vehicle cost for a CarLoad: (fixed prorated + fuel actual) ÷ trip duration.
     * Minimum of 1 day to avoid division by zero.
     */
    public function computeDailyTotalCostForCarLoad(CarLoad $carLoad): int
    {
        $tripDurationDays = $this->computeTripDurationDays($carLoad);

        return (int) round($this->computeTotalVehicleCostForCarLoad($carLoad) / $tripDurationDays);
    }

    /**
     * Number of days the CarLoad has been active so far.
     *
     * For completed trips (return_date already passed), uses return_date as the end.
     * For active trips whose planned return_date is still in the future, caps at today
     * to avoid inflating costs based on unelapsed future days.
     *
     * Minimum of 1 day to avoid zero-cost trips.
     */
    private function computeTripDurationDays(CarLoad $carLoad): int
    {
        $startDate = $carLoad->load_date ?? now();

        $plannedReturnDate = $carLoad->return_date;
        $endDate = ($plannedReturnDate !== null && $plannedReturnDate->isPast())
            ? $plannedReturnDate
            : now();

        $durationDays = (int) $startDate->diffInDays($endDate);

        return max(1, $durationDays);
    }
}
