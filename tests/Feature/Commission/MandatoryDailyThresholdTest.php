<?php

namespace Tests\Feature\Commission;

use App\Enums\CarLoadStatus;
use App\Models\CarLoad;
use App\Models\Commercial;
use App\Models\CommercialWorkPeriod;
use App\Models\Customer;
use App\Models\DailyCommission;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Vente;
use App\Services\Abc\AbcVehicleCostService;
use App\Services\Commission\CommissionCalculatorService;
use App\Services\Commission\CommissionRateResolverService;
use App\Services\Commission\DailyCommissionService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the mandatory daily threshold feature.
 *
 * The threshold is the minimum invoiced daily sales revenue that a commercial
 * must reach so that the margin from those sales covers the car load's
 * daily operating cost.
 *
 * Formula: threshold = ceil(daily_total_cost / average_margin_rate)
 * Example: cost 15 000 XOF, margin 30 % → threshold = ceil(15000 / 0.30) = 50 000 XOF
 *
 * Covers:
 *  1. computeMandatoryDailyThresholdForWorkDay() — direct unit tests
 *  2. computeAverageMarginRateFromAllSales() — margin rate computation
 *  3. recalculateDailyCommissionForWorkDay() — storage of threshold fields
 *  4. getDailyCommissionSummary() — DTO reflects stored threshold
 *  5. getCommissionSummaryForDateRange() — per-day and period-level threshold counts
 */
class MandatoryDailyThresholdTest extends TestCase
{
    use RefreshDatabase;

    private string $workDay = '2026-03-03';

    private User $user;

    private Commercial $commercial;

    private Team $team;

    private CommercialWorkPeriod $workPeriod;

    private Customer $customer;

    private DailyCommissionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DailyCommissionService(
            new CommissionCalculatorService(new CommissionRateResolverService),
            new AbcVehicleCostService,
        );

        $this->user = User::factory()->create();

        $this->team = Team::create([
            'name' => 'Équipe Threshold Test',
            'user_id' => $this->user->id,
        ]);

        $this->commercial = Commercial::create([
            'name' => 'Commercial Threshold',
            'phone_number' => '221700000099',
            'gender' => 'male',
            'user_id' => $this->user->id,
        ]);

        $this->commercial->team_id = $this->team->id;
        $this->commercial->save();

        $this->workPeriod = CommercialWorkPeriod::create([
            'commercial_id' => $this->commercial->id,
            'period_start_date' => '2026-03-01',
            'period_end_date' => '2026-03-31',
        ]);

