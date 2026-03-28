<?php

namespace App\Services\Abc;

use App\Enums\AccountType;
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
     * Maps vehicle field names to their corresponding AccountType.
     * This is the single source of truth for both cost calculation and account mapping.
     * Any change here (add/remove a cost component) is automatically reflected in CaisseService.
     *
     * @var array<string, AccountType>
     */
    const VARIABLES_USED_IN_COMPUTE_DAILY_FIXED_COST_FOR_VEHICLE = [
        'insurance_monthly' => AccountType::VehicleInsurance,
        'maintenance_monthly' => AccountType::VehicleMaintenance,
        'depreciation_monthly' => AccountType::VehicleDepreciation,
        'repair_reserve_monthly' => AccountType::VehicleRepairReserve,
        'driver_salary_monthly' => AccountType::VehicleDriverSalary,
    ];

    /**
     * Daily fixed cost for a vehicle, excluding fuel.
     * Variable expenses are tracked per trip via CarLoadExpense.
     */
    public function computeDailyRunningCostForVehicle(Vehicle $vehicle): int
    {
        if ($vehicle->working_days_per_month === 0) {
            return 0;
        }

        $totalMonthlyFixedCost = 0;
        foreach (array_keys(self::VARIABLES_USED_IN_COMPUTE_DAILY_FIXED_COST_FOR_VEHICLE) as $fieldName) {
            $totalMonthlyFixedCost += $vehicle->{$fieldName} ?? 0;
        }

        return (int) round($totalMonthlyFixedCost / $vehicle->working_days_per_month);
    }

    /**
     * Daily cost breakdown for a vehicle, one entry per cost component.
     *
     * Returns an indexed array of [AccountType, dailyAmount] pairs — one for each non-fuel
     * component in VARIABLES_USED_IN_COMPUTE_DAILY_FIXED_COST_FOR_VEHICLE, plus a final
     * fuel entry. The fuel entry absorbs any integer-rounding surplus or deficit so that
     * SUM(all dailyAmounts) == vehicle->daily_fixed_cost exactly.
     *
     * Using this method in CaisseService ensures that adding or removing a cost component
     * from the constant above automatically updates the accounting distribution with no
     * changes required elsewhere.
     *
     * @return array<int, array{0: AccountType, 1: int}>
     */
    public function computeDailyFixedCostBreakdownForVehicle(Vehicle $vehicle): array
    {
        $workingDaysPerMonth = max(1, $vehicle->working_days_per_month);

        $nonFuelEntries = [];
        $sumOfNonFuelDailyCosts = 0;

        foreach (self::VARIABLES_USED_IN_COMPUTE_DAILY_FIXED_COST_FOR_VEHICLE as $fieldName => $accountType) {
            $dailyAmount = (int) round(($vehicle->{$fieldName} ?? 0) / $workingDaysPerMonth);
            $nonFuelEntries[] = [$accountType, $dailyAmount];
            $sumOfNonFuelDailyCosts += $dailyAmount;
        }

        $totalDailyVehicleCost = $vehicle->daily_fixed_cost ?? 0;
        $dailyFuelCost = max(0, $totalDailyVehicleCost - $sumOfNonFuelDailyCosts);

        return array_merge($nonFuelEntries, [[AccountType::VehicleFuel, $dailyFuelCost]]);
    }

    /**
     * Total fixed cost allocated to a CarLoad based on trip duration.
     * Uses load_date → return_date (or today if still active) to determine days.
     *
     * Prefers the stored fixed_daily_cost snapshot on the CarLoad (frozen at vehicle assignment)
     * over a live computation, so historical trip costs remain accurate even if the vehicle's
     * monthly rates are updated later.
     */
    public function computeOverallVehicleRunningCostForCarLoad(CarLoad $carLoad): int
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

            $dailyRate = $this->computeDailyRunningCostForVehicle($vehicle);
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
    public function computeTotalFixedAndVariableVehicleCostForCarLoad(CarLoad $carLoad): int
    {
        return $this->computeOverallVehicleRunningCostForCarLoad($carLoad)
            + $this->computeVariableExpensesForCarLoad($carLoad);
    }

    /**
     * Daily total vehicle cost for a CarLoad: (fixed prorated + fuel actual) ÷ trip duration.
     * Minimum of 1 day to avoid division by zero.
     */
    public function computeDailyFixedAndVariableVehicleCostForCarLoad(CarLoad $carLoad): int
    {
        $tripDurationDays = $this->computeTripDurationDays($carLoad);

        return (int) round($this->computeTotalFixedAndVariableVehicleCostForCarLoad($carLoad) / $tripDurationDays);
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
