<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Exceptions\InsufficientAccountBalanceException;
use App\Models\DailyCostDistribution;
use App\Models\MonthlyFixedCost;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Redistributes accumulated operating costs from the MERCHANDISE_SALES account
 * to the appropriate cost reserve accounts.
 *
 * This is a PURE ACCOUNT operation — it does not touch any caisse balance.
 * The total of all account balances remains unchanged.
 *
 * Distribution:
 *   For each active vehicle:
 *     - VEHICLE_DEPRECIATION  += depreciation_monthly / working_days_per_month
 *     - VEHICLE_INSURANCE     += insurance_monthly    / working_days_per_month
 *     - VEHICLE_REPAIR_RESERVE+= repair_reserve_monthly / working_days_per_month
 *     - VEHICLE_MAINTENANCE   += maintenance_monthly  / working_days_per_month
 *     - VEHICLE_FUEL          += estimated_daily_fuel_consumption
 *   For each active monthly fixed cost in the current period:
 *     - FIXED_COST account    += amount / working_days_in_month
 *
 * All of the above are DEBITED from MERCHANDISE_SALES.
 *
 * A DailyCostDistribution record is inserted to prevent double-distribution
 * for the same date — attempting to distribute twice for the same date throws.
 */
class DailyCostDistributionService
{
    public function __construct(private readonly AccountService $accountService) {}

    /**
     * Distribute daily costs for the given date.
     *
     * @throws \RuntimeException if costs have already been distributed for this date
     * @throws InsufficientAccountBalanceException if MERCHANDISE_SALES balance is insufficient
     */
    public function distributeForDate(Carbon $date): DailyCostDistribution
    {
        $distributionDateString = $date->toDateString();

        if (DailyCostDistribution::whereDate('distribution_date', $distributionDateString)->exists()) {
            throw new \RuntimeException(
                "Les coûts journaliers ont déjà été distribués pour le {$distributionDateString}."
            );
        }

        $merchandiseSalesAccount = $this->accountService->getMerchandiseSalesAccount();

        // Compute per-vehicle daily breakdowns.
        $vehicleCostBreakdowns = $this->computeVehicleDailyCostBreakdowns();

        // Compute fixed cost daily amounts for the current month.
        $fixedCostDailyAmounts = $this->computeFixedCostDailyAmounts($date);

        $totalToDistribute = collect($vehicleCostBreakdowns)->sum(
            fn (array $breakdown) => array_sum($breakdown['amounts'])
        ) + array_sum(array_column($fixedCostDailyAmounts, 'daily_amount'));

        if ($totalToDistribute <= 0) {
            throw new \RuntimeException(
                'Aucun coût à distribuer pour le '.$distributionDateString.'.'
            );
        }

        return DB::transaction(function () use (
            $distributionDateString,
            $merchandiseSalesAccount,
            $vehicleCostBreakdowns,
            $fixedCostDailyAmounts,
            $totalToDistribute,
        ): DailyCostDistribution {

            // Record the distribution to prevent duplicates.
            $distribution = DailyCostDistribution::create([
                'distribution_date' => $distributionDateString,
                'total_amount_distributed' => $totalToDistribute,
            ]);

            // Debit the full total from MERCHANDISE_SALES upfront.
            // If this throws InsufficientAccountBalanceException the transaction rolls back.
            $this->accountService->debit(
                account: $merchandiseSalesAccount,
                amount: $totalToDistribute,
                label: "Distribution coûts journaliers — {$distributionDateString}",
                referenceType: 'DAILY_DISTRIBUTION',
                referenceId: $distribution->id,
            );

            // Credit each vehicle's cost accounts.
            foreach ($vehicleCostBreakdowns as $breakdown) {
                /** @var Vehicle $vehicle */
                $vehicle = $breakdown['vehicle'];

                foreach ($breakdown['amounts'] as $accountType => $dailyAmount) {
                    if ($dailyAmount <= 0) {
                        continue;
                    }

                    $vehicleCostAccount = $this->accountService->getOrCreateVehicleAccount(
                        $vehicle,
                        AccountType::from($accountType),
                    );

                    $this->accountService->credit(
                        account: $vehicleCostAccount,
                        amount: $dailyAmount,
                        label: "Distribution journalière — {$vehicle->name} — {$distributionDateString}",
                        referenceType: 'DAILY_DISTRIBUTION',
                        referenceId: $distribution->id,
                    );
                }
            }

            // Credit each fixed cost account.
            foreach ($fixedCostDailyAmounts as $fixedCostEntry) {
                if ($fixedCostEntry['daily_amount'] <= 0) {
                    continue;
                }

                $fixedCostAccount = $this->accountService->getOrCreateFixedCostAccount($fixedCostEntry['label']);

                $this->accountService->credit(
                    account: $fixedCostAccount,
                    amount: $fixedCostEntry['daily_amount'],
                    label: "Distribution journalière — {$fixedCostEntry['label']} — {$distributionDateString}",
                    referenceType: 'DAILY_DISTRIBUTION',
                    referenceId: $distribution->id,
                );
            }

            return $distribution;
        });
    }

    /**
     * Returns whether daily costs have already been distributed for the given date.
     */
    public function hasAlreadyBeenDistributedForDate(Carbon $date): bool
    {
        return DailyCostDistribution::whereDate('distribution_date', $date->toDateString())->exists();
    }

    /**
     * Compute per-vehicle cost breakdowns for one working day.
     * Each entry contains the Vehicle model and a map of AccountType → daily XOF amount.
     *
     * @return array<int, array{vehicle: Vehicle, amounts: array<string, int>}>
     */
    private function computeVehicleDailyCostBreakdowns(): array
    {
        return Vehicle::all()->map(function (Vehicle $vehicle): array {
            $workingDays = max(1, $vehicle->working_days_per_month);

            return [
                'vehicle' => $vehicle,
                'amounts' => [
                    AccountType::VehicleDepreciation->value => (int) round($vehicle->depreciation_monthly / $workingDays),
                    AccountType::VehicleInsurance->value => (int) round($vehicle->insurance_monthly / $workingDays),
                    AccountType::VehicleRepairReserve->value => (int) round($vehicle->repair_reserve_monthly / $workingDays),
                    AccountType::VehicleMaintenance->value => (int) round($vehicle->maintenance_monthly / $workingDays),
                    AccountType::VehicleFuel->value => $vehicle->estimated_daily_fuel_consumption,
                ],
            ];
        })->all();
    }

    /**
     * Compute the daily allocation for all fixed costs active in the given month.
     * Uses 26 working days as the denominator when no specific count is available.
     *
     * @return array<int, array{label: string, sub_category: string, daily_amount: int}>
     */
    private function computeFixedCostDailyAmounts(Carbon $date): array
    {
        $defaultWorkingDaysPerMonth = 26;

        return MonthlyFixedCost::where('period_year', $date->year)
            ->where('period_month', $date->month)
            ->get()
            ->map(function (MonthlyFixedCost $fixedCost) use ($defaultWorkingDaysPerMonth): array {
                return [
                    'label' => $fixedCost->label ?? $fixedCost->sub_category->value,
                    'daily_amount' => (int) round($fixedCost->amount / $defaultWorkingDaysPerMonth),
                ];
            })->all();
    }
}
