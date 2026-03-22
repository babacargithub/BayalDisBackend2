<?php

namespace Tests\Feature\Commission;

use App\Data\Commission\DailyCommissionSummaryData;
use App\Enums\CarLoadItemSource;
use App\Enums\CarLoadStatus;
use App\Models\CarLoad;
use App\Models\CarLoadItem;
use App\Models\Commercial;
use App\Models\CommercialObjectiveTier;
use App\Models\CommercialProductCommissionRate;
use App\Models\CommercialWorkPeriod;
use App\Models\CommissionPeriodSetting;
use App\Models\Customer;
use App\Models\DailyCommission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\User;
use App\Models\Vente;
use App\Services\Abc\AbcVehicleCostService;
use App\Services\Commission\CommissionCalculatorService;
use App\Services\Commission\CommissionRateResolverService;
use App\Services\Commission\DailyCommissionService;
use App\Services\SalesInvoiceStatsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for DailyCommissionSummaryData (DTO) and the API endpoint
 * GET /api/salesperson/daily-commission.
 *
 * Split into three sections:
 *  1. DTO unit-level tests (construction, toArray shape, field mapping)
 *  2. Service method tests (getDailyCommissionSummary via direct call)
 *  3. API endpoint integration tests (full HTTP, via salesperson API route)
 */
class DailyCommissionSummaryTest extends TestCase
{
    use RefreshDatabase;

    private string $periodStart = '2026-03-02';

    private string $periodEnd = '2026-03-07';

    private User $user;

    private Commercial $commercial;

    private CommercialWorkPeriod $weeklyWorkPeriod;

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

        $activeCarLoad = CarLoad::create([
            'name' => 'Chargement Test',
            'load_date' => now(),
            'return_date' => Carbon::parse('2026-12-31'),
            'team_id' => $team->id,
            'status' => CarLoadStatus::Selling,
        ]);

        $this->weeklyWorkPeriod = CommercialWorkPeriod::create([
            'commercial_id' => $this->commercial->id,
            'period_start_date' => $this->periodStart,
            'period_end_date' => $this->periodEnd,
        ]);

        $categoryAlm = ProductCategory::create(['name' => 'ALM']);
        $categoryJet = ProductCategory::create(['name' => 'JET']);

        $this->productAlm = Product::create([
            'name' => 'Produit ALM',
            'price' => 10_000,
            'cost_price' => 7_000,
            'product_category_id' => $categoryAlm->id,
        ]);

        $this->productJet = Product::create([
            'name' => 'Produit JET',
            'price' => 10_000,
            'cost_price' => 7_000,
            'product_category_id' => $categoryJet->id,
        ]);

