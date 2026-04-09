<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\CarLoadStatus;
use App\Enums\MonthlyFixedCostPool;
use App\Exceptions\DayAlreadyClosedException;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CarLoad;
use App\Models\Commercial;
use App\Models\CommercialWorkPeriod;
use App\Models\DailyCommission;
use App\Models\MonthlyFixedCost;
use App\Models\Payment;
use App\Services\Abc\VehicleCostCalculatorService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Handles the "Clôturer Journée" (end-of-day closing) operation for a commercial caisse.
 *
 * This operation:
 *   1. Guards against double-closing (idempotent check on locked_until).
 *   2. Registers today's collected payments into the MERCHANDISE_SALES account by
 *      transferring the caisse balance from COMMERCIAL_COLLECTED → MERCHANDISE_SALES.
 *   3. Splits the earned net commission out of MERCHANDISE_SALES into
 *      COMMERCIAL_COMMISSION (MERCHANDISE_SALES → COMMERCIAL_COMMISSION).
 *   4. Marks today's DailyCommission as finalized (sets finalized_at).
 *   5. Distributes the vehicle's daily operating costs (depreciation, insurance,
 *      repair reserve, maintenance, fuel) from MERCHANDISE_SALES → vehicle accounts.
 *      Skipped if the commercial has no active car load or no vehicle. Idempotent
 *      per vehicle per day (shared-team guard).
 *   6. Distributes the car load's daily share of company fixed costs (storage + overhead)
 *      from MERCHANDISE_SALES → pool fixed cost accounts. Each car load participates
 *      proportionally: per_carload_daily = pool_total / active_vehicles / carloads_per_vehicle / working_days.
 *      Idempotent per car load per day.
 *   7. Locks the commercial's caisse for the rest of the day by setting
 *      locked_until = $date (end of the requested work day). No new payments
 *      are accepted until the next day.
 *
 * Financial invariant: SUM(account.balance) == SUM(caisse.balance) is preserved
 * because all transfers are pure account reallocations (debit ↔ credit pairs).
 *
 * Impact on VersementService: since COMMERCIAL_COLLECTED is drained to zero by step 2,
 * VersementService at versement time only performs the physical caisse sweep and skips
 * the account settlement (which was already done here). Finalized DailyCommissions are
 * also excluded from the commissionToCredit sum to prevent double-credit.
 */