        $this->customer = Customer::create([
            'name' => 'Client Threshold',
            'phone_number' => '221700000098',
            'owner_number' => '221700000098',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Creates a Vehicle whose daily fixed cost = insurance_monthly / working_days_per_month.
     * With insurance_monthly = 450 000 and working_days_per_month = 30 → daily cost = 15 000 XOF.
     */
    private function createVehicleWithKnownDailyCost(int $insuranceMonthly = 450_000, int $workingDaysPerMonth = 30): Vehicle
    {
        return Vehicle::create([
            'name' => 'Véhicule Test',
            'plate_number' => 'SN-TEST-001',
            'insurance_monthly' => $insuranceMonthly,
            'maintenance_monthly' => 0,
            'repair_reserve_monthly' => 0,
            'depreciation_monthly' => 0,
            'driver_salary_monthly' => 0,
            'working_days_per_month' => $workingDaysPerMonth,
        ]);
    }

    /**
     * Creates a CarLoad active on the given workDay and linked to the commercial's team.
     * The CarLoad's fixed_daily_cost is automatically set from the vehicle's monthly config.
     */
    private function createActiveCarLoad(?Vehicle $vehicle = null): CarLoad
    {
        return CarLoad::create([
            'name' => 'Chargement Threshold',
            'load_date' => Carbon::parse($this->workDay)->subDay(),
            'return_date' => Carbon::parse($this->workDay)->addDays(10),
            'team_id' => $this->team->id,
            'vehicle_id' => $vehicle?->id,
            'status' => CarLoadStatus::Selling,
        ]);
    }

    /**
     * Creates a SalesInvoice with a Vente so that total_amount = price × quantity.
     * The Vente profit is set explicitly.
     */
    private function createSalesInvoiceWithVente(int $price, int $quantity, int $profit): SalesInvoice
    {
        $product = Product::create([
            'name' => 'Produit '.$price,
            'price' => $price,
            'cost_price' => $price - (int) round($profit / $quantity),
        ]);

        $invoice = SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'status' => 'DRAFT',
        ]);

        Vente::create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $price,
            'profit' => $profit,
            'type' => Vente::TYPE_INVOICE,
        ]);

        return $invoice->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Section 1 — computeAverageMarginRateFromAllSales()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_average_margin_rate_returns_null_when_no_sales_exist(): void
    {
        $thresholdData = $this->service->computeMandatoryDailyThresholdForWorkDay(
            $this->commercial,
            $this->workDay,
        );

        // No Ventes exist → margin rate cannot be computed → threshold and margin both null/0
        $this->assertSame(0, $thresholdData['threshold']);
        $this->assertNull($thresholdData['margin_rate']);
    }

    public function test_average_margin_rate_is_computed_globally_from_all_ventes(): void
    {
        Carbon::setTestNow($this->workDay);
        $vehicle = $this->createVehicleWithKnownDailyCost();
        $this->createActiveCarLoad($vehicle);

        // Two ventes: total revenue = 1000 + 2000 = 3000, total profit = 300 + 400 = 700
        // margin rate = 700 / 3000 ≈ 0.2333...
        Vente::create([
            'product_id' => Product::create(['name' => 'P1', 'price' => 1000, 'cost_price' => 700])->id,
            'quantity' => 1,
            'price' => 1000,
            'profit' => 300,
            'type' => Vente::TYPE_INVOICE,
        ]);

        Vente::create([
            'product_id' => Product::create(['name' => 'P2', 'price' => 2000, 'cost_price' => 1600])->id,
            'quantity' => 1,
            'price' => 2000,
            'profit' => 400,
            'type' => Vente::TYPE_INVOICE,
        ]);

        $thresholdData = $this->service->computeMandatoryDailyThresholdForWorkDay(
            $this->commercial,
            $this->workDay,
        );

        $expectedMarginRate = 700 / 3000;
        $expectedThreshold = (int) ceil(15_000 / $expectedMarginRate);

        $this->assertEqualsWithDelta($expectedMarginRate, $thresholdData['margin_rate'], 0.0001);
        $this->assertSame($expectedThreshold, $thresholdData['threshold']);
    }

    public function test_average_margin_rate_returns_null_when_total_revenue_is_zero(): void
    {
        Carbon::setTestNow($this->workDay);
        $vehicle = $this->createVehicleWithKnownDailyCost();
        $this->createActiveCarLoad($vehicle);

        // Vente with price=0 → total_revenue = 0, margin rate cannot be computed
        Vente::create([
            'product_id' => Product::create(['name' => 'P0', 'price' => 0, 'cost_price' => 0])->id,
            'quantity' => 1,
            'price' => 0,
            'profit' => 0,
            'type' => Vente::TYPE_INVOICE,
        ]);

        $thresholdData = $this->service->computeMandatoryDailyThresholdForWorkDay(
            $this->commercial,
            $this->workDay,
        );

        $this->assertSame(0, $thresholdData['threshold']);
        $this->assertNull($thresholdData['margin_rate']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Section 2 — computeMandatoryDailyThresholdForWorkDay()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_threshold_is_zero_when_no_active_car_load_exists_for_the_commercial_team(): void
    {
        // No CarLoad created — no active trip for this team on workDay
        $thresholdData = $this->service->computeMandatoryDailyThresholdForWorkDay(
            $this->commercial,
            $this->workDay,
        );

        $this->assertSame(0, $thresholdData['threshold']);
        $this->assertNull($thresholdData['margin_rate']);
    }

    public function test_threshold_is_zero_when_car_load_has_no_vehicle_and_therefore_zero_daily_cost(): void
    {
        Carbon::setTestNow($this->workDay);

        // CarLoad without vehicle → no fixed_daily_cost, no expenses → daily cost = 0
        $this->createActiveCarLoad(vehicle: null);

        Vente::create([
            'product_id' => Product::create(['name' => 'Prod', 'price' => 1000, 'cost_price' => 700])->id,
            'quantity' => 1,
            'price' => 1000,
            'profit' => 300,
            'type' => Vente::TYPE_INVOICE,
        ]);

        $thresholdData = $this->service->computeMandatoryDailyThresholdForWorkDay(
            $this->commercial,
            $this->workDay,
        );

        $this->assertSame(0, $thresholdData['threshold']);
        $this->assertNull($thresholdData['margin_rate']);
    }

    public function test_threshold_is_zero_when_commercial_has_no_team(): void
    {
        $commercialWithNoTeam = Commercial::create([
            'name' => 'Commercial Sans Equipe',
            'phone_number' => '221700000097',
            'gender' => 'male',
            'user_id' => $this->user->id,
        ]);

        $thresholdData = $this->service->computeMandatoryDailyThresholdForWorkDay(
            $commercialWithNoTeam,
            $this->workDay,
        );

        $this->assertSame(0, $thresholdData['threshold']);
        $this->assertNull($thresholdData['margin_rate']);
    }

    public function test_threshold_car_load_is_not_found_when_load_date_is_after_work_day(): void
    {
        Carbon::setTestNow($this->workDay);
        $vehicle = $this->createVehicleWithKnownDailyCost();

        // CarLoad whose load_date is AFTER workDay — should NOT be found
        CarLoad::create([
            'name' => 'Futur Chargement',
            'load_date' => Carbon::parse($this->workDay)->addDay(),
            'return_date' => Carbon::parse($this->workDay)->addDays(10),
            'team_id' => $this->team->id,
            'vehicle_id' => $vehicle->id,
            'status' => CarLoadStatus::Selling,
        ]);

        Vente::create([
            'product_id' => Product::create(['name' => 'Prod', 'price' => 1000, 'cost_price' => 700])->id,
            'quantity' => 1,
            'price' => 1000,
            'profit' => 300,
            'type' => Vente::TYPE_INVOICE,
        ]);

        $thresholdData = $this->service->computeMandatoryDailyThresholdForWorkDay(
            $this->commercial,
            $this->workDay,
        );

        $this->assertSame(0, $thresholdData['threshold']);
    }

    public function test_threshold_car_load_is_not_found_when_returned_before_work_day(): void
    {
        Carbon::setTestNow($this->workDay);
        $vehicle = $this->createVehicleWithKnownDailyCost();

        // CarLoad that was returned BEFORE workDay — should NOT be found
        CarLoad::create([
            'name' => 'Chargement Terminé',
            'load_date' => Carbon::parse($this->workDay)->subDays(5),
            'return_date' => Carbon::parse($this->workDay)->subDay(),
            'team_id' => $this->team->id,
            'vehicle_id' => $vehicle->id,
            'status' => CarLoadStatus::TerminatedAndTransferred,
        ]);

        Vente::create([
            'product_id' => Product::create(['name' => 'Prod', 'price' => 1000, 'cost_price' => 700])->id,
            'quantity' => 1,
            'price' => 1000,
            'profit' => 300,
            'type' => Vente::TYPE_INVOICE,
        ]);

        $thresholdData = $this->service->computeMandatoryDailyThresholdForWorkDay(
            $this->commercial,
            $this->workDay,
        );

        $this->assertSame(0, $thresholdData['threshold']);
    }

    public function test_threshold_correct_formula_with_30_percent_margin_and_15000_daily_cost(): void
    {
        // Freeze time to workDay so trip duration = 1 day → daily_total_cost = fixed_daily_cost = 15 000
        Carbon::setTestNow($this->workDay);

        // insurance_monthly = 450 000, working_days = 30 → fixed_daily_cost = 15 000 XOF
        $vehicle = $this->createVehicleWithKnownDailyCost(insuranceMonthly: 450_000, workingDaysPerMonth: 30);
        $this->createActiveCarLoad($vehicle);

        // price = 1000, profit = 300 → margin = 300/1000 = 30%
        Vente::create([
            'product_id' => Product::create(['name' => 'Prod30pct', 'price' => 1000, 'cost_price' => 700])->id,
            'quantity' => 1,
            'price' => 1000,
            'profit' => 300,
            'type' => Vente::TYPE_INVOICE,
        ]);

        $thresholdData = $this->service->computeMandatoryDailyThresholdForWorkDay(
            $this->commercial,
            $this->workDay,
        );

        // threshold = ceil(15 000 / 0.30) = ceil(50 000) = 50 000
        $this->assertSame(50_000, $thresholdData['threshold']);
        $this->assertEqualsWithDelta(0.30, $thresholdData['margin_rate'], 0.0001);
    }

    public function test_threshold_uses_ceil_rounding_upward(): void
    {
        Carbon::setTestNow($this->workDay);

        // insurance_monthly = 10 000, working_days = 30 → fixed_daily_cost = round(333.33) = 333 XOF
        $vehicle = $this->createVehicleWithKnownDailyCost(insuranceMonthly: 10_000, workingDaysPerMonth: 30);
        $this->createActiveCarLoad($vehicle);

        // Margin exactly 1/3
        Vente::create([
            'product_id' => Product::create(['name' => 'Prod1_3', 'price' => 3, 'cost_price' => 2])->id,
            'quantity' => 1,
            'price' => 3,
            'profit' => 1,
            'type' => Vente::TYPE_INVOICE,
        ]);

        $thresholdData = $this->service->computeMandatoryDailyThresholdForWorkDay(
            $this->commercial,
            $this->workDay,
        );

        // fixed_daily_cost = round(10 000 / 30) = round(333.33) = 333
        // daily_total_cost = 333 (trip duration = 1 day, no expenses)
        // threshold = ceil(333 / (1/3)) = ceil(999) = 999
        $this->assertSame(999, $thresholdData['threshold']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Section 3 — recalculateDailyCommissionForWorkDay() stores threshold fields
    // ─────────────────────────────────────────────────────────────────────────

    public function test_threshold_fields_are_stored_on_daily_commission_after_recalculation(): void
    {
        Carbon::setTestNow($this->workDay);

        $vehicle = $this->createVehicleWithKnownDailyCost();
        $this->createActiveCarLoad($vehicle);

        Vente::create([
            'product_id' => Product::create(['name' => 'ProdMargin', 'price' => 1000, 'cost_price' => 700])->id,
            'quantity' => 1,
            'price' => 1000,
            'profit' => 300,
            'type' => Vente::TYPE_INVOICE,
        ]);

        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial,
            $this->workPeriod,
            $this->workDay,
        );

        $this->assertSame(50_000, $dailyCommission->mandatory_daily_threshold);
        $this->assertEqualsWithDelta(0.30, (float) $dailyCommission->cached_average_margin_rate, 0.0001);
    }

    public function test_mandatory_threshold_reached_is_true_when_daily_sales_exactly_meet_threshold(): void
    {
        Carbon::setTestNow($this->workDay);

        $vehicle = $this->createVehicleWithKnownDailyCost();
        $this->createActiveCarLoad($vehicle);

        // Invoice total_amount = 10 000 × 5 = 50 000 XOF (exactly meets threshold).
        // Invoice Vente has 30 % margin: profit = 10 000 × 5 × 0.30 = 15 000.
        // Average margin = 15 000 / 50 000 = 30 % → threshold = ceil(15 000 / 0.30) = 50 000.
        $this->createSalesInvoiceWithVente(price: 10_000, quantity: 5, profit: 15_000);

        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial,
            $this->workPeriod,
            $this->workDay,
        );

        $this->assertSame(50_000, $dailyCommission->mandatory_daily_threshold);
        $this->assertTrue($dailyCommission->mandatory_threshold_reached);
    }

    public function test_mandatory_threshold_reached_is_true_when_daily_sales_exceed_threshold(): void
    {
        Carbon::setTestNow($this->workDay);

        $vehicle = $this->createVehicleWithKnownDailyCost();
        $this->createActiveCarLoad($vehicle);

        // Invoice total_amount = 10 000 × 8 = 80 000 XOF (above threshold).
        // 30 % margin → threshold = 50 000; 80 000 > 50 000 → reached.
        $this->createSalesInvoiceWithVente(price: 10_000, quantity: 8, profit: 24_000);

        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial,
            $this->workPeriod,
            $this->workDay,
        );

        $this->assertTrue($dailyCommission->mandatory_threshold_reached);
    }

    public function test_mandatory_threshold_reached_is_false_when_daily_sales_are_below_threshold(): void
    {
        Carbon::setTestNow($this->workDay);

        $vehicle = $this->createVehicleWithKnownDailyCost();
        $this->createActiveCarLoad($vehicle);

        // Invoice total_amount = 10 000 × 3 = 30 000 XOF (below the 50 000 threshold).
        // 30 % margin → threshold = 50 000; 30 000 < 50 000 → NOT reached.
        $this->createSalesInvoiceWithVente(price: 10_000, quantity: 3, profit: 9_000);

        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial,
            $this->workPeriod,
            $this->workDay,
        );

        $this->assertSame(50_000, $dailyCommission->mandatory_daily_threshold);
        $this->assertFalse($dailyCommission->mandatory_threshold_reached);
    }

    public function test_mandatory_threshold_reached_is_true_when_no_active_car_load_exists(): void
    {
        // No CarLoad → threshold = 0 → trivially reached (no cost to cover)
        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial,
            $this->workPeriod,
            $this->workDay,
        );

        $this->assertSame(0, $dailyCommission->mandatory_daily_threshold);
        $this->assertTrue($dailyCommission->mandatory_threshold_reached);
        $this->assertNull($dailyCommission->cached_average_margin_rate);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Section 4 — getDailyCommissionSummary() reads threshold from stored record
    // ─────────────────────────────────────────────────────────────────────────

    public function test_daily_summary_dto_includes_threshold_fields_from_stored_daily_commission(): void
    {
        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => $this->workDay,
            'base_commission' => 500,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 500,
            'mandatory_daily_threshold' => 50_000,
            'mandatory_threshold_reached' => false,
            'cached_average_margin_rate' => 0.30,
        ]);

        $summary = $this->service->getDailyCommissionSummary($this->commercial, $this->workDay);

        $this->assertSame(50_000, $summary->mandatoryDailyThreshold);
        $this->assertFalse($summary->mandatoryThresholdReached);
        $this->assertEqualsWithDelta(0.30, $summary->cachedAverageMarginRate, 0.0001);
    }

    public function test_daily_summary_dto_returns_zero_threshold_and_true_reached_when_no_commission_record(): void
    {
        // DailyCommission record exists for work period but not for this specific day
        $summary = $this->service->getDailyCommissionSummary($this->commercial, $this->workDay);

        $this->assertSame(0, $summary->mandatoryDailyThreshold);
        $this->assertTrue($summary->mandatoryThresholdReached);
        $this->assertNull($summary->cachedAverageMarginRate);
    }

    public function test_daily_summary_dto_returns_zero_threshold_when_no_work_period_exists(): void
    {
        $commercialWithNoPeriod = Commercial::create([
            'name' => 'Commercial Sans Période',
            'phone_number' => '221700000096',
            'gender' => 'male',
            'user_id' => $this->user->id,
        ]);

        $summary = $this->service->getDailyCommissionSummary($commercialWithNoPeriod, $this->workDay);

        $this->assertSame(0, $summary->mandatoryDailyThreshold);
        $this->assertTrue($summary->mandatoryThresholdReached);
        $this->assertNull($summary->cachedAverageMarginRate);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Section 5 — getCommissionSummaryForDateRange() threshold counts and per-day fields
    // ─────────────────────────────────────────────────────────────────────────

    public function test_period_summary_includes_threshold_fields_in_per_day_entries(): void
    {
        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-03',
            'base_commission' => 500,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 500,
            'mandatory_daily_threshold' => 50_000,
            'mandatory_threshold_reached' => false,
            'cached_average_margin_rate' => 0.30,
        ]);

        $summary = $this->service->getCommissionSummaryForDateRange(
            $this->commercial,
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-04'),
        );

        // 2026-03-03 is index 1 (Mon=0, Tue=1, Wed=2)
        $tuesdayEntry = $summary->days[1];

        $this->assertSame('2026-03-03', $tuesdayEntry['date']);
        $this->assertSame(50_000, $tuesdayEntry['mandatory_daily_threshold']);
        $this->assertFalse($tuesdayEntry['mandatory_threshold_reached']);
        $this->assertEqualsWithDelta(0.30, $tuesdayEntry['cached_average_margin_rate'], 0.0001);
    }

    public function test_period_summary_zero_fills_threshold_fields_for_days_without_commission_records(): void
    {
        // No DailyCommission records — all days should have zero-filled threshold fields
        $summary = $this->service->getCommissionSummaryForDateRange(
            $this->commercial,
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-04'),
        );

        foreach ($summary->days as $dayEntry) {
            $this->assertSame(0, $dayEntry['mandatory_daily_threshold']);
            $this->assertTrue($dayEntry['mandatory_threshold_reached']);
            $this->assertNull($dayEntry['cached_average_margin_rate']);
        }
    }

    public function test_total_days_threshold_reached_counts_days_where_threshold_was_met(): void
    {
        // Day 1: threshold met
        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-02',
            'base_commission' => 0,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 0,
            'mandatory_daily_threshold' => 50_000,
            'mandatory_threshold_reached' => true,
            'cached_average_margin_rate' => 0.30,
        ]);

        // Day 2: threshold NOT met
        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-03',
            'base_commission' => 0,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 0,
            'mandatory_daily_threshold' => 50_000,
            'mandatory_threshold_reached' => false,
            'cached_average_margin_rate' => 0.30,
        ]);

        // Day 3: threshold met
        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-04',
            'base_commission' => 0,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 0,
            'mandatory_daily_threshold' => 50_000,
            'mandatory_threshold_reached' => true,
            'cached_average_margin_rate' => 0.30,
        ]);

        $summary = $this->service->getCommissionSummaryForDateRange(
            $this->commercial,
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-08'),
        );

        $this->assertSame(2, $summary->totalDaysThresholdReached);
        $this->assertSame(1, $summary->totalDaysBelowThreshold);
    }

    public function test_total_days_threshold_counts_only_days_with_a_positive_threshold(): void
    {
        // Day with threshold = 0 (no active car load) should NOT be counted in either bucket
        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-02',
            'base_commission' => 0,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 0,
            'mandatory_daily_threshold' => 0,
            'mandatory_threshold_reached' => true,
            'cached_average_margin_rate' => null,
        ]);

        // Day with threshold > 0 that was reached
        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-03',
            'base_commission' => 0,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 0,
            'mandatory_daily_threshold' => 50_000,
            'mandatory_threshold_reached' => true,
            'cached_average_margin_rate' => 0.30,
        ]);

        $summary = $this->service->getCommissionSummaryForDateRange(
            $this->commercial,
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-08'),
        );

        // Only days with threshold > 0 are counted
        $this->assertSame(1, $summary->totalDaysThresholdReached);
        $this->assertSame(0, $summary->totalDaysBelowThreshold);
    }

    public function test_total_threshold_counts_are_zero_when_no_daily_commissions_exist(): void
    {
        $summary = $this->service->getCommissionSummaryForDateRange(
            $this->commercial,
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-08'),
        );

        $this->assertSame(0, $summary->totalDaysThresholdReached);
        $this->assertSame(0, $summary->totalDaysBelowThreshold);
    }
}
