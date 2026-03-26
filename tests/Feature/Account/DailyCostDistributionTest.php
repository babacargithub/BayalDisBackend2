<?php

namespace Tests\Feature\Account;

use App\Enums\AccountType;
use App\Exceptions\InsufficientAccountBalanceException;
use App\Models\Account;
use App\Models\Caisse;
use App\Models\DailyCostDistribution;
use App\Models\MonthlyFixedCost;
use App\Models\Vehicle;
use App\Services\AccountService;
use App\Services\DailyCostDistributionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyCostDistributionTest extends TestCase
{
    use RefreshDatabase;

    private DailyCostDistributionService $distributionService;

    private AccountService $accountService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->distributionService = app(DailyCostDistributionService::class);
        $this->accountService = app(AccountService::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Create the MERCHANDISE_SALES account with the given balance.
     */
    private function createMerchandiseSalesAccount(int $balance): Account
    {
        return Account::create([
            'name' => 'Vente marchandises',
            'account_type' => AccountType::MerchandiseSales->value,
            'balance' => $balance,
            'is_active' => true,
        ]);
    }

    /**
     * Assert that the global invariant holds: SUM(accounts) == SUM(caisses).
     */
    private function assertGlobalInvariantHolds(): void
    {
        $totalAccounts = Account::sum('balance');
        $totalCaisses = Caisse::sum('balance');
        $this->assertSame($totalCaisses, $totalAccounts,
            "Invariant violated: accounts={$totalAccounts} ≠ caisses={$totalCaisses}");
    }

    /**
     * Create a Vehicle with explicit cost fields and 26 working days.
     */
    private function createVehicle(array $overrides = []): Vehicle
    {
        return Vehicle::factory()->create(array_merge([
            'depreciation_monthly' => 26_000,
            'insurance_monthly' => 26_000,
            'repair_reserve_monthly' => 26_000,
            'maintenance_monthly' => 26_000,
            'estimated_daily_fuel_consumption' => 5_000,
            'working_days_per_month' => 26,
        ], $overrides));
    }

    // ── Vehicle cost account credits ─────────────────────────────────────────

    public function test_distribution_credits_vehicle_depreciation_account_with_daily_rate(): void
    {
        $vehicle = $this->createVehicle([
            'depreciation_monthly' => 26_000,
            'insurance_monthly' => 0,
            'repair_reserve_monthly' => 0,
            'maintenance_monthly' => 0,
            'estimated_daily_fuel_consumption' => 0,
            'working_days_per_month' => 26,
        ]);

        // Daily depreciation = 26_000 / 26 = 1_000
        $this->createMerchandiseSalesAccount(10_000);
        $date = Carbon::create(2026, 3, 25);

        $this->distributionService->distributeForDate($date);

        $depreciationAccount = Account::where('account_type', AccountType::VehicleDepreciation->value)
            ->where('vehicle_id', $vehicle->id)
            ->firstOrFail();

        $this->assertSame(1_000, $depreciationAccount->balance);
    }

    public function test_distribution_credits_vehicle_insurance_account_with_daily_rate(): void
    {
        $vehicle = $this->createVehicle([
            'depreciation_monthly' => 0,
            'insurance_monthly' => 26_000,
            'repair_reserve_monthly' => 0,
            'maintenance_monthly' => 0,
            'estimated_daily_fuel_consumption' => 0,
            'working_days_per_month' => 26,
        ]);

        $this->createMerchandiseSalesAccount(10_000);
        $date = Carbon::create(2026, 3, 25);

        $this->distributionService->distributeForDate($date);

        $insuranceAccount = Account::where('account_type', AccountType::VehicleInsurance->value)
            ->where('vehicle_id', $vehicle->id)
            ->firstOrFail();

        $this->assertSame(1_000, $insuranceAccount->balance);
    }

    public function test_distribution_credits_vehicle_repair_reserve_account_with_daily_rate(): void
    {
        $vehicle = $this->createVehicle([
            'depreciation_monthly' => 0,
            'insurance_monthly' => 0,
            'repair_reserve_monthly' => 26_000,
            'maintenance_monthly' => 0,
            'estimated_daily_fuel_consumption' => 0,
            'working_days_per_month' => 26,
        ]);

        $this->createMerchandiseSalesAccount(10_000);
        $date = Carbon::create(2026, 3, 25);

        $this->distributionService->distributeForDate($date);

        $repairAccount = Account::where('account_type', AccountType::VehicleRepairReserve->value)
            ->where('vehicle_id', $vehicle->id)
            ->firstOrFail();

        $this->assertSame(1_000, $repairAccount->balance);
    }

    public function test_distribution_credits_vehicle_maintenance_account_with_daily_rate(): void
    {
        $vehicle = $this->createVehicle([
            'depreciation_monthly' => 0,
            'insurance_monthly' => 0,
            'repair_reserve_monthly' => 0,
            'maintenance_monthly' => 26_000,
            'estimated_daily_fuel_consumption' => 0,
            'working_days_per_month' => 26,
        ]);

        $this->createMerchandiseSalesAccount(10_000);
        $date = Carbon::create(2026, 3, 25);

        $this->distributionService->distributeForDate($date);

        $maintenanceAccount = Account::where('account_type', AccountType::VehicleMaintenance->value)
            ->where('vehicle_id', $vehicle->id)
            ->firstOrFail();

        $this->assertSame(1_000, $maintenanceAccount->balance);
    }

    public function test_distribution_credits_vehicle_fuel_account_with_exact_daily_amount(): void
    {
        $vehicle = $this->createVehicle([
            'depreciation_monthly' => 0,
            'insurance_monthly' => 0,
            'repair_reserve_monthly' => 0,
            'maintenance_monthly' => 0,
            'estimated_daily_fuel_consumption' => 7_500,
            'working_days_per_month' => 26,
        ]);

        $this->createMerchandiseSalesAccount(20_000);
        $date = Carbon::create(2026, 3, 25);

        $this->distributionService->distributeForDate($date);

        $fuelAccount = Account::where('account_type', AccountType::VehicleFuel->value)
            ->where('vehicle_id', $vehicle->id)
            ->firstOrFail();

        // Fuel is NOT divided by working_days — it is the direct daily amount
        $this->assertSame(7_500, $fuelAccount->balance);
    }

    public function test_distribution_credits_all_five_vehicle_cost_accounts_simultaneously(): void
    {
        $vehicle = $this->createVehicle([
            'depreciation_monthly' => 26_000,
            'insurance_monthly' => 26_000,
            'repair_reserve_monthly' => 26_000,
            'maintenance_monthly' => 26_000,
            'estimated_daily_fuel_consumption' => 5_000,
            'working_days_per_month' => 26,
        ]);

        // Total per day: 4 × 1_000 + 5_000 = 9_000
        $this->createMerchandiseSalesAccount(20_000);
        $date = Carbon::create(2026, 3, 25);

        $this->distributionService->distributeForDate($date);

        $this->assertSame(1_000, Account::where('account_type', AccountType::VehicleDepreciation->value)->where('vehicle_id', $vehicle->id)->value('balance'));
        $this->assertSame(1_000, Account::where('account_type', AccountType::VehicleInsurance->value)->where('vehicle_id', $vehicle->id)->value('balance'));
        $this->assertSame(1_000, Account::where('account_type', AccountType::VehicleRepairReserve->value)->where('vehicle_id', $vehicle->id)->value('balance'));
        $this->assertSame(1_000, Account::where('account_type', AccountType::VehicleMaintenance->value)->where('vehicle_id', $vehicle->id)->value('balance'));
        $this->assertSame(5_000, Account::where('account_type', AccountType::VehicleFuel->value)->where('vehicle_id', $vehicle->id)->value('balance'));
    }

    // ── MERCHANDISE_SALES debit ───────────────────────────────────────────────

    public function test_merchandise_sales_account_is_debited_by_the_total_distributed(): void
    {
        $this->createVehicle([
            'depreciation_monthly' => 26_000,
            'insurance_monthly' => 26_000,
            'repair_reserve_monthly' => 26_000,
            'maintenance_monthly' => 26_000,
            'estimated_daily_fuel_consumption' => 5_000,
            'working_days_per_month' => 26,
        ]);

        // 4 × 1_000 + 5_000 = 9_000 per day
        $merchandiseAccount = $this->createMerchandiseSalesAccount(50_000);
        $date = Carbon::create(2026, 3, 25);

        $this->distributionService->distributeForDate($date);

        $merchandiseAccount->refresh();
        $this->assertSame(41_000, $merchandiseAccount->balance);
    }

    public function test_merchandise_sales_debit_equals_sum_of_all_vehicle_account_credits(): void
    {
        $vehicle1 = $this->createVehicle([
            'depreciation_monthly' => 26_000,
            'insurance_monthly' => 0,
            'repair_reserve_monthly' => 0,
            'maintenance_monthly' => 0,
            'estimated_daily_fuel_consumption' => 0,
            'working_days_per_month' => 26,
        ]);

        $vehicle2 = $this->createVehicle([
            'depreciation_monthly' => 52_000,
            'insurance_monthly' => 0,
            'repair_reserve_monthly' => 0,
            'maintenance_monthly' => 0,
            'estimated_daily_fuel_consumption' => 0,
            'working_days_per_month' => 26,
        ]);

        // vehicle1 daily depreciation: 1_000, vehicle2: 2_000 → total 3_000
        $merchandiseAccount = $this->createMerchandiseSalesAccount(10_000);
        $date = Carbon::create(2026, 3, 25);

        $this->distributionService->distributeForDate($date);

        $merchandiseAccount->refresh();
        $totalCredited = Account::where('account_type', AccountType::VehicleDepreciation->value)->sum('balance');

        $this->assertSame(3_000, $totalCredited);
        $this->assertSame(7_000, $merchandiseAccount->balance);
    }

    // ── Fixed costs ───────────────────────────────────────────────────────────

    public function test_distribution_credits_fixed_cost_account_with_daily_rate(): void
    {
        // No vehicle to isolate fixed cost behaviour
        MonthlyFixedCost::factory()->create([
            'amount' => 260_000,
            'label' => 'Loyer entrepôt',
            'period_year' => 2026,
            'period_month' => 3,
        ]);

        // daily_amount = round(260_000 / 26) = 10_000
        $this->createMerchandiseSalesAccount(20_000);
        $date = Carbon::create(2026, 3, 25);

        $this->distributionService->distributeForDate($date);

        $fixedCostAccount = Account::where('account_type', AccountType::FixedCost->value)
            ->where('name', 'Loyer entrepôt')
            ->firstOrFail();

        $this->assertSame(10_000, $fixedCostAccount->balance);
    }

    public function test_distribution_ignores_fixed_costs_from_other_months(): void
    {
        MonthlyFixedCost::factory()->create([
            'amount' => 260_000,
            'label' => 'Loyer entrepôt',
            'period_year' => 2026,
            'period_month' => 2, // February, not March
        ]);

        $this->createMerchandiseSalesAccount(10_000);
        $date = Carbon::create(2026, 3, 25);

        $this->expectException(\RuntimeException::class);
        // No costs at all → should throw "Aucun coût à distribuer"
        $this->distributionService->distributeForDate($date);
    }

    public function test_distribution_credits_multiple_fixed_cost_accounts(): void
    {
        MonthlyFixedCost::factory()->create([
            'amount' => 260_000,
            'label' => 'Loyer entrepôt',
            'period_year' => 2026,
            'period_month' => 3,
        ]);

        MonthlyFixedCost::factory()->create([
            'amount' => 130_000,
            'label' => 'Internet bureau',
            'period_year' => 2026,
            'period_month' => 3,
        ]);

        // daily: 10_000 + 5_000 = 15_000
        $this->createMerchandiseSalesAccount(30_000);
        $date = Carbon::create(2026, 3, 25);

        $this->distributionService->distributeForDate($date);

        $this->assertSame(10_000, Account::where('name', 'Loyer entrepôt')->value('balance'));
        $this->assertSame(5_000, Account::where('name', 'Internet bureau')->value('balance'));
    }

    public function test_distribution_combines_vehicle_and_fixed_costs(): void
    {
        $this->createVehicle([
            'depreciation_monthly' => 26_000,
            'insurance_monthly' => 0,
            'repair_reserve_monthly' => 0,
            'maintenance_monthly' => 0,
            'estimated_daily_fuel_consumption' => 0,
            'working_days_per_month' => 26,
        ]);

        MonthlyFixedCost::factory()->create([
            'amount' => 26_000,
            'label' => 'Loyer entrepôt',
            'period_year' => 2026,
            'period_month' => 3,
        ]);

        // vehicle daily: 1_000, fixed daily: 1_000 → total: 2_000
        $merchandiseAccount = $this->createMerchandiseSalesAccount(10_000);
        $date = Carbon::create(2026, 3, 25);

        $this->distributionService->distributeForDate($date);

        $merchandiseAccount->refresh();
        $this->assertSame(8_000, $merchandiseAccount->balance);
    }

    // ── Multiple vehicles ─────────────────────────────────────────────────────

    public function test_each_vehicle_gets_its_own_separate_cost_accounts(): void
    {
        $vehicle1 = $this->createVehicle([
            'name' => 'Camion Alpha',
            'depreciation_monthly' => 26_000,
            'insurance_monthly' => 0,
            'repair_reserve_monthly' => 0,
            'maintenance_monthly' => 0,
            'estimated_daily_fuel_consumption' => 0,
            'working_days_per_month' => 26,
        ]);

        $vehicle2 = $this->createVehicle([
            'name' => 'Camion Beta',
            'depreciation_monthly' => 52_000,
            'insurance_monthly' => 0,
            'repair_reserve_monthly' => 0,
            'maintenance_monthly' => 0,
            'estimated_daily_fuel_consumption' => 0,
            'working_days_per_month' => 26,
        ]);

        $this->createMerchandiseSalesAccount(20_000);
        $date = Carbon::create(2026, 3, 25);

        $this->distributionService->distributeForDate($date);

        $alpha = Account::where('account_type', AccountType::VehicleDepreciation->value)->where('vehicle_id', $vehicle1->id)->firstOrFail();
        $beta = Account::where('account_type', AccountType::VehicleDepreciation->value)->where('vehicle_id', $vehicle2->id)->firstOrFail();

        $this->assertSame(1_000, $alpha->balance);
        $this->assertSame(2_000, $beta->balance);
    }

    // ── DailyCostDistribution record ─────────────────────────────────────────

    public function test_distribution_creates_a_daily_cost_distribution_record(): void
    {
        $this->createVehicle();
        $this->createMerchandiseSalesAccount(50_000);
        $date = Carbon::create(2026, 3, 25);

        $distribution = $this->distributionService->distributeForDate($date);

        $this->assertSame(1, DailyCostDistribution::whereDate('distribution_date', '2026-03-25')->count());
        $this->assertSame($distribution->id, DailyCostDistribution::whereDate('distribution_date', '2026-03-25')->firstOrFail()->id);
    }

    public function test_distribution_record_stores_correct_total_amount_distributed(): void
    {
        $this->createVehicle([
            'depreciation_monthly' => 26_000,
            'insurance_monthly' => 26_000,
            'repair_reserve_monthly' => 0,
            'maintenance_monthly' => 0,
            'estimated_daily_fuel_consumption' => 0,
            'working_days_per_month' => 26,
        ]);

        // 2 × 1_000 = 2_000
        $this->createMerchandiseSalesAccount(10_000);
        $date = Carbon::create(2026, 3, 25);

        $distribution = $this->distributionService->distributeForDate($date);

        $this->assertSame(2_000, $distribution->total_amount_distributed);
    }

    // ── Idempotency / double-distribution prevention ──────────────────────────

    public function test_distributing_twice_for_the_same_date_throws_a_runtime_exception(): void
    {
        $this->createVehicle();
        $this->createMerchandiseSalesAccount(100_000);
        $date = Carbon::create(2026, 3, 25);

        $this->distributionService->distributeForDate($date);

        $this->expectException(\RuntimeException::class);
        $this->distributionService->distributeForDate($date);
    }

    public function test_second_distribution_attempt_does_not_change_any_balance(): void
    {
        $this->createVehicle([
            'depreciation_monthly' => 26_000,
            'insurance_monthly' => 0,
            'repair_reserve_monthly' => 0,
            'maintenance_monthly' => 0,
            'estimated_daily_fuel_consumption' => 0,
            'working_days_per_month' => 26,
        ]);

        $merchandiseAccount = $this->createMerchandiseSalesAccount(50_000);
        $date = Carbon::create(2026, 3, 25);

        $this->distributionService->distributeForDate($date);
        $balanceAfterFirst = $merchandiseAccount->fresh()->balance;

        try {
            $this->distributionService->distributeForDate($date);
        } catch (\RuntimeException) {
            // Expected
        }

        $this->assertSame($balanceAfterFirst, $merchandiseAccount->fresh()->balance);
    }

    public function test_different_dates_can_each_be_distributed_independently(): void
    {
        $this->createVehicle([
            'depreciation_monthly' => 26_000,
            'insurance_monthly' => 0,
            'repair_reserve_monthly' => 0,
            'maintenance_monthly' => 0,
            'estimated_daily_fuel_consumption' => 0,
            'working_days_per_month' => 26,
        ]);

        $merchandiseAccount = $this->createMerchandiseSalesAccount(100_000);
        $dateA = Carbon::create(2026, 3, 24);
        $dateB = Carbon::create(2026, 3, 25);

        $this->distributionService->distributeForDate($dateA);
        $this->distributionService->distributeForDate($dateB);

        // Two distributions of 1_000 each → total debit of 2_000
        $merchandiseAccount->refresh();
        $this->assertSame(98_000, $merchandiseAccount->balance);
    }

    // ── has_already_been_distributed helper ──────────────────────────────────

    public function test_has_already_been_distributed_returns_false_when_no_record_exists(): void
    {
        $date = Carbon::create(2026, 3, 25);
        $this->assertFalse($this->distributionService->hasAlreadyBeenDistributedForDate($date));
    }

    public function test_has_already_been_distributed_returns_true_after_distribution(): void
    {
        $this->createVehicle();
        $this->createMerchandiseSalesAccount(100_000);
        $date = Carbon::create(2026, 3, 25);

        $this->distributionService->distributeForDate($date);

        $this->assertTrue($this->distributionService->hasAlreadyBeenDistributedForDate($date));
    }

    // ── Caisse isolation ──────────────────────────────────────────────────────

    public function test_distribution_does_not_change_any_caisse_balance(): void
    {
        $mainCaisse = Caisse::create([
            'name' => 'Caisse principale',
            'balance' => 500_000,
            'closed' => false,
        ]);

        $this->createVehicle();
        $this->createMerchandiseSalesAccount(100_000);
        $date = Carbon::create(2026, 3, 25);

        $this->distributionService->distributeForDate($date);

        $mainCaisse->refresh();
        $this->assertSame(500_000, $mainCaisse->balance);
    }

    public function test_total_account_balance_is_unchanged_after_distribution(): void
    {
        $this->createVehicle();
        $this->createMerchandiseSalesAccount(100_000);
        $date = Carbon::create(2026, 3, 25);

        $totalBefore = Account::sum('balance');

        $this->distributionService->distributeForDate($date);

        $totalAfter = Account::sum('balance');

        // Pure account-to-account move — sum must stay constant
        $this->assertSame($totalBefore, $totalAfter);
    }

    // ── Insufficient balance ──────────────────────────────────────────────────

    public function test_distribution_throws_when_merchandise_sales_balance_is_insufficient(): void
    {
        $this->createVehicle([
            'depreciation_monthly' => 260_000,
            'insurance_monthly' => 0,
            'repair_reserve_monthly' => 0,
            'maintenance_monthly' => 0,
            'estimated_daily_fuel_consumption' => 0,
            'working_days_per_month' => 26,
        ]);

        // Daily depreciation = 10_000 but account only has 5_000
        $this->createMerchandiseSalesAccount(5_000);
        $date = Carbon::create(2026, 3, 25);

        $this->expectException(InsufficientAccountBalanceException::class);
        $this->distributionService->distributeForDate($date);
    }

    public function test_distribution_rolls_back_completely_when_balance_is_insufficient(): void
    {
        $this->createVehicle([
            'depreciation_monthly' => 260_000,
            'insurance_monthly' => 0,
            'repair_reserve_monthly' => 0,
            'maintenance_monthly' => 0,
            'estimated_daily_fuel_consumption' => 0,
            'working_days_per_month' => 26,
        ]);

        $this->createMerchandiseSalesAccount(5_000);
        $date = Carbon::create(2026, 3, 25);

        try {
            $this->distributionService->distributeForDate($date);
        } catch (InsufficientAccountBalanceException) {
            // Expected
        }

        // No DailyCostDistribution record should exist
        $this->assertSame(0, DailyCostDistribution::count());

        // No vehicle account should have been created
        $this->assertSame(0, Account::where('account_type', AccountType::VehicleDepreciation->value)->count());
    }

    // ── No costs scenario ─────────────────────────────────────────────────────

    public function test_distribution_throws_when_no_costs_exist_at_all(): void
    {
        // No vehicles, no monthly fixed costs
        $this->createMerchandiseSalesAccount(100_000);
        $date = Carbon::create(2026, 3, 25);

        $this->expectException(\RuntimeException::class);
        $this->distributionService->distributeForDate($date);
    }

    // ── Rounding precision ────────────────────────────────────────────────────

    public function test_daily_rate_rounds_correctly_when_monthly_amount_is_not_divisible(): void
    {
        $this->createVehicle([
            'depreciation_monthly' => 27_000, // 27_000 / 26 = 1_038.46… → rounds to 1_038
            'insurance_monthly' => 0,
            'repair_reserve_monthly' => 0,
            'maintenance_monthly' => 0,
            'estimated_daily_fuel_consumption' => 0,
            'working_days_per_month' => 26,
        ]);

        $this->createMerchandiseSalesAccount(10_000);
        $date = Carbon::create(2026, 3, 25);

        $this->distributionService->distributeForDate($date);

        $depreciationAccount = Account::where('account_type', AccountType::VehicleDepreciation->value)->firstOrFail();
        $expectedDailyRate = (int) round(27_000 / 26);
        $this->assertSame($expectedDailyRate, $depreciationAccount->balance);
    }

    public function test_fixed_cost_daily_rate_rounds_correctly(): void
    {
        MonthlyFixedCost::factory()->create([
            'amount' => 100_001, // not divisible by 26
            'label' => 'Abonnement',
            'period_year' => 2026,
            'period_month' => 3,
        ]);

        $this->createMerchandiseSalesAccount(10_000);
        $date = Carbon::create(2026, 3, 25);

        $this->distributionService->distributeForDate($date);

        $fixedCostAccount = Account::where('name', 'Abonnement')->firstOrFail();
        $expectedDailyRate = (int) round(100_001 / 26);
        $this->assertSame($expectedDailyRate, $fixedCostAccount->balance);
    }

    // ── working_days_per_month edge case ──────────────────────────────────────

    public function test_vehicle_with_zero_working_days_defaults_to_one_to_avoid_division_by_zero(): void
    {
        $vehicle = $this->createVehicle([
            'depreciation_monthly' => 26_000,
            'insurance_monthly' => 0,
            'repair_reserve_monthly' => 0,
            'maintenance_monthly' => 0,
            'estimated_daily_fuel_consumption' => 0,
            'working_days_per_month' => 0, // edge case
        ]);

        // When working_days = 0, service uses max(1, working_days) → 26_000 / 1 = 26_000
        $this->createMerchandiseSalesAccount(100_000);
        $date = Carbon::create(2026, 3, 25);

        $this->distributionService->distributeForDate($date);

        $depreciationAccount = Account::where('account_type', AccountType::VehicleDepreciation->value)
            ->where('vehicle_id', $vehicle->id)
            ->firstOrFail();

        $this->assertSame(26_000, $depreciationAccount->balance);
    }

    // ── Global invariant ──────────────────────────────────────────────────────

    public function test_global_invariant_holds_after_distribution_with_caisse_present(): void
    {
        // Seed a caisse and a matching account balance to bootstrap the invariant
        $mainCaisse = Caisse::create(['name' => 'Caisse principale', 'balance' => 100_000, 'closed' => false]);
        $merchandiseAccount = $this->createMerchandiseSalesAccount(100_000);

        $this->assertGlobalInvariantHolds();

        $this->createVehicle([
            'depreciation_monthly' => 26_000,
            'insurance_monthly' => 26_000,
            'repair_reserve_monthly' => 0,
            'maintenance_monthly' => 0,
            'estimated_daily_fuel_consumption' => 0,
            'working_days_per_month' => 26,
        ]);

        $this->distributionService->distributeForDate(Carbon::create(2026, 3, 25));

        // Distribution is pure account-to-account, so invariant must still hold
        $this->assertGlobalInvariantHolds();
    }

    public function test_account_transaction_records_are_created_for_each_credit(): void
    {
        $vehicle = $this->createVehicle([
            'depreciation_monthly' => 26_000,
            'insurance_monthly' => 26_000,
            'repair_reserve_monthly' => 0,
            'maintenance_monthly' => 0,
            'estimated_daily_fuel_consumption' => 0,
            'working_days_per_month' => 26,
        ]);

        $this->createMerchandiseSalesAccount(10_000);
        $date = Carbon::create(2026, 3, 25);

        $distribution = $this->distributionService->distributeForDate($date);

        // 1 debit on MERCHANDISE_SALES + 2 credits (depreciation + insurance) = 3 transactions
        $this->assertDatabaseHas('account_transactions', [
            'reference_type' => 'DAILY_DISTRIBUTION',
            'reference_id' => $distribution->id,
        ]);

        $transactionCount = \App\Models\AccountTransaction::where('reference_type', 'DAILY_DISTRIBUTION')
            ->where('reference_id', $distribution->id)
            ->count();

        $this->assertSame(3, $transactionCount);
    }
}