readonly class CaisseService
{
    public function __construct(
        private AccountService $accountService,
        private VehicleCostCalculatorService $vehicleCostService,
    ) {}

    /**
     * Close the day for the given commercial.
     *
     * @param  Carbon  $date  The work day to close — typically today.
     *
     * @throws DayAlreadyClosedException if the caisse has already been locked for this date
     * @throws RuntimeException if the commercial has no caisse or no MERCHANDISE_SALES account
     * @throws Throwable
     */
    public function closeCaisseForDay(Commercial $commercial, Carbon $date): void
    {
        $caisse = $commercial->caisse;

        if ($caisse === null) {
            throw new RuntimeException(
                "Le commercial «{$commercial->name}» n'a pas de caisse configurée."
            );
        }

        // Idempotency guard — prevent double-closing the same day.
        $caisse->refresh();
        if ($caisse->locked_until !== null && $caisse->locked_until->isSameDay($date)) {
            throw new DayAlreadyClosedException(
                "La caisse de «{$commercial->name}» a déjà été clôturée pour le {$date->toDateString()}."
            );
        }

        DB::transaction(function () use ($commercial, $caisse, $date): void {
            $dateString = $date->toDateString();

            // Find today's DailyCommission for this commercial (if any, and not yet finalized).
            $todayDailyCommission = $this->findTodaysDailyCommission($commercial, $date);

            // Re-read the caisse balance inside the transaction to prevent a race condition
            // where a payment arrives between the idempotency check above and this transfer.
            $caisse->refresh();
            $caisseBalance = $caisse->balance;

            // ── Step 1: Register today's collected payments into MERCHANDISE_SALES ─────
            // Transfer the full caisse balance: COMMERCIAL_COLLECTED → MERCHANDISE_SALES.
            // This records that the collected cash is now accounted for as merchandise revenue.
            $merchandiseSalesAccount = null;
            if ($caisseBalance > 0) {
                $collectedAccount = $this->accountService->getOrCreateCommercialCollectedAccount($commercial);
                $merchandiseSalesAccount = $this->accountService->getMerchandiseSalesAccount();

                $this->accountService->transferBetweenAccounts(
                    fromAccount: $collectedAccount,
                    toAccount: $merchandiseSalesAccount,
                    amount: $caisseBalance,
                    label: "Clôture journée {$dateString} — encaissements {$commercial->name}",
                    referenceType: 'CLOSE_DAY',
                    referenceId: $todayDailyCommission?->id,
                );
            }

            // ── Step 2: Split out earned commission from MERCHANDISE_SALES ────────────
            // Transfer net_commission: MERCHANDISE_SALES → COMMERCIAL_COMMISSION.
            // Now that MERCHANDISE_SALES holds today's revenue (from step 1), this
            // split is always funded by today's actual collections.
            //
            // Safety cap: if net_commission exceeds the current MERCHANDISE_SALES balance
            // (possible on credit-heavy days where invoices were issued but cash not yet
            // collected), only transfer what is available. The remainder will be credited
            // on the next versement once more cash is collected.
            $commissionTransferredAmount = 0;
            if ($todayDailyCommission !== null && $todayDailyCommission->net_commission > 0) {
                $merchandiseSalesAccount ??= $this->accountService->getMerchandiseSalesAccount();
                $commissionAccount = $this->accountService->getOrCreateCommercialCommissionAccount($commercial);

                $merchandiseSalesAccount->refresh();
                $transferableCommissionAmount = min(
                    $todayDailyCommission->net_commission,
                    $merchandiseSalesAccount->balance,
                );

                if ($transferableCommissionAmount > 0) {
                    $this->accountService->transferBetweenAccounts(
                        fromAccount: $merchandiseSalesAccount,
                        toAccount: $commissionAccount,
                        amount: $transferableCommissionAmount,
                        label: "Commission journée {$dateString} — {$commercial->name}",
                        referenceType: 'CLOSE_DAY',
                        referenceId: $todayDailyCommission->id,
                    );
                    $commissionTransferredAmount = $transferableCommissionAmount;
                }

                // Mark as finalized so VersementService does not double-credit it later.
                $todayDailyCommission->update(['finalized_at' => now()]);
            }

            // Look up active car load once — shared by steps 3 and 4.
            $activeCarLoad = $this->findActiveCarLoad($commercial);

            // ── Step 3: Distribute vehicle daily operating costs ──────────────────────
            // Finds the vehicle from the commercial's currently active car load, then
            // transfers each daily cost amount from MERCHANDISE_SALES to the vehicle's
            // dedicated cost accounts.
            $vehicleCostsDistributed = $this->distributeVehicleDailyCostsIfApplicable($activeCarLoad, $date, $merchandiseSalesAccount);

            // ── Step 4: Distribute car load's daily share of company fixed costs ──────
            // Uses AbcFixedCostDistributionService to determine this car load's
            // proportional share of fixed costs (storage + overhead pools), then
            // transfers the daily portion from MERCHANDISE_SALES → pool accounts.
            $fixedCostsDistributed = $this->distributeCarLoadFixedCostsDailyShareIfApplicable($activeCarLoad, $date, $merchandiseSalesAccount);

            // ── Step 5: Record net daily profit ───────────────────────────────────────
            // Net profit = gross daily profit (realized payment profits) − commission − vehicle costs − fixed costs.
            // This follows the same formula as StatisticsService: totalRealizedProfit − totalCommissions − totalCosts.
            // The positive remainder is transferred from MERCHANDISE_SALES → PROFIT.
            // When costs exceed gross profit (net profit ≤ 0), this step is skipped.
            $grossDailyProfit = $this->computeGrossDailyProfit($commercial, $date);
            $netDailyProfit = $grossDailyProfit - $commissionTransferredAmount - $vehicleCostsDistributed - $fixedCostsDistributed;

            if ($netDailyProfit > 0) {
                $merchandiseSalesAccount ??= $this->accountService->getMerchandiseSalesAccount();
                $profitAccount = $this->accountService->getOrCreateProfitAccount();

                $this->accountService->transferBetweenAccounts(
                    fromAccount: $merchandiseSalesAccount,
                    toAccount: $profitAccount,
                    amount: $netDailyProfit,
                    label: "Bénéfice net journée {$dateString} — {$commercial->name}",
                    referenceType: 'CLOSE_DAY_PROFIT',
                    referenceId: $todayDailyCommission?->id,
                );
            }

            // ── Invariant guard ────────────────────────────────────────────────────────
            $this->accountService->assertGlobalInvariantHolds();

            // ── Step 6: Lock the caisse ────────────────────────────────────────────────
            $caisse->update(['locked_until' => $date->copy()->setTime(23, 59, 59, 999999)]);
        });
    }

    /**
     * Distribute the vehicle's daily operating costs from MERCHANDISE_SALES to the
     * corresponding vehicle cost accounts (depreciation, insurance, repair reserve,
     * maintenance, fuel).
     *
     * Silently skips when:
     *   - there is no active car load
     *   - the active car load has no vehicle attached
     *   - the vehicle's costs have already been distributed for this date (idempotent)
     */
    /**
     * @return int The total vehicle operating cost distributed (0 if skipped).
     */
    private function distributeVehicleDailyCostsIfApplicable(
        ?CarLoad $activeCarLoad,
        Carbon $date,
        ?Account $merchandiseSalesAccount,
    ): int {
        $activeVehicle = $activeCarLoad?->vehicle;

        if ($activeVehicle === null) {
            return 0;
        }

        // Idempotency guard: skip if this vehicle's costs were already distributed today
        // (e.g., another commercial on the same team already closed their day).
        if (AccountTransaction::query()
            ->where('reference_type', 'CLOSE_DAY_VEHICLE')
            ->where('reference_id', $activeVehicle->id)
            ->whereDate('created_at', $date->toDateString())
            ->exists()) {
            return 0;
        }

        /** @var array<int, array{0: AccountType, 1: int}> $allCostEntries */
        $allCostEntries = $this->vehicleCostService->computeCostBreakdownPerDayForVehicle($activeVehicle);
        $totalVehicleCost = (int) array_sum(array_column($allCostEntries, 1));

        if ($totalVehicleCost <= 0) {
            return 0;
        }

        $merchandiseSalesAccount ??= $this->accountService->getMerchandiseSalesAccount();
        $dateString = $date->toDateString();

        // Debit MERCHANDISE_SALES for the full vehicle cost total.
        $this->accountService->debit(
            account: $merchandiseSalesAccount,
            amount: $totalVehicleCost,
            label: "Coûts véhicule journée {$dateString} — {$activeVehicle->name}",
            referenceType: 'CLOSE_DAY_VEHICLE',
            referenceId: $activeVehicle->id,
        );

        // Credit each vehicle cost account with its daily share.
        foreach ($allCostEntries as [$accountType, $dailyAmount]) {
            if ($dailyAmount <= 0) {
                continue;
            }

            $vehicleCostAccount = $this->accountService->getOrCreateVehicleAccount(
                $activeVehicle,
                $accountType,
            );

            $this->accountService->credit(
                account: $vehicleCostAccount,
                amount: $dailyAmount,
                label: "Coûts journaliers — {$activeVehicle->name} — {$dateString}",
                referenceType: 'CLOSE_DAY_VEHICLE',
                referenceId: $activeVehicle->id,
            );
        }

        return $totalVehicleCost;
    }

    /**
     * Distribute this car load's daily share of company fixed costs
     * (storage + overhead pools) from MERCHANDISE_SALES to pool accounts.
     *
     * Each car load participates proportionally:
     *   per_carload_monthly = pool_total / active_vehicles / carloads_per_vehicle
     *   per_carload_daily   = per_carload_monthly / working_days_per_month
     *
     * Silently skips when:
     *   - there is no active car load
     *   - the allocated monthly amounts are both zero
     *   - this car load's fixed costs have already been distributed today (idempotent per car load)
     *
     * @return int The total fixed cost distributed (0 if skipped).
     */
    private function distributeCarLoadFixedCostsDailyShareIfApplicable(
        ?CarLoad $activeCarLoad,
        Carbon $date,
        ?Account $merchandiseSalesAccount,
    ): int {
        if ($activeCarLoad === null) {
            return 0;
        }

        // Idempotency guard: skip if this car load's fixed costs were already distributed today.
        if (AccountTransaction::query()
            ->where('reference_type', 'CLOSE_DAY_FIXED_COST')
            ->where('reference_id', $activeCarLoad->id)
            ->whereDate('created_at', $date->toDateString())
            ->exists()) {
            return 0;
        }

        $defaultWorkingDaysPerMonth = 26;
        $dailyStorageAmount = (int) round(
            $this->computeCarLoadPoolMonthlyAllocation($activeCarLoad, MonthlyFixedCostPool::Storage, $date) / $defaultWorkingDaysPerMonth
        );
        $dailyOverheadAmount = (int) round(
            $this->computeCarLoadPoolMonthlyAllocation($activeCarLoad, MonthlyFixedCostPool::Overhead, $date) / $defaultWorkingDaysPerMonth
        );

        $totalDailyFixedCost = $dailyStorageAmount + $dailyOverheadAmount;

        if ($totalDailyFixedCost <= 0) {
            return 0;
        }

        $merchandiseSalesAccount ??= $this->accountService->getMerchandiseSalesAccount();
        $dateString = $date->toDateString();
        $carLoadName = $activeCarLoad->name ?? "chargement #{$activeCarLoad->id}";

        // Debit MERCHANDISE_SALES for the full daily fixed cost total.
        $this->accountService->debit(
            account: $merchandiseSalesAccount,
            amount: $totalDailyFixedCost,
            label: "Charges fixes journée {$dateString} — {$carLoadName}",
            referenceType: 'CLOSE_DAY_FIXED_COST',
            referenceId: $activeCarLoad->id,
        );

        // Credit the Storage pool account.
        if ($dailyStorageAmount > 0) {
            $storageAccount = $this->accountService->getOrCreateFixedCostAccount(
                MonthlyFixedCostPool::Storage->label()
            );

            $this->accountService->credit(
                account: $storageAccount,
                amount: $dailyStorageAmount,
                label: "Charges stockage journée {$dateString} — {$carLoadName}",
                referenceType: 'CLOSE_DAY_FIXED_COST',
                referenceId: $activeCarLoad->id,
            );
        }

        // Credit the Overhead pool account.
        if ($dailyOverheadAmount > 0) {
            $overheadAccount = $this->accountService->getOrCreateFixedCostAccount(
                MonthlyFixedCostPool::Overhead->label()
            );

            $this->accountService->credit(
                account: $overheadAccount,
                amount: $dailyOverheadAmount,
                label: "Frais généraux journée {$dateString} — {$carLoadName}",
                referenceType: 'CLOSE_DAY_FIXED_COST',
                referenceId: $activeCarLoad->id,
            );
        }

        return $totalDailyFixedCost;
    }

    /**
     * Compute this car load's monthly allocation for a given fixed cost pool.
     *
     * Formula (same as AbcFixedCostDistributionService, but computed live from raw
     * amounts so it works even when the month has not yet been finalized):
     *
     *   per_carload_monthly = pool_total / active_vehicle_count / carloads_for_this_vehicle
     *
     * - pool_total              : sum of MonthlyFixedCost.amount for the pool in this month
     * - active_vehicle_count    : distinct vehicles with a car load this month
     * - carloads_for_this_vehicle: how many car loads this vehicle ran this month
     *
     * @return int monthly XOF amount allocated to this car load for the given pool
     */
    private function computeCarLoadPoolMonthlyAllocation(
        CarLoad $activeCarLoad,
        MonthlyFixedCostPool $pool,
        Carbon $date,
    ): int {
        $year = $date->year;
        $month = $date->month;

        $poolTotal = (int) MonthlyFixedCost::query()
            ->where('cost_pool', $pool)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->sum('amount');

        if ($poolTotal === 0) {
            return 0;
        }

        $activeVehicleCount = max(1, CarLoad::query()
            ->whereNotNull('vehicle_id')
            ->whereYear('load_date', $year)
            ->whereMonth('load_date', $month)
            ->distinct('vehicle_id')
            ->count('vehicle_id'));

        $carloadsForThisVehicleThisMonth = max(1, CarLoad::query()
            ->where('vehicle_id', $activeCarLoad->vehicle_id)
            ->whereYear('load_date', $year)
            ->whereMonth('load_date', $month)
            ->count());

        return (int) round($poolTotal / $activeVehicleCount / $carloadsForThisVehicleThisMonth);
    }

    /**
     * Sum of Payment.profit for all payments received by this commercial on the given date.
     *
     * This is the "gross daily profit" — the realized margin on goods sold and paid for today.
     * Formula mirrors StatisticsService: totalRealizedProfit = SUM(payments.profit).
     *
     * Returns 0 when the commercial has no user account (factory default) or when no
     * payments were received today.
     */
    private function computeGrossDailyProfit(Commercial $commercial, Carbon $date): int
    {
        if ($commercial->user_id === null) {
            return 0;
        }

        return (int) Payment::where('user_id', $commercial->user_id)
            ->whereDate('created_at', $date->toDateString())
            ->sum('profit');
    }

    /**
     * Find the commercial's currently active car load.
     * "Active" means the vehicle is still in operation: Selling or OngoingInventory.
     * Returns null if the commercial has no team or the team has no active car load.
     */
    private function findActiveCarLoad(Commercial $commercial): ?CarLoad
    {
        $team = $commercial->team;

        if ($team === null) {
            return null;
        }

        return CarLoad::query()
            ->where('team_id', $team->id)
            ->whereIn('status', [CarLoadStatus::Selling, CarLoadStatus::OngoingInventory])
            ->with('vehicle')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Find today's DailyCommission for the commercial that has not yet been finalized.
     *
     * @noinspection SpellCheckingInspection
     */
    private function findTodaysDailyCommission(Commercial $commercial, Carbon $date): ?DailyCommission
    {
        $workPeriod = CommercialWorkPeriod::query()
            ->where('commercial_id', $commercial->id)
            ->whereDate('period_start_date', '<=', $date)
            ->whereDate('period_end_date', '>=', $date)
            ->first();

        if ($workPeriod === null) {
            return null;
        }

        return DailyCommission::query()
            ->where('commercial_work_period_id', $workPeriod->id)
            ->whereDate('work_day', $date->toDateString())
            ->whereNull('finalized_at')
            ->first();
    }
}
