<?php

namespace Tests\Feature\Commission;

use App\Data\Commission\CommissionPeriodSummaryData;
use App\Enums\CarLoadItemSource;
use App\Enums\CarLoadStatus;
use App\Models\CarLoad;
use App\Models\CarLoadItem;
use App\Models\Commercial;
use App\Models\CommercialObjectiveTier;
use App\Models\CommercialProductCommissionRate;
use App\Models\CommercialWorkPeriod;
use App\Models\Customer;
use App\Models\DailyCommission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Team;
use App\Models\User;
use App\Services\Abc\AbcVehicleCostService;
use App\Services\Commission\CommissionCalculatorService;
use App\Services\Commission\CommissionRateResolverService;
use App\Services\Commission\DailyCommissionService;
use App\Services\SalesInvoiceStatsService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for CommissionPeriodSummaryData (DTO) and the two API endpoints:
 *  - GET /api/salesperson/weekly-commissions
 *  - GET /api/salesperson/monthly-commissions
 *
 * Split into four sections:
 *  1. DTO unit-level tests (construction, toArray shape, field mapping)
 *  2. Service method tests for getCommissionSummaryForDateRange
 *  3. Weekly endpoint integration tests
 *  4. Monthly endpoint integration tests
 */
class CommissionPeriodSummaryTest extends TestCase
{
    use RefreshDatabase;

    // A Monday–Sunday week entirely inside March 2026
    private string $weekMonday = '2026-03-02';

    private string $weekSunday = '2026-03-08';

    private User $user;

    private Commercial $commercial;

    private CommercialWorkPeriod $workPeriod;

    private Product $productAlm;

    private Product $productJet;

    private Customer $customer;

    private DailyCommissionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DailyCommissionService(
            new CommissionCalculatorService(new CommissionRateResolverService),
            new AbcVehicleCostService,
            new SalesInvoiceStatsService(new CommissionRateResolverService),
        );

        $this->user = User::factory()->create();

        $team = Team::create([
            'name' => 'Équipe Test',
            'user_id' => $this->user->id,
        ]);

        $this->commercial = Commercial::create([
            'name' => 'Commercial Test',
            'phone_number' => '221700000001',
            'gender' => 'male',
            'user_id' => $this->user->id,
        ]);

        $this->commercial->team_id = $team->id;
        $this->commercial->save();

        // Work period spans all of March 2026.
        $this->workPeriod = CommercialWorkPeriod::create([
            'commercial_id' => $this->commercial->id,
            'period_start_date' => '2026-03-01',
            'period_end_date' => '2026-03-31',
            'is_finalized' => false,
        ]);

        $categoryA = ProductCategory::create(['name' => 'ALM']);
        $categoryB = ProductCategory::create(['name' => 'JET']);

        $this->productAlm = Product::create([
            'name' => 'Produit ALM',
            'price' => 1000,
            'cost_price' => 600,
            'product_category_id' => $categoryA->id,
        ]);

        $this->productJet = Product::create([
            'name' => 'Produit JET',
            'price' => 2000,
            'cost_price' => 1200,
            'product_category_id' => $categoryB->id,
        ]);