        foreach ([$this->productAlm, $this->productJet] as $product) {
            CarLoadItem::create([
                'car_load_id' => $activeCarLoad->id,
                'product_id' => $product->id,
                'quantity_loaded' => 999,
                'quantity_left' => 999,
                'cost_price_per_unit' => $product->cost_price,
                'loaded_at' => now(),
                'source' => CarLoadItemSource::Warehouse,
            ]);
        }

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $this->productAlm->id,
            'rate' => 0.0100, // 1 %
        ]);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $this->productJet->id,
            'rate' => 0.0200, // 2 %
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
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DTO — unit-level tests
    // ─────────────────────────────────────────────────────────────────────────

    public function test_dto_can_be_constructed_with_all_fields(): void
    {
        $summaryData = new DailyCommissionSummaryData(
            mandatoryDailySales: 500_000,
            totalPayments: 350_000,
            commissionsEarned: 4_200,
            totalPenalties: 800,
            tierBonus: 15_000,
            reachedTierLevel: 2,
            basketBonus: 1_260,
            newConfirmedCustomersCount: 0,
            newProspectCustomersCount: 0,
            newConfirmedCustomersBonus: 0,
            newProspectCustomersBonus: 0,
            mandatoryDailyThreshold: 0,
            mandatoryThresholdReached: true,
            cachedAverageMarginRate: null,
        );

        $this->assertEquals(500_000, $summaryData->mandatoryDailySales);
        $this->assertEquals(350_000, $summaryData->totalPayments);
        $this->assertEquals(4_200, $summaryData->commissionsEarned);
        $this->assertEquals(800, $summaryData->totalPenalties);
        $this->assertEquals(15_000, $summaryData->tierBonus);
        $this->assertEquals(2, $summaryData->reachedTierLevel);
        $this->assertEquals(1_260, $summaryData->basketBonus);
    }

    public function test_dto_accepts_null_reached_tier_level_when_no_tier_was_achieved(): void
    {
        $summaryData = new DailyCommissionSummaryData(
            mandatoryDailySales: 0,
            totalPayments: 0,
            commissionsEarned: 0,
            totalPenalties: 0,
            tierBonus: 0,
            reachedTierLevel: null,
            basketBonus: 0,
            newConfirmedCustomersCount: 0,
            newProspectCustomersCount: 0,
            newConfirmedCustomersBonus: 0,
            newProspectCustomersBonus: 0,
            mandatoryDailyThreshold: 0,
            mandatoryThresholdReached: true,
            cachedAverageMarginRate: null,
        );

        $this->assertNull($summaryData->reachedTierLevel);
    }

    public function test_dto_to_array_returns_all_fourteen_keys_with_correct_snake_case_names(): void
    {
        $summaryData = new DailyCommissionSummaryData(
            mandatoryDailySales: 100,
            totalPayments: 200,
            commissionsEarned: 300,
            totalPenalties: 400,
            tierBonus: 500,
            reachedTierLevel: 1,
            basketBonus: 600,
            newConfirmedCustomersCount: 2,
            newProspectCustomersCount: 1,
            newConfirmedCustomersBonus: 1_000,
            newProspectCustomersBonus: 500,
            mandatoryDailyThreshold: 50_000,
            mandatoryThresholdReached: false,
            cachedAverageMarginRate: 0.30,
        );

        $array = $summaryData->toArray();

        $this->assertArrayHasKey('mandatory_daily_sales', $array);
        $this->assertArrayHasKey('total_payments', $array);
        $this->assertArrayHasKey('commissions_earned', $array);
        $this->assertArrayHasKey('total_penalties', $array);
        $this->assertArrayHasKey('tier_bonus', $array);
        $this->assertArrayHasKey('reached_tier_level', $array);
        $this->assertArrayHasKey('basket_bonus', $array);
        $this->assertArrayHasKey('new_confirmed_customers_count', $array);
        $this->assertArrayHasKey('new_prospect_customers_count', $array);
        $this->assertArrayHasKey('new_confirmed_customers_bonus', $array);
        $this->assertArrayHasKey('new_prospect_customers_bonus', $array);
        $this->assertArrayHasKey('mandatory_daily_threshold', $array);
        $this->assertArrayHasKey('mandatory_threshold_reached', $array);
        $this->assertArrayHasKey('cached_average_margin_rate', $array);
        $this->assertCount(14, $array);
    }

    public function test_dto_to_array_maps_values_correctly(): void
    {
        $summaryData = new DailyCommissionSummaryData(
            mandatoryDailySales: 500_000,
            totalPayments: 350_000,
            commissionsEarned: 4_200,
            totalPenalties: 800,
            tierBonus: 15_000,
            reachedTierLevel: 2,
            basketBonus: 1_260,
            newConfirmedCustomersCount: 3,
            newProspectCustomersCount: 1,
            newConfirmedCustomersBonus: 1_500,
            newProspectCustomersBonus: 300,
            mandatoryDailyThreshold: 50_000,
            mandatoryThresholdReached: true,
            cachedAverageMarginRate: 0.30,
        );

        $this->assertEquals([
            'mandatory_daily_sales' => 500_000,
            'total_payments' => 350_000,
            'commissions_earned' => 4_200,
            'total_penalties' => 800,
            'tier_bonus' => 15_000,
            'reached_tier_level' => 2,
            'basket_bonus' => 1_260,
            'new_confirmed_customers_count' => 3,
            'new_prospect_customers_count' => 1,
            'new_confirmed_customers_bonus' => 1_500,
            'new_prospect_customers_bonus' => 300,
            'mandatory_daily_threshold' => 50_000,
            'mandatory_threshold_reached' => true,
            'cached_average_margin_rate' => 0.30,
        ], $summaryData->toArray());
    }

    public function test_dto_to_array_preserves_null_reached_tier_level(): void
    {
        $summaryData = new DailyCommissionSummaryData(
            mandatoryDailySales: 0,
            totalPayments: 0,
            commissionsEarned: 0,
            totalPenalties: 0,
            tierBonus: 0,
            reachedTierLevel: null,
            basketBonus: 0,
            newConfirmedCustomersCount: 0,
            newProspectCustomersCount: 0,
            newConfirmedCustomersBonus: 0,
            newProspectCustomersBonus: 0,
            mandatoryDailyThreshold: 0,
            mandatoryThresholdReached: true,
            cachedAverageMarginRate: null,
        );

        $this->assertNull($summaryData->toArray()['reached_tier_level']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Service — getDailyCommissionSummary
    // ─────────────────────────────────────────────────────────────────────────

    public function test_service_returns_all_zeros_when_no_payments_and_no_invoices_exist_for_the_day(): void
    {
        $summary = $this->service->getDailyCommissionSummary($this->commercial, '2026-03-03');

        $this->assertEquals(0, $summary->mandatoryDailySales);
        $this->assertEquals(0, $summary->totalPayments);
        $this->assertEquals(0, $summary->commissionsEarned);
        $this->assertEquals(0, $summary->totalPenalties);
        $this->assertEquals(0, $summary->tierBonus);
        $this->assertNull($summary->reachedTierLevel);
        $this->assertEquals(0, $summary->basketBonus);
    }

    public function test_service_returns_all_zeros_when_payment_falls_outside_any_work_period(): void
    {
        $summary = $this->service->getDailyCommissionSummary($this->commercial, '2026-03-10');

        $this->assertEquals(0, $summary->commissionsEarned);
        $this->assertEquals(0, $summary->tierBonus);
        $this->assertNull($summary->reachedTierLevel);
    }

    public function test_service_returns_correct_mandatory_daily_sales_from_sales_invoices(): void
    {
        Carbon::setTestNow('2026-03-03');

        // Create two invoices (one fully paid, one unpaid) — both count towards mandatoryDailySales
        $invoice1 = SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'status' => 'DRAFT',
        ]);
        Vente::create([
            'sales_invoice_id' => $invoice1->id,
            'product_id' => $this->productAlm->id,
            'quantity' => 2,
            'price' => 10_000,
            'profit' => 0,
            'type' => Vente::TYPE_INVOICE,
        ]);

        $invoice2 = SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'status' => 'DRAFT',
        ]);
        Vente::create([
            'sales_invoice_id' => $invoice2->id,
            'product_id' => $this->productJet->id,
            'quantity' => 3,
            'price' => 10_000,
            'profit' => 0,
            'type' => Vente::TYPE_INVOICE,
        ]);

        $summary = $this->service->getDailyCommissionSummary($this->commercial, '2026-03-03');

        // invoice1: 2 × 10 000 = 20 000, invoice2: 3 × 10 000 = 30 000 → total 50 000
        $this->assertEquals(50_000, $summary->mandatoryDailySales);
    }

    public function test_service_returns_correct_total_payments_from_payment_records(): void
    {
        Carbon::setTestNow('2026-03-03');

        $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial,
            $this->weeklyWorkPeriod,
            '2026-03-03',
        );

        // Manually insert payments so we control created_at without going through the API
        $invoice = SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'status' => 'DRAFT',
        ]);

        \App\Models\Payment::create([
            'sales_invoice_id' => $invoice->id,
            'amount' => 80_000,
            'payment_method' => 'Cash',
            'user_id' => $this->user->id,
        ]);
        \App\Models\Payment::create([
            'sales_invoice_id' => $invoice->id,
            'amount' => 20_000,
            'payment_method' => 'Cash',
            'user_id' => $this->user->id,
        ]);

        $summary = $this->service->getDailyCommissionSummary($this->commercial, '2026-03-03');

        $this->assertEquals(100_000, $summary->totalPayments);
    }

    public function test_service_maps_daily_commission_record_fields_to_summary_correctly(): void
    {
        // Directly create a DailyCommission with known values to isolate the mapping logic
        DailyCommission::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'work_day' => '2026-03-03',
            'base_commission' => 2_000,
            'basket_bonus' => 600,
            'objective_bonus' => 15_000,
            'total_penalties' => 500,
            'net_commission' => 17_100, // 2_000 + 600 + 15_000 − 500
            'basket_achieved' => true,
            'basket_multiplier_applied' => 1.30,
            'achieved_tier_level' => 1,
        ]);

        $summary = $this->service->getDailyCommissionSummary($this->commercial, '2026-03-03');

        $this->assertEquals(17_100, $summary->commissionsEarned);
        $this->assertEquals(500, $summary->totalPenalties);
        $this->assertEquals(15_000, $summary->tierBonus);
        $this->assertEquals(1, $summary->reachedTierLevel);
        $this->assertEquals(600, $summary->basketBonus);
    }

    public function test_service_returns_null_reached_tier_level_when_no_tier_was_achieved(): void
    {
        DailyCommission::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'work_day' => '2026-03-03',
            'base_commission' => 100,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 100,
            'basket_achieved' => false,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => null,
        ]);

        $summary = $this->service->getDailyCommissionSummary($this->commercial, '2026-03-03');

        $this->assertNull($summary->reachedTierLevel);
        $this->assertEquals(0, $summary->tierBonus);
    }

    public function test_service_returns_dto_instance(): void
    {
        $summary = $this->service->getDailyCommissionSummary($this->commercial, '2026-03-03');

        $this->assertInstanceOf(DailyCommissionSummaryData::class, $summary);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // API endpoint — GET /api/salesperson/daily-commission
    // ─────────────────────────────────────────────────────────────────────────

    public function test_endpoint_returns_404_when_user_has_no_commercial_profile(): void
    {
        $userWithNoCommercial = User::factory()->create();

        Sanctum::actingAs($userWithNoCommercial);

        $this->getJson('/api/salesperson/daily-commission')
            ->assertStatus(404)
            ->assertJsonFragment(['message' => 'Aucun profil commercial lié à cet utilisateur.']);
    }

    public function test_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/salesperson/daily-commission')
            ->assertStatus(401);
    }

    public function test_endpoint_returns_200_with_all_keys(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/salesperson/daily-commission?date=2026-03-03')
            ->assertStatus(200)
            ->assertJsonStructure([
                'mandatory_daily_sales',
                'total_payments',
                'commissions_earned',
                'total_penalties',
                'tier_bonus',
                'reached_tier_level',
                'basket_bonus',
                'new_confirmed_customers_count',
                'new_prospect_customers_count',
                'new_confirmed_customers_bonus',
                'new_prospect_customers_bonus',
                'mandatory_daily_threshold',
                'mandatory_threshold_reached',
                'cached_average_margin_rate',
            ]);
    }

    public function test_endpoint_returns_zeros_when_no_sales_exist_for_the_day(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/salesperson/daily-commission?date=2026-03-03')
            ->assertStatus(200)
            ->assertExactJson([
                'mandatory_daily_sales' => 0,
                'total_payments' => 0,
                'commissions_earned' => 0,
                'total_penalties' => 0,
                'tier_bonus' => 0,
                'reached_tier_level' => null,
                'basket_bonus' => 0,
                'new_confirmed_customers_count' => 0,
                'new_prospect_customers_count' => 0,
                'new_confirmed_customers_bonus' => 0,
                'new_prospect_customers_bonus' => 0,
                'mandatory_daily_threshold' => 0,
                'mandatory_threshold_reached' => true,
                'cached_average_margin_rate' => null,
            ]);
    }

    public function test_endpoint_defaults_to_today_when_no_date_is_provided(): void
    {
        Carbon::setTestNow('2026-03-03');

        Sanctum::actingAs($this->user);

        // Create a DailyCommission record for today
        DailyCommission::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'work_day' => '2026-03-03',
            'base_commission' => 500,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 500,
            'basket_achieved' => false,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => null,
        ]);

        $this->getJson('/api/salesperson/daily-commission') // no date param
            ->assertStatus(200)
            ->assertJsonFragment(['commissions_earned' => 500]);
    }

    public function test_endpoint_returns_full_commission_breakdown_after_a_sale_is_made(): void
    {
        CommercialObjectiveTier::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'tier_level' => 1,
            'ca_threshold' => 100_000,
            'bonus_amount' => 15_000,
        ]);

        CommissionPeriodSetting::create([
            'period_start_date' => $this->periodStart,
            'period_end_date' => $this->periodEnd,
            'basket_multiplier' => 1.30,
            'required_category_ids' => [],
        ]);

        Carbon::setTestNow('2026-03-03');

        // Salesperson sells 10 × 10 000 = 100 000 XOF → hits tier 1
        // base_commission = 100 000 × 1 % = 1 000
        // tier_bonus = 15 000
        // net = 16 000
        Sanctum::actingAs($this->user);

        $this->postJson('/api/salesperson/sales-invoices', [
            'customer_id' => $this->customer->id,
            'items' => [
                ['product_id' => $this->productAlm->id, 'quantity' => 10, 'price' => 10_000],
            ],
            'paid' => true,
            'payment_method' => 'Cash',
        ])->assertStatus(201);

        $response = $this->getJson('/api/salesperson/daily-commission?date=2026-03-03')
            ->assertStatus(200);

        $response->assertJsonFragment([
            'mandatory_daily_sales' => 100_000,
            'total_payments' => 100_000,
            'commissions_earned' => 16_000,
            'total_penalties' => 0,
            'tier_bonus' => 15_000,
            'reached_tier_level' => 1,
            'basket_bonus' => 0,
        ]);
    }

    public function test_endpoint_reflects_penalty_deduction_in_commissions_earned(): void
    {
        Carbon::setTestNow('2026-03-03');

        // Create a daily commission directly then apply a penalty
        DailyCommission::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'work_day' => '2026-03-03',
            'base_commission' => 2_000,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 500,
            'net_commission' => 1_500,
            'basket_achieved' => false,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => null,
        ]);

        Sanctum::actingAs($this->user);

        $this->getJson('/api/salesperson/daily-commission?date=2026-03-03')
            ->assertStatus(200)
            ->assertJsonFragment([
                'commissions_earned' => 1_500,
                'total_penalties' => 500,
            ]);
    }

    public function test_endpoint_reflects_basket_bonus_when_all_required_categories_are_in_one_invoice(): void
    {
        Carbon::setTestNow('2026-03-03');

        // Pre-built DailyCommission record with basket achieved
        DailyCommission::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'work_day' => '2026-03-03',
            'base_commission' => 200,
            'basket_bonus' => 60,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 260,
            'basket_achieved' => true,
            'basket_multiplier_applied' => 1.30,
            'achieved_tier_level' => null,
        ]);

        Sanctum::actingAs($this->user);

        $this->getJson('/api/salesperson/daily-commission?date=2026-03-03')
            ->assertStatus(200)
            ->assertJsonFragment([
                'commissions_earned' => 260,
                'basket_bonus' => 60,
                'reached_tier_level' => null,
            ]);
    }

    public function test_mandatory_daily_sales_counts_unpaid_invoices_too(): void
    {
        Carbon::setTestNow('2026-03-03');

        // Invoice 1 — fully paid: 30 000
        $invoice1 = SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'status' => 'DRAFT',
        ]);
        Vente::create([
            'sales_invoice_id' => $invoice1->id,
            'product_id' => $this->productAlm->id,
            'quantity' => 3, 'price' => 10_000, 'profit' => 0,
            'type' => Vente::TYPE_INVOICE,
        ]);

        // Invoice 2 — unpaid debt: 20 000
        $invoice2 = SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'status' => 'DRAFT',
        ]);
        Vente::create([
            'sales_invoice_id' => $invoice2->id,
            'product_id' => $this->productJet->id,
            'quantity' => 2, 'price' => 10_000, 'profit' => 0,
            'type' => Vente::TYPE_INVOICE,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/salesperson/daily-commission?date=2026-03-03')
            ->assertStatus(200);

        // mandatory_daily_sales = 30 000 + 20 000 = 50 000 (both invoices regardless of payment)
        $this->assertEquals(50_000, $response->json('mandatory_daily_sales'));
        // total_payments = 0 (no payments made yet)
        $this->assertEquals(0, $response->json('total_payments'));
    }

    public function test_mandatory_daily_sales_and_total_payments_differ_when_invoice_is_partially_paid(): void
    {
        Carbon::setTestNow('2026-03-03');

        $invoice = SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'status' => 'DRAFT',
        ]);
        Vente::create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $this->productAlm->id,
            'quantity' => 10, 'price' => 10_000, 'profit' => 0,
            'type' => Vente::TYPE_INVOICE,
        ]);

        \App\Models\Payment::create([
            'sales_invoice_id' => $invoice->id,
            'amount' => 60_000, // partial payment
            'payment_method' => 'Cash',
            'user_id' => $this->user->id,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/salesperson/daily-commission?date=2026-03-03')
            ->assertStatus(200);

        $this->assertEquals(100_000, $response->json('mandatory_daily_sales')); // full invoice
        $this->assertEquals(60_000, $response->json('total_payments'));          // only paid portion
    }
}
