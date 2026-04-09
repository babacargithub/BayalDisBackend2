<?php

namespace Tests\Feature\Account;

use App\Enums\AccountTransactionType;
use App\Enums\AccountType;
use App\Enums\CaisseType;
use App\Enums\CarLoadStatus;
use App\Enums\MonthlyFixedCostPool;
use App\Enums\MonthlyFixedCostSubCategory;
use App\Exceptions\DayAlreadyClosedException;
use App\Exceptions\DayCaisseClosedException;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Caisse;
use App\Models\CarLoad;
use App\Models\Commercial;
use App\Models\CommercialWorkPeriod;
use App\Models\DailyCommission;
use App\Models\MonthlyFixedCost;
use App\Models\Payment;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\CaisseService;
use App\Services\VersementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseDayTest extends TestCase
{
    use RefreshDatabase;

    private CaisseService $closeDayService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->closeDayService = app(CaisseService::class);
    }

    private function assertGlobalInvariantHolds(): void
    {
        $totalAccounts = Account::sum('balance');
        $totalCaisses = Caisse::sum('balance');
        $this->assertSame($totalCaisses, $totalAccounts,
            "Invariant violated: accounts={$totalAccounts} ≠ caisses={$totalCaisses}");
    }

    /**
     * Create a commercial with a caisse (via the model's booted hook) and
     * sync the COMMERCIAL_COLLECTED account to match the caisse balance.
     */
    private function createCommercialWithCaisse(int $caisseBalance = 0): Commercial
    {
        $commercial = Commercial::factory()->create();
        $commercial->caisse->update(['balance' => $caisseBalance]);

        if ($caisseBalance > 0) {
            $this->addCreditToCollectedAccount($commercial, $caisseBalance);
        }

        return $commercial;
    }

    private function createMerchandiseSalesAccount(int $balance = 0): Account
    {
        return Account::create([
            'name' => 'Vente marchandises',
            'account_type' => AccountType::MerchandiseSales,
            'balance' => $balance,
            'is_active' => true,
        ]);
    }

    private function createTodayDailyCommission(Commercial $commercial, int $netCommission): DailyCommission
    {
        $workPeriod = CommercialWorkPeriod::create([
            'commercial_id' => $commercial->id,
            'period_start_date' => today()->startOfWeek()->toDateString(),
            'period_end_date' => today()->endOfWeek()->toDateString(),
            'is_finalized' => false,
        ]);

        return DailyCommission::create([
            'commercial_work_period_id' => $workPeriod->id,
            'work_day' => today()->toDateString(),
            'base_commission' => $netCommission,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'new_confirmed_customers_bonus' => 0,
            'new_prospect_customers_bonus' => 0,
            'net_commission' => $netCommission,
            'basket_achieved' => false,
        ]);
    }

    /**
     * Add a credit AccountTransaction to the COMMERCIAL_COLLECTED account and sync its
     * cached balance. Required whenever test setup directly assigns a non-zero balance,
     * because AccountService::debit() calls updateBalanceFromLedger() which recomputes
     * from transactions — a direct DB update without a matching transaction results in a
     * negative balance after the debit.
     */
    private function addCreditToCollectedAccount(Commercial $commercial, int $amount): void
    {
        $collectedAccount = Account::where('account_type', AccountType::CommercialCollected->value)
            ->where('commercial_id', $commercial->id)
            ->firstOrFail();

        AccountTransaction::create([
            'account_id' => $collectedAccount->id,
            'amount' => $amount,
            'transaction_type' => AccountTransactionType::Credit,
            'label' => 'Solde initial (test setup)',
            'reference_type' => 'TEST_SETUP',
        ]);

        $collectedAccount->updateBalanceFromLedger();
    }

    /**
     * Insert a payment record for the given commercial on the given date with a specific
     * gross profit value. Uses DB insert (bypassing model events) so the caisse balance
     * is not affected — tests that need both caisse balance AND gross profit must set them
     * up independently.
     */
    private function insertPaymentWithGrossProfit(
        Commercial $commercial,
        int $grossProfit,
        Carbon $date,
    ): void {
        \Illuminate\Support\Facades\DB::table('payments')->insert([
            'amount' => $grossProfit * 4, // plausible revenue — not used in profit calculation
            'profit' => $grossProfit,
            'commercial_commission' => 0,
            'payment_method' => 'CASH',
            'user_id' => $commercial->user_id,
            'created_at' => $date->toDateTimeString(),
            'updated_at' => $date->toDateTimeString(),
        ]);
    }

    // ── Step 1: COMMERCIAL_COLLECTED → MERCHANDISE_SALES ─────────────────────

    public function test_close_day_transfers_caisse_balance_from_collected_to_merchandise_sales(): void
    {
        $commercial = $this->createCommercialWithCaisse(80_000);
        $merchandiseSalesAccount = $this->createMerchandiseSalesAccount(0);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $merchandiseSalesAccount->refresh();
        $this->assertSame(80_000, $merchandiseSalesAccount->balance);
    }

    public function test_close_day_drains_commercial_collected_account_to_zero(): void
    {
        $commercial = $this->createCommercialWithCaisse(80_000);
        $this->createMerchandiseSalesAccount(0);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $collectedAccount = Account::where('account_type', AccountType::CommercialCollected->value)
            ->where('commercial_id', $commercial->id)
            ->firstOrFail();

        $this->assertSame(0, $collectedAccount->balance);
    }

    public function test_close_day_does_not_modify_collected_or_merchandise_when_caisse_is_empty(): void
    {
        $commercial = $this->createCommercialWithCaisse(0);
        $merchandiseSalesAccount = $this->createMerchandiseSalesAccount(20_000);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $collectedAccount = Account::where('account_type', AccountType::CommercialCollected->value)
            ->where('commercial_id', $commercial->id)
            ->firstOrFail();

        $this->assertSame(0, $collectedAccount->balance);
        $merchandiseSalesAccount->refresh();
        $this->assertSame(20_000, $merchandiseSalesAccount->balance);
    }

    // ── Step 2: MERCHANDISE_SALES → COMMERCIAL_COMMISSION ────────────────────

    public function test_close_day_credits_commercial_commission_with_net_commission(): void
    {
        // Caisse = 80_000 so step 1 funds MERCHANDISE_SALES before step 2 draws from it.
        $commercial = $this->createCommercialWithCaisse(80_000);
        $this->createMerchandiseSalesAccount(0);
        $this->createTodayDailyCommission($commercial, 12_000);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $commissionAccount = Account::where('account_type', AccountType::CommercialCommission->value)
            ->where('commercial_id', $commercial->id)
            ->firstOrFail();
        $this->assertSame(12_000, $commissionAccount->balance);
    }

    public function test_close_day_merchandise_sales_net_of_commission_after_both_steps(): void
    {
        // Step 1: COLLECTED (80_000) → MERCHANDISE_SALES (was 0 → becomes 80_000).
        // Step 2: MERCHANDISE_SALES (-12_000) → COMMISSION. MERCHANDISE_SALES = 68_000.
        $commercial = $this->createCommercialWithCaisse(80_000);
        $merchandiseSalesAccount = $this->createMerchandiseSalesAccount(0);
        $this->createTodayDailyCommission($commercial, 12_000);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $merchandiseSalesAccount->refresh();
        $this->assertSame(68_000, $merchandiseSalesAccount->balance);
    }

    public function test_close_day_commission_account_balance_starts_at_zero_before_close(): void
    {
        $commercial = $this->createCommercialWithCaisse(20_000);
        $this->createMerchandiseSalesAccount(0);
        $this->createTodayDailyCommission($commercial, 5_000);

        // The Commercial booted hook auto-creates the commission account with balance = 0.
        $commissionAccount = Account::where('account_type', AccountType::CommercialCommission->value)
            ->where('commercial_id', $commercial->id)
            ->firstOrFail();
        $this->assertSame(0, $commissionAccount->balance);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $commissionAccount->refresh();
        $this->assertSame(5_000, $commissionAccount->balance);
    }

    // ── finalized_at ─────────────────────────────────────────────────────────

    public function test_close_day_sets_finalized_at_on_today_daily_commission(): void
    {
        $commercial = $this->createCommercialWithCaisse(30_000);
        $this->createMerchandiseSalesAccount(0);
        $dailyCommission = $this->createTodayDailyCommission($commercial, 8_000);

        $this->assertNull($dailyCommission->finalized_at);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $dailyCommission->refresh();
        $this->assertNotNull($dailyCommission->finalized_at);
    }

    public function test_close_day_does_not_finalize_commissions_from_other_days(): void
    {
        $commercial = $this->createCommercialWithCaisse(0);
        $this->createMerchandiseSalesAccount(0);

        $pastWorkPeriod = CommercialWorkPeriod::create([
            'commercial_id' => $commercial->id,
            'period_start_date' => today()->subDays(14)->startOfWeek()->toDateString(),
            'period_end_date' => today()->subDays(14)->endOfWeek()->toDateString(),
            'is_finalized' => false,
        ]);
        $yesterdayCommission = DailyCommission::create([
            'commercial_work_period_id' => $pastWorkPeriod->id,
            'work_day' => today()->subDay()->toDateString(),
            'base_commission' => 5_000,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'new_confirmed_customers_bonus' => 0,
            'new_prospect_customers_bonus' => 0,
            'net_commission' => 5_000,
            'basket_achieved' => false,
        ]);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $yesterdayCommission->refresh();
        $this->assertNull($yesterdayCommission->finalized_at);
    }

    // ── Caisse locking ────────────────────────────────────────────────────────

    public function test_close_day_sets_locked_until_to_today_on_commercial_caisse(): void
    {
        $commercial = $this->createCommercialWithCaisse();
        $this->createMerchandiseSalesAccount(0);

        $this->assertNull($commercial->caisse->locked_until);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $commercial->caisse->refresh();
        $this->assertTrue($commercial->caisse->locked_until->isToday());
    }

    public function test_caisse_is_locked_for_today_after_close_day(): void
    {
        $commercial = $this->createCommercialWithCaisse();
        $this->createMerchandiseSalesAccount(0);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $commercial->caisse->refresh();
        $this->assertTrue($commercial->caisse->isLockedForToday());
    }

    // ── Payment blocking ─────────────────────────────────────────────────────

    public function test_payment_creation_is_blocked_when_commercial_caisse_is_locked_for_today(): void
    {
        $user = User::factory()->create();
        $commercial = $this->createCommercialWithCaisse();
        $commercial->update(['user_id' => $user->id]);
        $this->createMerchandiseSalesAccount(0);
        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $this->expectException(DayCaisseClosedException::class);

        Payment::create([
            'amount' => 1_000,
            'payment_method' => 'CASH',
            'user_id' => $user->id,
        ]);
    }

    // ── Idempotency ───────────────────────────────────────────────────────────

    public function test_close_day_throws_day_already_closed_exception_on_second_call_for_same_date(): void
    {
        $commercial = $this->createCommercialWithCaisse(30_000);
        $this->createMerchandiseSalesAccount(0);
        $this->createTodayDailyCommission($commercial, 5_000);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $this->expectException(DayAlreadyClosedException::class);
        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());
    }

    public function test_close_day_does_not_double_credit_commission_when_called_twice(): void
    {
        $commercial = $this->createCommercialWithCaisse(30_000);
        $this->createMerchandiseSalesAccount(0);
        $this->createTodayDailyCommission($commercial, 5_000);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        try {
            $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());
        } catch (DayAlreadyClosedException) {
            // Expected.
        }

        $commissionAccount = Account::where('account_type', AccountType::CommercialCommission->value)
            ->where('commercial_id', $commercial->id)
            ->firstOrFail();

        $this->assertSame(5_000, $commissionAccount->balance);
    }

    // ── Zero / no commission edge cases ──────────────────────────────────────

    public function test_close_day_locks_caisse_when_net_commission_is_zero(): void
    {
        $commercial = $this->createCommercialWithCaisse();
        $this->createMerchandiseSalesAccount(0);
        $this->createTodayDailyCommission($commercial, 0);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $commercial->caisse->refresh();
        $this->assertTrue($commercial->caisse->isLockedForToday());
    }

    public function test_close_day_does_not_modify_merchandise_sales_commission_when_net_commission_is_zero(): void
    {
        // caisse = 0, so step 1 is skipped; commission = 0, so step 2 is skipped.
        $commercial = $this->createCommercialWithCaisse(0);
        $merchandiseSalesAccount = $this->createMerchandiseSalesAccount(20_000);
        $this->createTodayDailyCommission($commercial, 0);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $merchandiseSalesAccount->refresh();
        $this->assertSame(20_000, $merchandiseSalesAccount->balance);
    }

    public function test_close_day_locks_caisse_when_no_daily_commission_exists_for_today(): void
    {
        $commercial = $this->createCommercialWithCaisse();
        $this->createMerchandiseSalesAccount(0);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $commercial->caisse->refresh();
        $this->assertTrue($commercial->caisse->isLockedForToday());
    }

    // ── Global invariant ─────────────────────────────────────────────────────

    public function test_global_invariant_is_preserved_after_close_day(): void
    {
        // Commercial caisse = 100_000, COMMERCIAL_COLLECTED = 100_000 (auto-created by booted hook).
        $commercial = $this->createCommercialWithCaisse(100_000);

        // MERCHANDISE_SALES = 0 (funded entirely from today's collections via step 1).
        // A main caisse with 0 balances the invariant.
        $this->createMerchandiseSalesAccount(0);
        Caisse::create([
            'name' => 'Caisse principale',
            'caisse_type' => CaisseType::Main,
            'balance' => 0,
            'closed' => false,
        ]);

        $this->createTodayDailyCommission($commercial, 10_000);

        $this->assertGlobalInvariantHolds();

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $this->assertGlobalInvariantHolds();
    }

    // ── Error cases ───────────────────────────────────────────────────────────

    public function test_close_day_throws_runtime_exception_when_commercial_has_no_caisse(): void
    {
        $commercial = Commercial::factory()->create();
        $commercial->caisse?->forceDelete();
        $commercial->unsetRelation('caisse');

        $this->expectException(\RuntimeException::class);
        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());
    }

    // ── VersementService integration: accounts settled at close-day ───────────

    public function test_versement_skips_account_settlement_when_close_day_already_drained_collected(): void
    {
        $commercial = $this->createCommercialWithCaisse(80_000);
        $this->createMerchandiseSalesAccount(0);
        $this->createTodayDailyCommission($commercial, 10_000);

        // Close day: COLLECTED (80_000) → MERCHANDISE_SALES, MERCHANDISE_SALES → COMMISSION (10_000).
        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        // After close-day: COLLECTED = 0, MERCHANDISE_SALES = 70_000, COMMISSION = 10_000.
        $collectedAccount = Account::where('account_type', AccountType::CommercialCollected->value)
            ->where('commercial_id', $commercial->id)
            ->firstOrFail();
        $this->assertSame(0, $collectedAccount->balance);

        // Unlock caisse so versement can proceed (physical sweep still valid).
        $commercial->caisse->update(['locked_until' => null]);

        $mainCaisse = Caisse::create([
            'name' => 'Caisse principale',
            'caisse_type' => CaisseType::Main,
            'balance' => 0,
            'closed' => false,
        ]);

        app(VersementService::class)->performVersement($commercial, $mainCaisse);

        // COMMERCIAL_COLLECTED must stay at 0 (versement did not re-debit it).
        $collectedAccount->refresh();
        $this->assertSame(0, $collectedAccount->balance);
    }

    public function test_versement_does_not_double_credit_commission_after_close_day(): void
    {
        $commercial = $this->createCommercialWithCaisse(80_000);
        $this->createMerchandiseSalesAccount(0);
        $this->createTodayDailyCommission($commercial, 10_000);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $commissionAccount = Account::where('account_type', AccountType::CommercialCommission->value)
            ->where('commercial_id', $commercial->id)
            ->firstOrFail();
        $this->assertSame(10_000, $commissionAccount->balance);

        $commercial->caisse->update(['locked_until' => null]);
        $mainCaisse = Caisse::create([
            'name' => 'Caisse principale',
            'caisse_type' => CaisseType::Main,
            'balance' => 0,
            'closed' => false,
        ]);

        app(VersementService::class)->performVersement($commercial, $mainCaisse);

        // COMMISSION must remain at 10_000 — versement did not re-credit it.
        $commissionAccount->refresh();
        $this->assertSame(10_000, $commissionAccount->balance);
    }

    public function test_versement_merchandise_sales_unchanged_after_physical_sweep_when_close_day_done(): void
    {
        $commercial = $this->createCommercialWithCaisse(80_000);
        $merchandiseSalesAccount = $this->createMerchandiseSalesAccount(0);
        $this->createTodayDailyCommission($commercial, 10_000);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        // After close-day: MERCHANDISE_SALES = 80_000 - 10_000 = 70_000.
        $merchandiseSalesAccount->refresh();
        $this->assertSame(70_000, $merchandiseSalesAccount->balance);

        $commercial->caisse->update(['locked_until' => null]);
        $mainCaisse = Caisse::create([
            'name' => 'Caisse principale',
            'caisse_type' => CaisseType::Main,
            'balance' => 0,
            'closed' => false,
        ]);

        app(VersementService::class)->performVersement($commercial, $mainCaisse);

        // Versement does not touch MERCHANDISE_SALES when accounts are already settled.
        $merchandiseSalesAccount->refresh();
        $this->assertSame(70_000, $merchandiseSalesAccount->balance);
    }

    public function test_versement_marks_finalized_commissions_with_versement_id(): void
    {
        $commercial = $this->createCommercialWithCaisse(50_000);
        $this->createMerchandiseSalesAccount(0);
        $dailyCommission = $this->createTodayDailyCommission($commercial, 8_000);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());
        $commercial->caisse->update(['locked_until' => null]);

        $mainCaisse = Caisse::create([
            'name' => 'Caisse principale',
            'caisse_type' => CaisseType::Main,
            'balance' => 0,
            'closed' => false,
        ]);

        $versement = app(VersementService::class)->performVersement($commercial, $mainCaisse);

        $dailyCommission->refresh();
        $this->assertSame($versement->id, $dailyCommission->versement_id);
    }

    // ── Vehicle daily cost distribution ───────────────────────────────────────

    /**
     * Create a commercial with a caisse, an active car load (Selling), and a vehicle
     * with deterministic daily costs so tests can assert exact amounts.
     */
    private function createTeam(string $name = 'Équipe Test'): Team
    {
        $manager = User::factory()->create();

        return Team::create(['name' => $name, 'user_id' => $manager->id]);
    }

    private function createCommercialWithActiveCarLoadAndVehicle(int $caisseBalance = 200_000): array
    {
        $vehicle = Vehicle::factory()->create([
            'depreciation_monthly' => 26_000,  // 1_000 / day  (26 working days)
            'insurance_monthly' => 52_000,     // 2_000 / day
            'repair_reserve_monthly' => 26_000, // 1_000 / day
            'maintenance_monthly' => 26_000,    // 1_000 / day
            'driver_salary_monthly' => 0,       // excluded to keep totals deterministic
            'estimated_daily_fuel_consumption' => 5_000,
            'working_days_per_month' => 26,
        ]);
        // total daily vehicle cost = 1_000 + 2_000 + 1_000 + 1_000 + 0 + 5_000 = 10_000

        $team = $this->createTeam();

        $commercial = Commercial::factory()->create(['team_id' => $team->id]);
        $commercial->caisse->update(['balance' => $caisseBalance]);

        if ($caisseBalance > 0) {
            $this->addCreditToCollectedAccount($commercial, $caisseBalance);
        }

        CarLoad::create([
            'name' => 'Chargement test',
            'load_date' => today(),
            'team_id' => $team->id,
            'vehicle_id' => $vehicle->id,
            'status' => CarLoadStatus::Selling,
        ]);

        return [$commercial, $vehicle];
    }

    public function test_close_day_credits_vehicle_depreciation_account_with_daily_amount(): void
    {
        [$commercial, $vehicle] = $this->createCommercialWithActiveCarLoadAndVehicle(200_000);
        $this->createMerchandiseSalesAccount(200_000);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $depreciationAccount = Account::where('account_type', AccountType::VehicleDepreciation->value)
            ->where('vehicle_id', $vehicle->id)
            ->firstOrFail();
        $this->assertSame(1_000, $depreciationAccount->balance);
    }

    public function test_close_day_credits_vehicle_insurance_account_with_daily_amount(): void
    {
        [$commercial, $vehicle] = $this->createCommercialWithActiveCarLoadAndVehicle(200_000);
        $this->createMerchandiseSalesAccount(200_000);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $insuranceAccount = Account::where('account_type', AccountType::VehicleInsurance->value)
            ->where('vehicle_id', $vehicle->id)
            ->firstOrFail();
        $this->assertSame(2_000, $insuranceAccount->balance);
    }

    public function test_close_day_credits_vehicle_fuel_account_with_daily_consumption_estimate(): void
    {
        // Fuel is distributed as a reserve equal to estimated_daily_fuel_consumption regardless
        // of any CarLoadExpense records. The VehicleFuel account absorbs any rounding surplus/deficit
        // from the other fixed cost accounts.
        // vehicle.daily_fixed_cost = 10_000, sum of non-fuel = 5_000 → fuel = 5_000.
        [$commercial, $vehicle] = $this->createCommercialWithActiveCarLoadAndVehicle(200_000);
        $this->createMerchandiseSalesAccount(200_000);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $fuelAccount = Account::where('account_type', AccountType::VehicleFuel->value)
            ->where('vehicle_id', $vehicle->id)
            ->firstOrFail();
        $this->assertSame(5_000, $fuelAccount->balance);
    }

    public function test_close_day_fuel_account_absorbs_rounding_surplus_from_non_fuel_costs(): void
    {
        // When non-fuel fixed costs don't divide evenly, VehicleFuel absorbs the rounding gap
        // so that SUM(all vehicle account credits) == vehicle.daily_fixed_cost exactly.
        // Here daily_fixed_cost = 10_000, non-fuel rounded sum = 5_000 → fuel = 5_000.
        [$commercial, $vehicle] = $this->createCommercialWithActiveCarLoadAndVehicle(200_000);
        $this->createMerchandiseSalesAccount(200_000);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $fuelAccount = Account::where('account_type', AccountType::VehicleFuel->value)
            ->where('vehicle_id', $vehicle->id)
            ->firstOrFail();
        $this->assertSame(5_000, $fuelAccount->balance);
    }

    public function test_close_day_debits_total_vehicle_cost_from_merchandise_sales(): void
    {
        // MERCHANDISE_SALES is funded by step 1 (COLLECTED → MERCHANDISE_SALES).
        // Then step 3 debits the full daily vehicle cost (fixed + fuel reserve) from it.
        // vehicle.daily_fixed_cost = 10_000 (5_000 non-fuel + 5_000 fuel).
        // caisse = 200_000 → MERCHANDISE_SALES +200_000, then -10_000 vehicle.
        [$commercial] = $this->createCommercialWithActiveCarLoadAndVehicle(200_000);
        $merchandiseSalesAccount = $this->createMerchandiseSalesAccount(0);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $merchandiseSalesAccount->refresh();
        // 0 + 200_000 (step 1) - 10_000 (vehicle costs step 3) = 190_000
        $this->assertSame(190_000, $merchandiseSalesAccount->balance);
    }

    public function test_close_day_skips_vehicle_cost_distribution_when_commercial_has_no_team(): void
    {
        $commercial = $this->createCommercialWithCaisse(50_000);
        // Ensure no team_id on the commercial.
        $commercial->update(['team_id' => null]);
        $merchandiseSalesAccount = $this->createMerchandiseSalesAccount(0);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        // MERCHANDISE_SALES only received step 1 (50_000) — no vehicle cost deduction.
        $merchandiseSalesAccount->refresh();
        $this->assertSame(50_000, $merchandiseSalesAccount->balance);
    }

    public function test_close_day_skips_vehicle_cost_distribution_when_team_has_no_active_car_load(): void
    {
        $team = $this->createTeam('Équipe sans chargement actif');
        $commercial = Commercial::factory()->create(['team_id' => $team->id]);
        $commercial->caisse->update(['balance' => 50_000]);
        Account::where('account_type', AccountType::CommercialCollected->value)
            ->where('commercial_id', $commercial->id)
            ->update(['balance' => 50_000]);

        $merchandiseSalesAccount = $this->createMerchandiseSalesAccount(0);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        // No active car load — vehicle costs skipped. MERCHANDISE_SALES = 50_000 from step 1 only.
        $merchandiseSalesAccount->refresh();
        $this->assertSame(50_000, $merchandiseSalesAccount->balance);
    }

    public function test_close_day_distributes_fixed_costs_when_car_load_is_in_loading_status(): void
    {
        // A car load in LOADING status (being prepared) should still trigger fixed cost distribution.
        // This is the real-world scenario: after one car load cycle ends, the next car load is
        // created in LOADING state while commercials continue receiving payments on old invoices.
        // Fixed costs (warehouse rent, overhead) accrue daily regardless of car load selling status.
        $commercial = $this->createCommercialWithCaisse(50_000);
        $team = $this->createTeam('Équipe chargement en cours');
        $commercial->update(['team_id' => $team->id]);

        CarLoad::create([
            'name' => 'Chargement en préparation',
            'load_date' => today(),
            'team_id' => $team->id,
            'vehicle_id' => null,
            'status' => CarLoadStatus::Loading,
        ]);

        $this->createMonthlyFixedCost(MonthlyFixedCostPool::Storage, 26_000, perVehicleAmount: 26_000);
        // daily = 26_000 / 26 = 1_000
        $merchandiseSalesAccount = $this->createMerchandiseSalesAccount(0);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        // MERCHANDISE_SALES: +50_000 (step 1) - 1_000 (fixed cost step 4) = 49_000
        $merchandiseSalesAccount->refresh();
        $this->assertSame(49_000, $merchandiseSalesAccount->balance);

        $storageAccount = Account::where('account_type', AccountType::FixedCost->value)
            ->where('name', MonthlyFixedCostPool::Storage->label())
            ->firstOrFail();
        $this->assertSame(1_000, $storageAccount->balance);
    }

    public function test_close_day_distributes_vehicle_costs_when_car_load_is_in_loading_status_with_vehicle(): void
    {
        // A car load in LOADING status WITH a vehicle should distribute vehicle daily costs.
        // The vehicle is already assigned and its daily operating costs should start accruing.
        $vehicle = Vehicle::factory()->create([
            'depreciation_monthly' => 26_000, // 1_000 / day
            'insurance_monthly' => 0,
            'repair_reserve_monthly' => 0,
            'maintenance_monthly' => 0,
            'driver_salary_monthly' => 0,
            'estimated_daily_fuel_consumption' => 2_000,
            'working_days_per_month' => 26,
        ]);
        // total daily = 1_000 (depreciation) + 2_000 (fuel) = 3_000

        $commercial = $this->createCommercialWithCaisse(100_000);
        $team = $this->createTeam('Équipe avec véhicule en chargement');
        $commercial->update(['team_id' => $team->id]);

        CarLoad::create([
            'name' => 'Chargement avec véhicule',
            'load_date' => today(),
            'team_id' => $team->id,
            'vehicle_id' => $vehicle->id,
            'status' => CarLoadStatus::Loading,
        ]);

        $merchandiseSalesAccount = $this->createMerchandiseSalesAccount(0);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        // Vehicle depreciation account should have 1_000 credited.
        $depreciationAccount = Account::where('account_type', AccountType::VehicleDepreciation->value)
            ->where('vehicle_id', $vehicle->id)
            ->firstOrFail();
        $this->assertSame(1_000, $depreciationAccount->balance);

        // MERCHANDISE_SALES: +100_000 (step 1) - 3_000 (vehicle costs step 3) = 97_000
        $merchandiseSalesAccount->refresh();
        $this->assertSame(97_000, $merchandiseSalesAccount->balance);
    }

    public function test_close_day_vehicle_costs_are_not_distributed_twice_when_two_commercials_on_same_team_close(): void
    {
        $vehicle = Vehicle::factory()->create([
            'depreciation_monthly' => 26_000,
            'insurance_monthly' => 52_000,
            'repair_reserve_monthly' => 26_000,
            'maintenance_monthly' => 26_000,
            'driver_salary_monthly' => 0,
            'estimated_daily_fuel_consumption' => 5_000,
            'working_days_per_month' => 26,
        ]);
        // total daily = 1_000 + 2_000 + 1_000 + 1_000 + 0 + 5_000 (fuel) = 10_000

        $team = $this->createTeam('Équipe partagée');

        $firstCommercial = Commercial::factory()->create(['team_id' => $team->id]);
        $firstCommercial->caisse->update(['balance' => 100_000]);
        $this->addCreditToCollectedAccount($firstCommercial, 100_000);

        $secondCommercial = Commercial::factory()->create(['team_id' => $team->id]);
        $secondCommercial->caisse->update(['balance' => 80_000]);
        $this->addCreditToCollectedAccount($secondCommercial, 80_000);

        CarLoad::create([
            'name' => 'Chargement partagé',
            'load_date' => today(),
            'team_id' => $team->id,
            'vehicle_id' => $vehicle->id,
            'status' => CarLoadStatus::Selling,
        ]);

        // Fund MERCHANDISE_SALES enough for both close-days.
        $this->createMerchandiseSalesAccount(0);

        // First commercial closes their day — vehicle costs distributed (10_000, including fuel).
        $this->closeDayService->closeCaisseForDay($firstCommercial, Carbon::today());

        // Second commercial closes their day — vehicle costs must be skipped (already done).
        $this->closeDayService->closeCaisseForDay($secondCommercial, Carbon::today());

        // Vehicle cost accounts must total 10_000 (one distribution, not two).
        $totalVehicleCostAccountBalance = Account::whereIn('account_type', [
            AccountType::VehicleDepreciation->value,
            AccountType::VehicleInsurance->value,
            AccountType::VehicleRepairReserve->value,
            AccountType::VehicleMaintenance->value,
            AccountType::VehicleFuel->value,
        ])->where('vehicle_id', $vehicle->id)->sum('balance');

        $this->assertSame(10_000, $totalVehicleCostAccountBalance);

        // Only one CLOSE_DAY_VEHICLE debit transaction should exist for this vehicle.
        $vehicleCostTransactionCount = AccountTransaction::where('reference_type', 'CLOSE_DAY_VEHICLE')
            ->where('reference_id', $vehicle->id)
            ->where('amount', 10_000)
            ->count();
        $this->assertSame(1, $vehicleCostTransactionCount);
    }

    public function test_global_invariant_is_preserved_after_close_day_with_vehicle_costs(): void
    {
        // commercial caisse = 100_000, COLLECTED = 100_000, main caisse = 0
        // MERCHANDISE_SALES = 0 → after step 1: 100_000 → after step 3: 90_000 (vehicle 10_000)
        // Vehicle accounts = 10_000
        // Account total: COLLECTED(0) + MERCHANDISE_SALES(90_000) + COMMISSION(0) + VEHICLE_ACCOUNTS(10_000) = 100_000
        // Caisse total: commercial(100_000) + main(0) = 100_000 ✓
        [$commercial] = $this->createCommercialWithActiveCarLoadAndVehicle(100_000);
        $this->createMerchandiseSalesAccount(0);
        Caisse::create([
            'name' => 'Caisse principale',
            'caisse_type' => CaisseType::Main,
            'balance' => 0,
            'closed' => false,
        ]);

        $this->assertGlobalInvariantHolds();

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $this->assertGlobalInvariantHolds();
    }

    // ── Fixed cost daily distribution ─────────────────────────────────────────

    /**
     * Create a MonthlyFixedCost with a pre-computed per_vehicle_amount so that
     * AbcFixedCostDistributionService can use it without requiring a finalized month.
     * per_vehicle_amount is what the service uses to compute per-carload allocation.
     */
    private function createMonthlyFixedCost(
        MonthlyFixedCostPool $costPool,
        int $amount,
        int $perVehicleAmount,
    ): MonthlyFixedCost {
        return MonthlyFixedCost::create([
            'label' => $costPool->label(),
            'sub_category' => MonthlyFixedCostSubCategory::Other,
            'cost_pool' => $costPool,
            'amount' => $amount,
            'per_vehicle_amount' => $perVehicleAmount,
            'period_year' => today()->year,
            'period_month' => today()->month,
        ]);
    }

    public function test_close_day_credits_storage_pool_account_with_daily_share(): void
    {
        // 1 active vehicle, 1 car load → per_carload_monthly = 260_000 → daily = 260_000 / 26 = 10_000
        [$commercial] = $this->createCommercialWithActiveCarLoadAndVehicle(200_000);
        $this->createMonthlyFixedCost(MonthlyFixedCostPool::Storage, 260_000, perVehicleAmount: 260_000);
        $this->createMerchandiseSalesAccount(200_000);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $storageAccount = Account::where('account_type', AccountType::FixedCost->value)
            ->where('name', MonthlyFixedCostPool::Storage->label())
            ->firstOrFail();
        $this->assertSame(10_000, $storageAccount->balance);
    }

    public function test_close_day_credits_overhead_pool_account_with_daily_share(): void
    {
        // per_vehicle_amount = 52_000 → daily = 52_000 / 26 = 2_000
        [$commercial] = $this->createCommercialWithActiveCarLoadAndVehicle(200_000);
        $this->createMonthlyFixedCost(MonthlyFixedCostPool::Overhead, 52_000, perVehicleAmount: 52_000);
        $this->createMerchandiseSalesAccount(200_000);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $overheadAccount = Account::where('account_type', AccountType::FixedCost->value)
            ->where('name', MonthlyFixedCostPool::Overhead->label())
            ->firstOrFail();
        $this->assertSame(2_000, $overheadAccount->balance);
    }

    public function test_close_day_debits_total_fixed_costs_from_merchandise_sales(): void
    {
        // Storage daily = 26_000 / 26 = 1_000. Overhead daily = 52_000 / 26 = 2_000. Total = 3_000.
        [$commercial] = $this->createCommercialWithActiveCarLoadAndVehicle(50_000);
        $this->createMonthlyFixedCost(MonthlyFixedCostPool::Storage, 26_000, perVehicleAmount: 26_000);
        $this->createMonthlyFixedCost(MonthlyFixedCostPool::Overhead, 52_000, perVehicleAmount: 52_000);
        // Pre-fund MERCHANDISE_SALES — step 1 will also add the 50_000 caisse balance.
        $merchandiseSalesAccount = $this->createMerchandiseSalesAccount(0);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $merchandiseSalesAccount->refresh();
        // step 1: +50_000 from COLLECTED; step 3: -10_000 vehicle (incl. fuel); step 4: -3_000 fixed → net 37_000
        $this->assertSame(37_000, $merchandiseSalesAccount->balance);
    }

    public function test_close_day_fixed_costs_are_distributed_once_per_car_load_not_per_commercial(): void
    {
        // Two commercials on the same team share the same car load.
        // Only the first close-day triggers fixed cost distribution for that car load.
        $vehicle = Vehicle::factory()->create([
            'depreciation_monthly' => 0, 'insurance_monthly' => 0,
            'repair_reserve_monthly' => 0, 'maintenance_monthly' => 0,
            'estimated_daily_fuel_consumption' => 0, 'working_days_per_month' => 26,
        ]);
        $team = $this->createTeam('Équipe partagée fixed');

        $firstCommercial = Commercial::factory()->create(['team_id' => $team->id]);
        $firstCommercial->caisse->update(['balance' => 50_000]);
        Account::where('account_type', AccountType::CommercialCollected->value)
            ->where('commercial_id', $firstCommercial->id)
            ->update(['balance' => 50_000]);

        $secondCommercial = Commercial::factory()->create(['team_id' => $team->id]);
        $secondCommercial->caisse->update(['balance' => 50_000]);
        Account::where('account_type', AccountType::CommercialCollected->value)
            ->where('commercial_id', $secondCommercial->id)
            ->update(['balance' => 50_000]);

        CarLoad::create([
            'name' => 'Chargement partagé',
            'load_date' => today(),
            'team_id' => $team->id,
            'vehicle_id' => $vehicle->id,
            'status' => CarLoadStatus::Selling,
        ]);

        // Overhead: 260_000 per vehicle, 1 vehicle, 1 car load → daily = 260_000 / 26 = 10_000
        $this->createMonthlyFixedCost(MonthlyFixedCostPool::Overhead, 260_000, perVehicleAmount: 260_000);
        $this->createMerchandiseSalesAccount(200_000);

        $this->closeDayService->closeCaisseForDay($firstCommercial, Carbon::today());
        $this->closeDayService->closeCaisseForDay($secondCommercial, Carbon::today());

        $overheadAccount = Account::where('account_type', AccountType::FixedCost->value)
            ->where('name', MonthlyFixedCostPool::Overhead->label())
            ->firstOrFail();

        // Must be 10_000 (one distribution for the shared car load), not 20_000.
        $this->assertSame(10_000, $overheadAccount->balance);
    }

    public function test_close_day_skips_fixed_cost_distribution_when_commercial_has_no_car_load(): void
    {
        // A commercial with no team/car load should not trigger any fixed cost distribution.
        $commercial = $this->createCommercialWithCaisse(50_000);
        $this->createMonthlyFixedCost(MonthlyFixedCostPool::Storage, 260_000, perVehicleAmount: 260_000);
        $merchandiseSalesAccount = $this->createMerchandiseSalesAccount(0);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $merchandiseSalesAccount->refresh();
        // Only step 1: +50_000 from COLLECTED. No fixed cost deduction.
        $this->assertSame(50_000, $merchandiseSalesAccount->balance);
    }

    // ── Net daily profit extraction ───────────────────────────────────────────

    public function test_close_day_credits_profit_account_with_net_daily_profit(): void
    {
        // gross_profit (from payments) = 25_000, commission = 8_000
        // vehicle daily = 10_000 (5_000 non-fuel + 5_000 fuel reserve)
        // net_daily_profit = 25_000 − 8_000 − 10_000 = 7_000
        [$commercial] = $this->createCommercialWithActiveCarLoadAndVehicle(100_000);
        $commercial->update(['user_id' => User::factory()->create()->id]);
        $this->createMerchandiseSalesAccount(0);
        $this->createTodayDailyCommission($commercial, 8_000);
        $this->insertPaymentWithGrossProfit($commercial, grossProfit: 25_000, date: Carbon::today());

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $profitAccount = Account::where('account_type', AccountType::Profit->value)->firstOrFail();
        $this->assertSame(7_000, $profitAccount->balance);
    }

    public function test_close_day_does_not_credit_profit_when_gross_profit_is_zero(): void
    {
        // No payment records → gross_daily_profit = 0 → net_daily_profit ≤ 0 → profit step is skipped.
        $commercial = $this->createCommercialWithCaisse(50_000);
        $this->createMerchandiseSalesAccount(0);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $profitAccount = Account::where('account_type', AccountType::Profit->value)->first();
        $this->assertNull($profitAccount, 'PROFIT account must not be created when gross profit is zero');
    }

    public function test_close_day_profit_accumulates_across_multiple_close_days(): void
    {
        $commercial = $this->createCommercialWithCaisse(60_000);
        $commercial->update(['user_id' => User::factory()->create()->id]);
        $this->createMerchandiseSalesAccount(0);

        // Day 1: gross profit = 15_000, no other costs → net profit = 15_000
        $this->insertPaymentWithGrossProfit($commercial, grossProfit: 15_000, date: Carbon::today());
        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        // Reset caisse for day 2.
        $commercial->caisse->update(['balance' => 40_000, 'locked_until' => null]);
        Account::where('account_type', AccountType::CommercialCollected->value)
            ->where('commercial_id', $commercial->id)
            ->update(['balance' => 40_000]);

        // Day 2: gross profit = 10_000 → net profit = 10_000
        $this->insertPaymentWithGrossProfit($commercial, grossProfit: 10_000, date: Carbon::today()->addDay());
        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today()->addDay());

        $profitAccount = Account::where('account_type', AccountType::Profit->value)->firstOrFail();
        $this->assertSame(25_000, $profitAccount->balance);
    }

    public function test_global_invariant_is_preserved_after_close_day_with_vehicle_and_fixed_costs(): void
    {
        // caisse = 200_000, vehicle daily = 10_000, overhead daily = 2_000
        // MERCHANDISE_SALES: 0 +200_000 (step 1) -10_000 (vehicle) -2_000 (fixed) = 188_000
        // Vehicle accounts = 10_000, Overhead account = 2_000
        // Total accounts = COLLECTED(0) + MERCH(188_000) + VEHICLE(10_000) + FIXED(2_000) = 200_000
        // Total caisses = commercial(200_000) + main(0) = 200_000 ✓
        [$commercial] = $this->createCommercialWithActiveCarLoadAndVehicle(200_000);
        $this->createMonthlyFixedCost(MonthlyFixedCostPool::Overhead, 52_000, perVehicleAmount: 52_000);
        $this->createMerchandiseSalesAccount(0);
        Caisse::create([
            'name' => 'Caisse principale',
            'caisse_type' => CaisseType::Main,
            'balance' => 0,
            'closed' => false,
        ]);

        $this->assertGlobalInvariantHolds();

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $this->assertGlobalInvariantHolds();
    }

    // ── Bug regression tests ──────────────────────────────────────────────────

    /**
     * Bug B regression: locked_until must be set to the requested $date, not today().
     * When closing a past date (e.g., yesterday), locked_until must reflect that past date
     * so that today's payments are not incorrectly blocked.
     */
    public function test_close_day_sets_locked_until_to_the_requested_date_not_today(): void
    {
        $commercial = $this->createCommercialWithCaisse(0);
        $yesterday = Carbon::yesterday();

        $this->closeDayService->closeCaisseForDay($commercial, $yesterday);

        $commercial->caisse->refresh();
        $this->assertTrue(
            $commercial->caisse->locked_until->isSameDay($yesterday),
            "locked_until should be yesterday ({$yesterday->toDateString()}), got {$commercial->caisse->locked_until->toDateString()}"
        );
    }

    /**
     * Bug D regression: when net_commission exceeds available MERCHANDISE_SALES balance,
     * the service must cap the transfer at the available balance instead of throwing
     * InsufficientAccountBalanceException — and still mark the DailyCommission as finalized.
     */
    public function test_close_day_caps_commission_transfer_at_available_merchandise_sales_balance(): void
    {
        // Caisse is empty (no step-1 funds): MERCHANDISE_SALES balance stays at 0.
        // Commission is 5_000 — more than the 0 available. Must not throw; must transfer 0.
        $commercial = $this->createCommercialWithCaisse(0);
        $merchandiseSalesAccount = $this->createMerchandiseSalesAccount(0);
        $dailyCommission = $this->createTodayDailyCommission($commercial, 5_000);

        // Must not throw.
        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        // Commission account receives nothing (no funds available).
        $commissionAccount = Account::where('account_type', AccountType::CommercialCommission->value)
            ->where('commercial_id', $commercial->id)
            ->firstOrFail();
        $this->assertSame(0, $commissionAccount->balance);

        // MERCHANDISE_SALES remains at 0 — nothing was over-drawn.
        $merchandiseSalesAccount->refresh();
        $this->assertSame(0, $merchandiseSalesAccount->balance);

        // The DailyCommission is still finalized so VersementService won't double-credit it.
        $dailyCommission->refresh();
        $this->assertNotNull($dailyCommission->finalized_at);
    }

    /**
     * Bug D (partial-funds variant): when caisse has some cash but it is less than the
     * commission, only the available balance is transferred.
     */
    public function test_close_day_transfers_only_available_balance_when_commission_exceeds_merchandise_sales(): void
    {
        // Step 1 puts 3_000 in MERCHANDISE_SALES. Commission is 5_000. Only 3_000 can be transferred.
        $commercial = $this->createCommercialWithCaisse(3_000);
        $merchandiseSalesAccount = $this->createMerchandiseSalesAccount(0);
        $this->createTodayDailyCommission($commercial, 5_000);

        $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());

        $commissionAccount = Account::where('account_type', AccountType::CommercialCommission->value)
            ->where('commercial_id', $commercial->id)
            ->firstOrFail();
        $this->assertSame(3_000, $commissionAccount->balance);

        // MERCHANDISE_SALES was drained entirely: 0 (3_000 in → 3_000 out to commission).
        $merchandiseSalesAccount->refresh();
        $this->assertSame(0, $merchandiseSalesAccount->balance);
    }
}