        // 10 % commission on both products.
        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $this->productAlm->id,
            'rate' => 0.10,
        ]);
        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $this->productJet->id,
            'rate' => 0.10,
        ]);

        $this->customer = Customer::create([
            'name' => 'Client Test',
            'phone_number' => '221700000002',
            'owner_number' => '221700000002',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // always reset frozen time after each test
        parent::tearDown();
    }

    // ─── Helper: give the commercial a sellable CarLoad ────────────────────────

    private function createCarLoadWithStock(): void
    {
        $carLoad = CarLoad::create([
            'name' => 'Chargement Test',
            'load_date' => now(),
            'return_date' => Carbon::parse('2026-12-31'),
            'team_id' => $this->commercial->team_id,
            'status' => CarLoadStatus::Selling,
        ]);

        foreach ([$this->productAlm, $this->productJet] as $product) {
            CarLoadItem::create([
                'car_load_id' => $carLoad->id,
                'product_id' => $product->id,
                'quantity_loaded' => 999,
                'quantity_left' => 999,
                'cost_price_per_unit' => $product->cost_price,
                'source' => CarLoadItemSource::Warehouse,
                'loaded_at' => now(),
            ]);
        }
    }

    /**
     * POST a sale through the real salesperson API, creating a SalesInvoice + Payment,
     * which triggers RecalculateDailyCommissionJob synchronously via model events.
     *
     * @param  array<array{product_id: int, quantity: int, price: int}>  $items
     */
    private function postSale(array $items): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/salesperson/sales-invoices', [
            'customer_id' => $this->customer->id,
            'items' => $items,
            'paid' => true,
            'payment_method' => 'Cash',
        ])->assertSuccessful();
    }

    // ─── Section 1: DTO unit tests ─────────────────────────────────────────────

    public function test_dto_can_be_constructed_with_all_fields(): void
    {
        $dto = new CommissionPeriodSummaryData(
            startDate: '2026-03-02',
            endDate: '2026-03-08',
            mandatoryDailySales: 10000,
            totalPayments: 8000,
            baseCommission: 800,
            commissionsEarned: 950,
            totalPenalties: 50,
            tierBonus: 100,
            basketBonus: 100,
            totalNewConfirmedCustomersCount: 5,
            totalNewProspectCustomersCount: 3,
            totalNewConfirmedCustomersBonus: 2_500,
            totalNewProspectCustomersBonus: 900,
            totalDaysThresholdReached: 3,
            totalDaysBelowThreshold: 1,
            days: [],
        );

        $this->assertSame('2026-03-02', $dto->startDate);
        $this->assertSame('2026-03-08', $dto->endDate);
        $this->assertSame(10000, $dto->mandatoryDailySales);
        $this->assertSame(8000, $dto->totalPayments);
        $this->assertSame(800, $dto->baseCommission);
        $this->assertSame(950, $dto->commissionsEarned);
        $this->assertSame(50, $dto->totalPenalties);
        $this->assertSame(100, $dto->tierBonus);
        $this->assertSame(100, $dto->basketBonus);
        $this->assertSame(5, $dto->totalNewConfirmedCustomersCount);
        $this->assertSame(3, $dto->totalNewProspectCustomersCount);
        $this->assertSame(2_500, $dto->totalNewConfirmedCustomersBonus);
        $this->assertSame(900, $dto->totalNewProspectCustomersBonus);
        $this->assertSame([], $dto->days);
    }

    public function test_dto_to_array_returns_sixteen_expected_keys(): void
    {
        $dto = new CommissionPeriodSummaryData(
            startDate: '2026-03-02',
            endDate: '2026-03-08',
            mandatoryDailySales: 0,
            totalPayments: 0,
            baseCommission: 0,
            commissionsEarned: 0,
            totalPenalties: 0,
            tierBonus: 0,
            basketBonus: 0,
            totalNewConfirmedCustomersCount: 0,
            totalNewProspectCustomersCount: 0,
            totalNewConfirmedCustomersBonus: 0,
            totalNewProspectCustomersBonus: 0,
            totalDaysThresholdReached: 0,
            totalDaysBelowThreshold: 0,
            days: [],
        );

        $array = $dto->toArray();

        $this->assertCount(16, $array);
        $this->assertArrayHasKey('start_date', $array);
        $this->assertArrayHasKey('end_date', $array);
        $this->assertArrayHasKey('mandatory_daily_sales', $array);
        $this->assertArrayHasKey('total_payments', $array);
        $this->assertArrayHasKey('base_commission', $array);
        $this->assertArrayHasKey('commissions_earned', $array);
        $this->assertArrayHasKey('total_penalties', $array);
        $this->assertArrayHasKey('tier_bonus', $array);
        $this->assertArrayHasKey('basket_bonus', $array);
        $this->assertArrayHasKey('total_new_confirmed_customers_count', $array);
        $this->assertArrayHasKey('total_new_prospect_customers_count', $array);
        $this->assertArrayHasKey('total_new_confirmed_customers_bonus', $array);
        $this->assertArrayHasKey('total_new_prospect_customers_bonus', $array);
        $this->assertArrayHasKey('total_days_threshold_reached', $array);
        $this->assertArrayHasKey('total_days_below_threshold', $array);
        $this->assertArrayHasKey('days', $array);
    }

    public function test_dto_to_array_maps_values_correctly(): void
    {
        $days = [
            ['date' => '2026-03-02', 'mandatory_daily_sales' => 5000, 'total_payments' => 5000,
                'commissions_earned' => 500, 'total_penalties' => 0, 'tier_bonus' => 0,
                'reached_tier_level' => null, 'basket_bonus' => 0,
                'new_confirmed_customers_count' => 0, 'new_prospect_customers_count' => 0,
                'new_confirmed_customers_bonus' => 0, 'new_prospect_customers_bonus' => 0],
        ];

        $dto = new CommissionPeriodSummaryData(
            startDate: '2026-03-02',
            endDate: '2026-03-08',
            mandatoryDailySales: 5000,
            totalPayments: 5000,
            baseCommission: 500,
            commissionsEarned: 500,
            totalPenalties: 0,
            tierBonus: 0,
            basketBonus: 0,
            totalNewConfirmedCustomersCount: 0,
            totalNewProspectCustomersCount: 0,
            totalNewConfirmedCustomersBonus: 0,
            totalNewProspectCustomersBonus: 0,
            totalDaysThresholdReached: 0,
            totalDaysBelowThreshold: 0,
            days: $days,
        );

        $array = $dto->toArray();

        $this->assertSame('2026-03-02', $array['start_date']);
        $this->assertSame('2026-03-08', $array['end_date']);
        $this->assertSame(5000, $array['mandatory_daily_sales']);
        $this->assertSame(5000, $array['total_payments']);
        $this->assertSame(500, $array['base_commission']);
        $this->assertSame(500, $array['commissions_earned']);
        $this->assertSame(0, $array['total_penalties']);
        $this->assertSame(0, $array['tier_bonus']);
        $this->assertSame(0, $array['basket_bonus']);
        $this->assertSame(0, $array['total_new_confirmed_customers_count']);
        $this->assertSame(0, $array['total_new_prospect_customers_count']);
        $this->assertSame(0, $array['total_new_confirmed_customers_bonus']);
        $this->assertSame(0, $array['total_new_prospect_customers_bonus']);
        $this->assertSame(0, $array['total_days_threshold_reached']);
        $this->assertSame(0, $array['total_days_below_threshold']);
        $this->assertSame($days, $array['days']);
    }

    // ─── Section 2: Service getCommissionSummaryForDateRange tests ─────────────

    public function test_service_returns_all_zeros_for_commercial_with_no_data(): void
    {
        $summary = $this->service->getCommissionSummaryForDateRange(
            $this->commercial,
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-08'),
        );

        $this->assertSame(0, $summary->mandatoryDailySales);
        $this->assertSame(0, $summary->totalPayments);
        $this->assertSame(0, $summary->baseCommission);
        $this->assertSame(0, $summary->commissionsEarned);
        $this->assertSame(0, $summary->totalPenalties);
        $this->assertSame(0, $summary->tierBonus);
        $this->assertSame(0, $summary->basketBonus);
    }

    public function test_service_days_array_spans_full_date_range_even_with_no_activity(): void
    {
        $summary = $this->service->getCommissionSummaryForDateRange(
            $this->commercial,
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-08'),
        );

        // 7 days: Mon 02 → Sun 08 inclusive.
        $this->assertCount(7, $summary->days);
        $this->assertSame('2026-03-02', $summary->days[0]['date']);
        $this->assertSame('2026-03-08', $summary->days[6]['date']);
    }

    public function test_service_zero_fills_inactive_days_in_days_array(): void
    {
        $summary = $this->service->getCommissionSummaryForDateRange(
            $this->commercial,
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-04'),
        );

        foreach ($summary->days as $dayEntry) {
            $this->assertSame(0, $dayEntry['mandatory_daily_sales']);
            $this->assertSame(0, $dayEntry['total_payments']);
            $this->assertSame(0, $dayEntry['commissions_earned']);
            $this->assertNull($dayEntry['reached_tier_level']);
        }
    }

    public function test_service_aggregates_commissions_from_multiple_daily_commission_records(): void
    {
        // Manually seed two DailyCommission records on different days.
        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-03',
            'base_commission' => 300,
            'basket_bonus' => 30,
            'objective_bonus' => 50,
            'total_penalties' => 10,
            'net_commission' => 370,
        ]);
        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-04',
            'base_commission' => 200,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 200,
        ]);

        $summary = $this->service->getCommissionSummaryForDateRange(
            $this->commercial,
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-08'),
        );

        $this->assertSame(500, $summary->baseCommission);
        $this->assertSame(570, $summary->commissionsEarned);
        $this->assertSame(10, $summary->totalPenalties);
        $this->assertSame(50, $summary->tierBonus);
        $this->assertSame(30, $summary->basketBonus);
    }

    public function test_service_days_array_carries_correct_values_for_active_days(): void
    {
        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-05',
            'base_commission' => 400,
            'basket_bonus' => 40,
            'objective_bonus' => 100,
            'total_penalties' => 20,
            'net_commission' => 520,
            'achieved_tier_level' => 2,
        ]);

        $summary = $this->service->getCommissionSummaryForDateRange(
            $this->commercial,
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-08'),
        );

        // Day index 3 is 2026-03-05 (Mon=0, Tue=1, Wed=2, Thu=3).
        $thursdayEntry = $summary->days[3];

        $this->assertSame('2026-03-05', $thursdayEntry['date']);
        $this->assertSame(520, $thursdayEntry['commissions_earned']);
        $this->assertSame(20, $thursdayEntry['total_penalties']);
        $this->assertSame(100, $thursdayEntry['tier_bonus']);
        $this->assertSame(40, $thursdayEntry['basket_bonus']);
        $this->assertSame(2, $thursdayEntry['reached_tier_level']);
    }

    public function test_service_returns_instance_of_commission_period_summary_data(): void
    {
        $summary = $this->service->getCommissionSummaryForDateRange(
            $this->commercial,
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-08'),
        );

        $this->assertInstanceOf(CommissionPeriodSummaryData::class, $summary);
    }

    public function test_service_weekly_summary_spans_monday_to_sunday(): void
    {
        // Wednesday inside the week.
        $summary = $this->service->getWeeklyCommissionSummary($this->commercial, '2026-03-04');

        $this->assertSame('2026-03-02', $summary->startDate); // Monday
        $this->assertSame('2026-03-08', $summary->endDate);   // Sunday
        $this->assertCount(7, $summary->days);
    }

    public function test_service_monthly_summary_spans_first_to_last_day_of_month(): void
    {
        $summary = $this->service->getMonthlyCommissionSummary($this->commercial, '2026-03-15');

        $this->assertSame('2026-03-01', $summary->startDate);
        $this->assertSame('2026-03-31', $summary->endDate);
        $this->assertCount(31, $summary->days);
    }

    public function test_service_monthly_summary_handles_february_correctly(): void
    {
        // 2026 is not a leap year — February has 28 days.
        $summary = $this->service->getMonthlyCommissionSummary($this->commercial, '2026-02-10');

        $this->assertSame('2026-02-01', $summary->startDate);
        $this->assertSame('2026-02-28', $summary->endDate);
        $this->assertCount(28, $summary->days);
    }

    // ─── Section 3: Weekly endpoint integration tests ─────────────────────────

    public function test_weekly_endpoint_returns_401_when_unauthenticated(): void
    {
        $this->getJson('/api/salesperson/weekly-commissions')->assertUnauthorized();
    }

    public function test_weekly_endpoint_returns_404_when_user_has_no_commercial_profile(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/salesperson/weekly-commissions')->assertNotFound();
    }

    public function test_weekly_endpoint_returns_200_with_expected_keys(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/salesperson/weekly-commissions?date=2026-03-04');

        $response->assertOk()->assertJsonStructure([
            'start_date',
            'end_date',
            'mandatory_daily_sales',
            'total_payments',
            'base_commission',
            'commissions_earned',
            'total_penalties',
            'tier_bonus',
            'basket_bonus',
            'total_new_confirmed_customers_count',
            'total_new_prospect_customers_count',
            'total_new_confirmed_customers_bonus',
            'total_new_prospect_customers_bonus',
            'total_days_threshold_reached',
            'total_days_below_threshold',
            'days',
        ]);
    }

    public function test_weekly_endpoint_returns_zeros_and_seven_days_when_no_activity(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/salesperson/weekly-commissions?date=2026-03-04');

        $response->assertOk()
            ->assertJsonPath('start_date', '2026-03-02')
            ->assertJsonPath('end_date', '2026-03-08')
            ->assertJsonPath('mandatory_daily_sales', 0)
            ->assertJsonPath('total_payments', 0)
            ->assertJsonPath('commissions_earned', 0);

        $this->assertCount(7, $response->json('days'));
    }

    public function test_weekly_endpoint_defaults_to_current_week_when_no_date_given(): void
    {
        Carbon::setTestNow('2026-03-04'); // Wednesday

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/salesperson/weekly-commissions');

        $response->assertOk()
            ->assertJsonPath('start_date', '2026-03-02')
            ->assertJsonPath('end_date', '2026-03-08');

        Carbon::setTestNow();
    }

    public function test_weekly_endpoint_accumulates_commissions_across_multiple_days_of_the_week(): void
    {
        $this->createCarLoadWithStock();

        Carbon::setTestNow('2026-03-02');
        $this->postSale([['product_id' => $this->productAlm->id, 'quantity' => 1, 'price' => 1000]]);

        Carbon::setTestNow('2026-03-04');
        $this->postSale([['product_id' => $this->productJet->id, 'quantity' => 1, 'price' => 2000]]);

        Carbon::setTestNow();

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/salesperson/weekly-commissions?date=2026-03-04');

        $response->assertOk();
        // 10 % on 1000 + 10 % on 2000 = 100 + 200 = 300
        $this->assertSame(300, $response->json('commissions_earned'));
        $this->assertSame(3000, $response->json('mandatory_daily_sales'));
        $this->assertSame(3000, $response->json('total_payments'));
    }

    public function test_weekly_endpoint_days_array_correctly_assigns_each_sale_to_its_day(): void
    {
        $this->createCarLoadWithStock();

        Carbon::setTestNow('2026-03-02');
        $this->postSale([['product_id' => $this->productAlm->id, 'quantity' => 1, 'price' => 1000]]);

        Carbon::setTestNow('2026-03-05');
        $this->postSale([['product_id' => $this->productJet->id, 'quantity' => 1, 'price' => 2000]]);

        Carbon::setTestNow();

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/salesperson/weekly-commissions?date=2026-03-04');

        $days = $response->json('days');

        // Monday (index 0) = 100, Thursday (index 3) = 200, others = 0
        $this->assertSame(100, $days[0]['commissions_earned']); // Monday 2026-03-02
        $this->assertSame(0, $days[1]['commissions_earned']);   // Tuesday 2026-03-03
        $this->assertSame(200, $days[3]['commissions_earned']); // Thursday 2026-03-05
    }

    public function test_weekly_endpoint_excludes_sales_from_other_weeks(): void
    {
        $this->createCarLoadWithStock();

        // Sale in the previous week.
        Carbon::setTestNow('2026-02-23');
        $this->postSale([['product_id' => $this->productAlm->id, 'quantity' => 1, 'price' => 1000]]);

        Carbon::setTestNow();

        Sanctum::actingAs($this->user);

        // Query the week of 2026-03-04 (Mon 02 – Sun 08).
        $response = $this->getJson('/api/salesperson/weekly-commissions?date=2026-03-04');

        $response->assertOk()
            ->assertJsonPath('commissions_earned', 0)
            ->assertJsonPath('mandatory_daily_sales', 0);
    }

    public function test_weekly_endpoint_includes_penalties_in_the_period_total(): void
    {
        // Seed a penalty on Tuesday 2026-03-03.
        $this->workPeriod->penalties()->create([
            'work_day' => '2026-03-03',
            'amount' => 500,
            'reason' => 'Late arrival',
        ]);

        // Seed a DailyCommission reflecting the penalty.
        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-03',
            'base_commission' => 1000,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 500,
            'net_commission' => 500,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/salesperson/weekly-commissions?date=2026-03-04');

        $response->assertOk()
            ->assertJsonPath('total_penalties', 500)
            ->assertJsonPath('commissions_earned', 500);
    }

    // ─── Section 4: Monthly endpoint integration tests ────────────────────────

    public function test_monthly_endpoint_returns_401_when_unauthenticated(): void
    {
        $this->getJson('/api/salesperson/monthly-commissions')->assertUnauthorized();
    }

    public function test_monthly_endpoint_returns_404_when_user_has_no_commercial_profile(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/salesperson/monthly-commissions')->assertNotFound();
    }

    public function test_monthly_endpoint_returns_200_with_expected_keys(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/salesperson/monthly-commissions?date=2026-03-15');

        $response->assertOk()->assertJsonStructure([
            'start_date',
            'end_date',
            'mandatory_daily_sales',
            'total_payments',
            'base_commission',
            'commissions_earned',
            'total_penalties',
            'tier_bonus',
            'basket_bonus',
            'total_new_confirmed_customers_count',
            'total_new_prospect_customers_count',
            'total_new_confirmed_customers_bonus',
            'total_new_prospect_customers_bonus',
            'total_days_threshold_reached',
            'total_days_below_threshold',
            'days',
        ]);
    }

    public function test_monthly_endpoint_returns_31_days_for_march(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/salesperson/monthly-commissions?date=2026-03-15');

        $response->assertOk()
            ->assertJsonPath('start_date', '2026-03-01')
            ->assertJsonPath('end_date', '2026-03-31');

        $this->assertCount(31, $response->json('days'));
    }

    public function test_monthly_endpoint_defaults_to_current_month_when_no_date_given(): void
    {
        Carbon::setTestNow('2026-03-15');

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/salesperson/monthly-commissions');

        $response->assertOk()
            ->assertJsonPath('start_date', '2026-03-01')
            ->assertJsonPath('end_date', '2026-03-31');

        Carbon::setTestNow();
    }

    public function test_monthly_endpoint_accumulates_commissions_across_multiple_weeks(): void
    {
        $this->createCarLoadWithStock();

        // Week 1: Monday 2026-03-02
        Carbon::setTestNow('2026-03-02');
        $this->postSale([['product_id' => $this->productAlm->id, 'quantity' => 1, 'price' => 1000]]);

        // Week 2: Monday 2026-03-09
        Carbon::setTestNow('2026-03-09');
        $this->postSale([['product_id' => $this->productJet->id, 'quantity' => 2, 'price' => 2000]]);

        Carbon::setTestNow();

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/salesperson/monthly-commissions?date=2026-03-15');

        $response->assertOk();
        // 10 % on 1000 + 10 % on 4000 = 100 + 400 = 500
        $this->assertSame(500, $response->json('commissions_earned'));
        // 1 × 1000 (productAlm) + 2 × 2000 (productJet) = 5000
        $this->assertSame(5000, $response->json('mandatory_daily_sales'));
    }

    public function test_monthly_endpoint_excludes_sales_from_other_months(): void
    {
        $this->createCarLoadWithStock();

        // Sale in February (outside March 2026).
        Carbon::setTestNow('2026-02-20');
        $this->postSale([['product_id' => $this->productAlm->id, 'quantity' => 1, 'price' => 1000]]);

        Carbon::setTestNow();

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/salesperson/monthly-commissions?date=2026-03-15');

        $response->assertOk()
            ->assertJsonPath('commissions_earned', 0)
            ->assertJsonPath('mandatory_daily_sales', 0);
    }

    public function test_monthly_endpoint_tier_bonus_is_summed_across_all_days(): void
    {
        // Add an objective tier that fires at 3000 collected.
        CommercialObjectiveTier::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'tier_level' => 1,
            'ca_threshold' => 3000,
            'bonus_amount' => 150,
        ]);

        $this->createCarLoadWithStock();

        // Day 1: pay 4000 → hits tier, earns 150 tier bonus.
        Carbon::setTestNow('2026-03-03');
        $this->postSale([['product_id' => $this->productJet->id, 'quantity' => 2, 'price' => 2000]]);

        // Day 2: pay 4000 again → hits tier again, earns another 150.
        Carbon::setTestNow('2026-03-10');
        $this->postSale([['product_id' => $this->productJet->id, 'quantity' => 2, 'price' => 2000]]);

        Carbon::setTestNow();

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/salesperson/monthly-commissions?date=2026-03-15');

        $response->assertOk();
        $this->assertSame(300, $response->json('tier_bonus')); // 150 × 2 days
    }

    public function test_monthly_endpoint_mandatory_daily_sales_counts_unpaid_invoices(): void
    {
        $this->createCarLoadWithStock();

        Carbon::setTestNow('2026-03-05');

        Sanctum::actingAs($this->user);

        // Create an unpaid invoice (credit sale — requires should_be_paid_at).
        $this->postJson('/api/salesperson/sales-invoices', [
            'customer_id' => $this->customer->id,
            'items' => [['product_id' => $this->productAlm->id, 'quantity' => 1, 'price' => 1000]],
            'paid' => false,
            'should_be_paid_at' => today()->addDays(7)->toDateString(),
        ])->assertSuccessful();

        Carbon::setTestNow();

        $response = $this->getJson('/api/salesperson/monthly-commissions?date=2026-03-15');

        // Invoice is 1000 XOF; since no payment occurred, totalPayments stays 0
        // but mandatoryDailySales reflects the invoiced amount.
        $response->assertOk()
            ->assertJsonPath('mandatory_daily_sales', 1000)
            ->assertJsonPath('total_payments', 0);
    }
}
