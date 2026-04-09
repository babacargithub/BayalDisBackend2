<?php

namespace Tests\Feature\Commission;

use App\Models\Commercial;
use App\Models\CommercialObjectiveTier;
use App\Models\CommercialPenalty;
use App\Models\CommercialProductCommissionRate;
use App\Models\CommercialWorkPeriod;
use App\Models\CommissionPaymentLine;
use App\Models\CommissionPeriodSetting;
use App\Models\Customer;
use App\Models\DailyCommission;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Vente;
use App\Services\Abc\CarLoadCostAggregatorService;
use App\Services\Abc\FixedCostCalculationAndDistributionService;
use App\Services\Abc\VehicleCostCalculatorService;
use App\Services\Commission\CommissionCalculatorService;
use App\Services\Commission\CommissionRateResolverService;
use App\Services\Commission\DailyCommissionService;
use App\Services\SalesInvoiceStatsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class DailyCommissionServiceTest extends TestCase
{
    use RefreshDatabase;

    private DailyCommissionService $service;

    private User $user;

    private Commercial $commercial;

    private CommercialWorkPeriod $weeklyWorkPeriod;

    /** Fixed weekly period: Mon 2 Mar → Sat 7 Mar 2026 */
    private string $periodStart = '2026-03-02';

    private string $periodEnd = '2026-03-07';

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DailyCommissionService(
            new CommissionCalculatorService(new CommissionRateResolverService),
            new CarLoadCostAggregatorService(
                new VehicleCostCalculatorService,
                new FixedCostCalculationAndDistributionService,
            ),
            new SalesInvoiceStatsService(new CommissionRateResolverService),
        );

        $this->user = User::factory()->create();
        $this->commercial = Commercial::create([
            'name' => 'Commercial Test',
            'phone_number' => '221700000001',
            'gender' => 'male',
            'user_id' => $this->user->id,
        ]);

        $this->weeklyWorkPeriod = CommercialWorkPeriod::create([
            'commercial_id' => $this->commercial->id,
            'period_start_date' => $this->periodStart,
            'period_end_date' => $this->periodEnd,
        ]);
    }

    private function makeCategory(string $name): ProductCategory
    {
        return ProductCategory::create(['name' => $name]);
    }

    private function makeProduct(?ProductCategory $category = null, int $price = 5_000): Product
    {
        return Product::create([
            'name' => 'Produit '.rand(1, 9999),
            'price' => $price,
            'cost_price' => 3_000,
            'product_category_id' => $category?->id,
        ]);
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'name' => 'Client Test',
            'phone_number' => '221'.rand(700000000, 799999999),
            'owner_number' => '221'.rand(700000000, 799999999),
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
        ]);
    }

    /**
     * Creates an invoice with one product and a full payment backdated to $paymentDate.
     */
    private function makePaidInvoiceOnDate(
        Product $product,
        int $quantity,
        int $pricePerUnit,
        Carbon $paymentDate,
    ): Payment {
        // Freeze time so that SalesInvoice::create() and Payment::create() both use
        // $paymentDate as created_at. This ensures the sync-queue job dispatched by
        // Payment::saved fires with the correct workDay instead of today's date.
        Carbon::setTestNow($paymentDate);

        $customer = $this->makeCustomer();
        $invoice = SalesInvoice::create([
            'customer_id' => $customer->id,
            'commercial_id' => $this->commercial->id,
            'status' => 'DRAFT',
        ]);

        Vente::create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $pricePerUnit,
            'profit' => 0,
            'type' => Vente::TYPE_INVOICE,
        ]);

        $payment = Payment::create([
            'sales_invoice_id' => $invoice->fresh()->id,
            'amount' => $quantity * $pricePerUnit,
            'payment_method' => 'CASH',
            'user_id' => $this->user->id,
        ]);

        Carbon::setTestNow();

        return $payment;
    }

    /**
     * Creates one invoice with multiple products and a single full payment backdated to $paymentDate.
     * Use this when testing basket bonus: all required categories must be in the same invoice.
     *
     * @param  array<array{0: Product, 1: int, 2: int}>  $productQuantityPriceTuples  [[product, qty, price], ...]
     */
    private function makeMultiProductPaidInvoiceOnDate(
        array $productQuantityPriceTuples,
        Carbon $paymentDate,
    ): Payment {
        // Freeze time so that SalesInvoice::create() and Payment::create() both use
        // $paymentDate as created_at. This ensures the sync-queue job dispatched by
        // Payment::saved fires with the correct workDay instead of today's date.
        Carbon::setTestNow($paymentDate);

        $customer = $this->makeCustomer();
        $invoice = SalesInvoice::create([
            'customer_id' => $customer->id,
            'commercial_id' => $this->commercial->id,
            'status' => 'DRAFT',
        ]);

        $totalAmount = 0;

        foreach ($productQuantityPriceTuples as [$product, $quantity, $pricePerUnit]) {
            Vente::create([
                'sales_invoice_id' => $invoice->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'price' => $pricePerUnit,
                'profit' => 0,
                'type' => Vente::TYPE_INVOICE,
            ]);
            $totalAmount += $quantity * $pricePerUnit;
        }

        $payment = Payment::create([
            'sales_invoice_id' => $invoice->fresh()->id,
            'amount' => $totalAmount,
            'payment_method' => 'CASH',
            'user_id' => $this->user->id,
        ]);

        Carbon::setTestNow();

        return $payment;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // recalculateDailyCommissionForWorkDay — base commission
    // ─────────────────────────────────────────────────────────────────────────

    public function test_base_commission_is_zero_when_no_payments_on_that_day(): void
    {
        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial,
            $this->weeklyWorkPeriod,
            '2026-03-03',
        );

        $this->assertEquals(0, $dailyCommission->base_commission);
        $this->assertEquals(0, $dailyCommission->net_commission);
    }

    public function test_base_commission_is_computed_from_all_payments_on_that_day(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $product->id,
            'rate' => 0.0100, // 1%
        ]);

        // Two payments on Tuesday 3 Mar: 20_000 + 30_000 = 50_000 × 1% = 500
        $this->makePaidInvoiceOnDate($product, 2, 10_000, Carbon::parse('2026-03-03'));
        $this->makePaidInvoiceOnDate($product, 3, 10_000, Carbon::parse('2026-03-03'));

        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial,
            $this->weeklyWorkPeriod,
            '2026-03-03',
        );

        $this->assertEquals(500, $dailyCommission->base_commission);
    }

    public function test_payments_on_other_days_are_excluded_from_daily_calculation(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $product->id,
            'rate' => 0.0100,
        ]);

        // Payment on Tuesday (the day we compute) — should be included.
        $this->makePaidInvoiceOnDate($product, 2, 10_000, Carbon::parse('2026-03-03')); // 20_000
        // Payment on Wednesday — should NOT be included in Tuesday's daily commission.
        $this->makePaidInvoiceOnDate($product, 5, 10_000, Carbon::parse('2026-03-04')); // 50_000

        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial,
            $this->weeklyWorkPeriod,
            '2026-03-03',
        );

        // Only 20_000 × 1% = 200
        $this->assertEquals(200, $dailyCommission->base_commission);
    }

    public function test_each_day_gets_its_own_daily_commission_record(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $product->id,
            'rate' => 0.0100,
        ]);

        $this->makePaidInvoiceOnDate($product, 1, 10_000, Carbon::parse('2026-03-02')); // Monday
        $this->makePaidInvoiceOnDate($product, 2, 10_000, Carbon::parse('2026-03-03')); // Tuesday
        $this->makePaidInvoiceOnDate($product, 3, 10_000, Carbon::parse('2026-03-04')); // Wednesday

        $this->service->recalculateDailyCommissionForWorkDay($this->commercial, $this->weeklyWorkPeriod, '2026-03-02');
        $this->service->recalculateDailyCommissionForWorkDay($this->commercial, $this->weeklyWorkPeriod, '2026-03-03');
        $this->service->recalculateDailyCommissionForWorkDay($this->commercial, $this->weeklyWorkPeriod, '2026-03-04');

        $this->assertEquals(3, DailyCommission::where('commercial_work_period_id', $this->weeklyWorkPeriod->id)->count());

        $monday = DailyCommission::whereDate('work_day', '2026-03-02')->first();
        $tuesday = DailyCommission::whereDate('work_day', '2026-03-03')->first();
        $wednesday = DailyCommission::whereDate('work_day', '2026-03-04')->first();

        $this->assertEquals(100, $monday->base_commission);  // 10_000 × 1%
        $this->assertEquals(200, $tuesday->base_commission); // 20_000 × 1%
        $this->assertEquals(300, $wednesday->base_commission); // 30_000 × 1%
    }

    /**
     * @throws \Throwable
     */
    public function test_recalculating_a_day_replaces_payment_lines_not_duplicates(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $product->id,
            'rate' => 0.0100,
        ]);

        $this->makePaidInvoiceOnDate($product, 1, 10_000, Carbon::parse('2026-03-04'));

        $firstRun = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-04'
        );
        $lineCountAfterFirstRun = CommissionPaymentLine::where('daily_commission_id', $firstRun->id)->count();

        /** @noinspection PhpSuspiciousNameCombinationInspection */
        $secondRun = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-04'
        );
        $lineCountAfterSecondRun = CommissionPaymentLine::where('daily_commission_id', $secondRun->id)->count();

        $this->assertEquals($lineCountAfterFirstRun, $lineCountAfterSecondRun);
        $this->assertEquals(1, DailyCommission::where('commercial_work_period_id', $this->weeklyWorkPeriod->id)->count());
    }

    /**
     * @throws \Throwable
     */
    public function test_commission_payment_lines_are_persisted_with_correct_daily_commission_id(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $product->id,
            'rate' => 0.0100,
        ]);

        $this->makePaidInvoiceOnDate($product, 1, 10_000, Carbon::parse('2026-03-04'));

        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-04'
        );

        $this->assertDatabaseHas('commission_payment_lines', [
            'daily_commission_id' => $dailyCommission->id,
            'product_id' => $product->id,
            'commission_amount' => 100, // 10_000 × 1%
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Basket bonus (daily)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_basket_bonus_is_zero_when_no_period_settings_configured(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $product->id,
            'rate' => 0.0100,
        ]);

        $this->makePaidInvoiceOnDate($product, 1, 10_000, Carbon::parse('2026-03-04'));

        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-04'
        );

        $this->assertEquals(0, $dailyCommission->basket_bonus);
        $this->assertFalse($dailyCommission->basket_achieved);
    }

    public function test_basket_bonus_is_applied_when_all_required_categories_are_in_the_same_invoice(): void
    {
        $categoryAlm = $this->makeCategory('ALM');
        $categoryJet = $this->makeCategory('JET');

        $productAlm = $this->makeProduct($categoryAlm, 10_000);
        $productJet = $this->makeProduct($categoryJet, 10_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id, 'product_id' => $productAlm->id, 'rate' => 0.0100,
        ]);
        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id, 'product_id' => $productJet->id, 'rate' => 0.0100,
        ]);

        CommissionPeriodSetting::create([
            'period_start_date' => $this->periodStart,
            'period_end_date' => $this->periodEnd,
            'basket_multiplier' => 1.30,
            'required_category_ids' => [$categoryAlm->id, $categoryJet->id],
        ]);

        // Both categories in one invoice on Tuesday — base = 100 + 100 = 200, basket = 200 × 0.30 = 60
        $this->makeMultiProductPaidInvoiceOnDate(
            [[$productAlm, 1, 10_000], [$productJet, 1, 10_000]],
            Carbon::parse('2026-03-03'),
        );

        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-03'
        );

        $this->assertTrue($dailyCommission->basket_achieved);
        $this->assertEquals(200, $dailyCommission->base_commission);
        $this->assertEquals(60, $dailyCommission->basket_bonus);
        $this->assertEquals(260, $dailyCommission->net_commission);
    }

    public function test_basket_bonus_is_not_applied_when_required_categories_are_split_across_separate_invoices(): void
    {
        $categoryAlm = $this->makeCategory('ALM');
        $categoryJet = $this->makeCategory('JET');

        $productAlm = $this->makeProduct($categoryAlm, 10_000);
        $productJet = $this->makeProduct($categoryJet, 10_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id, 'product_id' => $productAlm->id, 'rate' => 0.0100,
        ]);
        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id, 'product_id' => $productJet->id, 'rate' => 0.0100,
        ]);

        CommissionPeriodSetting::create([
            'period_start_date' => $this->periodStart,
            'period_end_date' => $this->periodEnd,
            'basket_multiplier' => 1.30,
            'required_category_ids' => [$categoryAlm->id, $categoryJet->id],
        ]);

        // Both categories sold on Tuesday, but on SEPARATE invoices — basket must NOT be triggered
        $this->makePaidInvoiceOnDate($productAlm, 1, 10_000, Carbon::parse('2026-03-03'));
        $this->makePaidInvoiceOnDate($productJet, 1, 10_000, Carbon::parse('2026-03-03'));

        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-03'
        );

        $this->assertFalse($dailyCommission->basket_achieved);
        $this->assertEquals(200, $dailyCommission->base_commission);
        $this->assertEquals(0, $dailyCommission->basket_bonus);
        $this->assertEquals(200, $dailyCommission->net_commission);
    }

    public function test_basket_bonus_is_not_applied_when_only_some_required_categories_sold(): void
    {
        $categoryAlm = $this->makeCategory('ALM');
        $categoryJet = $this->makeCategory('JET');
        $productAlm = $this->makeProduct($categoryAlm, 10_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id, 'product_id' => $productAlm->id, 'rate' => 0.0100,
        ]);

        CommissionPeriodSetting::create([
            'period_start_date' => $this->periodStart,
            'period_end_date' => $this->periodEnd,
            'basket_multiplier' => 1.30,
            'required_category_ids' => [$categoryAlm->id, $categoryJet->id],
        ]);

        // Only ALM sold — JET missing — no basket
        $this->makePaidInvoiceOnDate($productAlm, 1, 10_000, Carbon::parse('2026-03-03'));

        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-03'
        );

        $this->assertFalse($dailyCommission->basket_achieved);
        $this->assertEquals(0, $dailyCommission->basket_bonus);
    }

    public function test_basket_bonus_on_one_day_does_not_affect_another_day(): void
    {
        $categoryAlm = $this->makeCategory('ALM');
        $categoryJet = $this->makeCategory('JET');

        $productAlm = $this->makeProduct($categoryAlm, 10_000);
        $productJet = $this->makeProduct($categoryJet, 10_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id, 'product_id' => $productAlm->id, 'rate' => 0.0100,
        ]);
        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id, 'product_id' => $productJet->id, 'rate' => 0.0100,
        ]);

        CommissionPeriodSetting::create([
            'period_start_date' => $this->periodStart,
            'period_end_date' => $this->periodEnd,
            'basket_multiplier' => 1.30,
            'required_category_ids' => [$categoryAlm->id, $categoryJet->id],
        ]);

        // Monday: both categories in one invoice — basket achieved
        $this->makeMultiProductPaidInvoiceOnDate(
            [[$productAlm, 1, 10_000], [$productJet, 1, 10_000]],
            Carbon::parse('2026-03-02'),
        );

        // Tuesday: only ALM — basket NOT achieved
        $this->makePaidInvoiceOnDate($productAlm, 1, 10_000, Carbon::parse('2026-03-03'));

        $monday = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-02'
        );
        $tuesday = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-03'
        );

        $this->assertTrue($monday->basket_achieved);
        $this->assertFalse($tuesday->basket_achieved);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Objective bonus (daily encaissement vs period tiers)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_objective_bonus_is_zero_when_daily_encaissement_is_below_all_tiers(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        CommercialObjectiveTier::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'tier_level' => 1,
            'ca_threshold' => 100_000,
            'bonus_amount' => 50_000,
        ]);

        $this->makePaidInvoiceOnDate($product, 1, 10_000, Carbon::parse('2026-03-04'));

        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-04'
        );

        $this->assertEquals(0, $dailyCommission->objective_bonus);
        $this->assertNull($dailyCommission->achieved_tier_level);
    }

    public function test_objective_bonus_applies_highest_achieved_tier_based_on_daily_encaissement(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        CommercialObjectiveTier::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'tier_level' => 1, 'ca_threshold' => 10_000, 'bonus_amount' => 10_000,
        ]);
        CommercialObjectiveTier::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'tier_level' => 2, 'ca_threshold' => 50_000, 'bonus_amount' => 30_000,
        ]);
        CommercialObjectiveTier::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'tier_level' => 3, 'ca_threshold' => 100_000, 'bonus_amount' => 70_000,
        ]);

        // Daily encaissement = 60_000 → tiers 1 and 2 reached, tier 3 not reached.
        $this->makePaidInvoiceOnDate($product, 6, 10_000, Carbon::parse('2026-03-04'));

        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-04'
        );

        // Non-cumulative: only tier 2 (highest achieved) pays out.
        $this->assertEquals(30_000, $dailyCommission->objective_bonus);
        $this->assertEquals(2, $dailyCommission->achieved_tier_level);
    }

    public function test_objective_bonus_is_independent_per_day(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        CommercialObjectiveTier::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'tier_level' => 1, 'ca_threshold' => 50_000, 'bonus_amount' => 20_000,
        ]);

        // Monday: 60_000 → tier 1 achieved
        $this->makePaidInvoiceOnDate($product, 6, 10_000, Carbon::parse('2026-03-02'));
        // Tuesday: 10_000 → tier 1 not achieved
        $this->makePaidInvoiceOnDate($product, 1, 10_000, Carbon::parse('2026-03-03'));

        $monday = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-02'
        );
        $tuesday = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-03'
        );

        $this->assertEquals(20_000, $monday->objective_bonus);
        $this->assertEquals(0, $tuesday->objective_bonus);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Penalties (daily — assigned via work_day)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_penalty_assigned_to_a_day_is_deducted_from_that_days_net_commission(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 100_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id, 'product_id' => $product->id, 'rate' => 0.0100,
        ]);

        $this->makePaidInvoiceOnDate($product, 1, 100_000, Carbon::parse('2026-03-04'));

        CommercialPenalty::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'work_day' => '2026-03-04',
            'amount' => 500,
            'reason' => 'Retard',
        ]);

        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-04'
        );

        $this->assertEquals(1_000, $dailyCommission->base_commission);   // 100_000 × 1%
        $this->assertEquals(500, $dailyCommission->total_penalties);
        $this->assertEquals(500, $dailyCommission->net_commission);       // 1_000 − 500
    }

    public function test_penalty_on_day_a_does_not_affect_day_b(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 100_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id, 'product_id' => $product->id, 'rate' => 0.0100,
        ]);

        $this->makePaidInvoiceOnDate($product, 1, 100_000, Carbon::parse('2026-03-04'));
        $this->makePaidInvoiceOnDate($product, 1, 100_000, Carbon::parse('2026-03-05'));

        // Penalty only on Wednesday
        CommercialPenalty::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'work_day' => '2026-03-04',
            'amount' => 500,
            'reason' => 'Test',
        ]);

        $wednesday = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-04'
        );
        $thursday = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-05'
        );

        $this->assertEquals(500, $wednesday->total_penalties);
        $this->assertEquals(0, $thursday->total_penalties);
    }

    public function test_net_commission_cannot_go_below_zero(): void
    {
        CommercialPenalty::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'work_day' => '2026-03-04',
            'amount' => 99_999, // far larger than any commission
            'reason' => 'Test',
        ]);

        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-04'
        );

        $this->assertEquals(0, $dailyCommission->net_commission);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // recalculateDailyCommissionForPayment — auto-resolution
    // ─────────────────────────────────────────────────────────────────────────

    public function test_payment_with_no_sales_invoice_is_silently_skipped(): void
    {
        $payment = Payment::create([
            'sales_invoice_id' => null,
            'amount' => 10_000,
            'payment_method' => 'CASH',
            'user_id' => $this->user->id,
        ]);

        // Should not throw and should not create any DailyCommission.
        $this->service->recalculateDailyCommissionForPayment($payment);

        $this->assertEquals(0, DailyCommission::count());
    }

    public function test_payment_from_user_without_a_commercial_is_silently_skipped(): void
    {
        $userWithoutCommercial = User::factory()->create();
        $customer = $this->makeCustomer();
        $invoice = SalesInvoice::create([
            'customer_id' => $customer->id,
            'commercial_id' => $this->commercial->id,
            'status' => 'DRAFT',
        ]);

        $payment = Payment::create([
            'sales_invoice_id' => $invoice->id,
            'amount' => 10_000,
            'payment_method' => 'CASH',
            'user_id' => $userWithoutCommercial->id,
        ]);

        $this->service->recalculateDailyCommissionForPayment($payment);

        $this->assertEquals(0, DailyCommission::count());
    }

    public function test_payment_outside_pre_configured_period_auto_creates_a_weekly_period_and_tracks_commission(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $product->id,
            'rate' => 0.0100,
        ]);

        // Payment on Sunday 8 Mar — outside the Mon-Sat pre-configured work period.
        // The system must auto-create a Mon 2 Mar → Sun 8 Mar weekly period and track commission.
        $payment = $this->makePaidInvoiceOnDate($product, 1, 10_000, Carbon::parse('2026-03-08'));

        $this->service->recalculateDailyCommissionForPayment($payment);

        // A new period covering Sunday 8 Mar must have been auto-created.
        $autoCreatedPeriod = CommercialWorkPeriod::whereDate('period_end_date', '2026-03-08')->first();
        $this->assertNotNull($autoCreatedPeriod, 'A weekly period ending on Sun 8 Mar must be auto-created.');
        $this->assertEquals('2026-03-02', $autoCreatedPeriod->period_start_date->toDateString());

        // A DailyCommission for 8 Mar must exist and carry the correct commission.
        $dailyCommission = DailyCommission::whereDate('work_day', '2026-03-08')->first();
        $this->assertNotNull($dailyCommission, 'A DailyCommission for 8 Mar must be created.');
        $this->assertEquals(100, $dailyCommission->base_commission); // 10_000 × 1%
        $this->assertEquals($autoCreatedPeriod->id, $dailyCommission->commercial_work_period_id);
    }

    public function test_payment_in_a_finalized_work_period_is_silently_skipped(): void
    {
        $this->weeklyWorkPeriod->update(['is_finalized' => true]);

        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        $payment = $this->makePaidInvoiceOnDate($product, 1, 10_000, Carbon::parse('2026-03-03'));

        $this->service->recalculateDailyCommissionForPayment($payment);

        $this->assertEquals(0, DailyCommission::count());
    }

    public function test_payment_inside_work_period_creates_daily_commission(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $product->id,
            'rate' => 0.0100,
        ]);

        $payment = $this->makePaidInvoiceOnDate($product, 1, 10_000, Carbon::parse('2026-03-04'));

        $this->service->recalculateDailyCommissionForPayment($payment);

        $this->assertEquals(1, DailyCommission::count());

        $dailyCommission = DailyCommission::whereDate('work_day', '2026-03-04')->first();
        $this->assertNotNull($dailyCommission);
        $this->assertEquals($this->weeklyWorkPeriod->id, $dailyCommission->commercial_work_period_id);
        $this->assertEquals(100, $dailyCommission->base_commission); // 10_000 × 1%
    }

    // ─────────────────────────────────────────────────────────────────────────
    // recomputeAllDaysForWorkPeriod
    // ─────────────────────────────────────────────────────────────────────────

    public function test_recompute_all_days_creates_one_record_per_payment_day(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $product->id,
            'rate' => 0.0100,
        ]);

        $this->makePaidInvoiceOnDate($product, 1, 10_000, Carbon::parse('2026-03-02')); // Mon
        $this->makePaidInvoiceOnDate($product, 1, 10_000, Carbon::parse('2026-03-04')); // Wed
        $this->makePaidInvoiceOnDate($product, 1, 10_000, Carbon::parse('2026-03-06')); // Fri

        $this->service->recomputeAllDaysForWorkPeriod($this->weeklyWorkPeriod);

        $this->assertEquals(3, DailyCommission::where('commercial_work_period_id', $this->weeklyWorkPeriod->id)->count());
    }

    public function test_recompute_all_days_throws_if_work_period_is_finalized(): void
    {
        $this->weeklyWorkPeriod->update(['is_finalized' => true]);

        $this->expectException(RuntimeException::class);

        $this->service->recomputeAllDaysForWorkPeriod($this->weeklyWorkPeriod);
    }

    public function test_recompute_all_days_zeroes_out_a_day_whose_payments_were_removed(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $product->id,
            'rate' => 0.0100,
        ]);

        // Seed a DailyCommission for Tuesday directly (simulating a prior calculation).
        DailyCommission::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'work_day' => '2026-03-03',
            'base_commission' => 500,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 500,
            'basket_achieved' => false,
        ]);

        // No actual payments exist — recompute should zero out Tuesday.
        $this->service->recomputeAllDaysForWorkPeriod($this->weeklyWorkPeriod);

        $tuesday = DailyCommission::whereDate('work_day', '2026-03-03')->first();
        $this->assertEquals(0, $tuesday->base_commission);
        $this->assertEquals(0, $tuesday->net_commission);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // finalizeWorkPeriod
    // ─────────────────────────────────────────────────────────────────────────

    public function test_finalize_work_period_sets_is_finalized_and_finalized_at(): void
    {
        $this->assertFalse($this->weeklyWorkPeriod->is_finalized);
        $this->assertNull($this->weeklyWorkPeriod->finalized_at);

        $finalizedWorkPeriod = $this->service->finalizeWorkPeriod($this->weeklyWorkPeriod);

        $this->assertTrue($finalizedWorkPeriod->is_finalized);
        $this->assertNotNull($finalizedWorkPeriod->finalized_at);
    }

    public function test_finalizing_already_finalized_work_period_throws(): void
    {
        $this->service->finalizeWorkPeriod($this->weeklyWorkPeriod);

        $this->expectException(RuntimeException::class);

        $this->service->finalizeWorkPeriod($this->weeklyWorkPeriod->fresh());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Full flow
    // ─────────────────────────────────────────────────────────────────────────

    public function test_full_flow_multi_day_period_with_basket_objective_and_penalty(): void
    {
        $categoryAlm = $this->makeCategory('ALM');
        $categoryJet = $this->makeCategory('JET');

        $productAlm = $this->makeProduct($categoryAlm, 100_000);
        $productJet = $this->makeProduct($categoryJet, 50_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id, 'product_id' => $productAlm->id, 'rate' => 0.0100,
        ]);
        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id, 'product_id' => $productJet->id, 'rate' => 0.0150,
        ]);

        CommissionPeriodSetting::create([
            'period_start_date' => $this->periodStart,
            'period_end_date' => $this->periodEnd,
            'basket_multiplier' => 1.30,
            'required_category_ids' => [$categoryAlm->id, $categoryJet->id],
        ]);

        CommercialObjectiveTier::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'tier_level' => 1, 'ca_threshold' => 100_000, 'bonus_amount' => 15_000,
        ]);

        CommercialPenalty::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'work_day' => '2026-03-03', // Tuesday penalty
            'amount' => 1_000,
            'reason' => 'Retard',
        ]);

        // Monday: ALM only — base = 1_000, no basket, no tier (100_000 encaissement hits tier 1)
        $this->makePaidInvoiceOnDate($productAlm, 1, 100_000, Carbon::parse('2026-03-02'));

        // Tuesday: ALM + JET in one invoice — base = 1_000 + 750 = 1_750, basket achieved, tier 1 achieved (150_000 > 100_000)
        $this->makeMultiProductPaidInvoiceOnDate(
            [[$productAlm, 1, 100_000], [$productJet, 1, 50_000]],
            Carbon::parse('2026-03-03'),
        );

        $this->service->recomputeAllDaysForWorkPeriod($this->weeklyWorkPeriod);

        $monday = DailyCommission::whereDate('work_day', '2026-03-02')->first();
        $tuesday = DailyCommission::whereDate('work_day', '2026-03-03')->first();

        // Monday: base 1_000, no basket (JET missing), tier 1 achieved (100_000 >= 100_000), no penalty
        $this->assertEquals(1_000, $monday->base_commission);
        $this->assertFalse($monday->basket_achieved);
        $this->assertEquals(15_000, $monday->objective_bonus);
        $this->assertEquals(0, $monday->total_penalties);
        $this->assertEquals(16_000, $monday->net_commission); // 1_000 + 15_000

        // Tuesday: base 1_750, basket achieved (30% bonus = 525), tier 1 achieved (150_000 >= 100_000), penalty 1_000
        $this->assertEquals(1_750, $tuesday->base_commission);
        $this->assertTrue($tuesday->basket_achieved);
        $this->assertEquals(525, $tuesday->basket_bonus);     // 1_750 × 0.30
        $this->assertEquals(15_000, $tuesday->objective_bonus);
        $this->assertEquals(1_000, $tuesday->total_penalties);
        $this->assertEquals(16_275, $tuesday->net_commission); // 1_750 + 525 + 15_000 − 1_000
    }

    // ─────────────────────────────────────────────────────────────────────────
    // estimated_commercial_commission sync after recalculateDailyCommissionForWorkDay
    // ─────────────────────────────────────────────────────────────────────────

    public function test_commercial_estimated_commissions_are_recalculated_after_work_day_recompute(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $product->id,
            'rate' => 0.0200, // 2 %
        ]);

        $payment = $this->makePaidInvoiceOnDate($product, 1, 10_000, Carbon::parse('2026-03-03'));
        $invoiceId = $payment->salesInvoice->id;

        // Corrupt the stored value to simulate stale data.
        DB::table('sales_invoices')->where('id', $invoiceId)->update(['estimated_commercial_commission' => 99_999]);
        $this->assertEquals(99_999, SalesInvoice::find($invoiceId)->estimated_commercial_commission);

        $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-03',
        );

        // 10_000 × 1 × 2 % = 200
        $this->assertEquals(200, SalesInvoice::find($invoiceId)->estimated_commercial_commission);
    }

    public function test_multiple_invoices_on_same_day_all_get_updated(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 5_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $product->id,
            'rate' => 0.0500, // 5 %
        ]);

        $paymentA = $this->makePaidInvoiceOnDate($product, 2, 5_000, Carbon::parse('2026-03-04'));
        $paymentB = $this->makePaidInvoiceOnDate($product, 3, 5_000, Carbon::parse('2026-03-04'));

        $invoiceAId = $paymentA->salesInvoice->id;
        $invoiceBId = $paymentB->salesInvoice->id;

        // Corrupt both values.
        DB::table('sales_invoices')->whereIn('id', [$invoiceAId, $invoiceBId])->update(['estimated_commercial_commission' => 1]);

        $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-04',
        );

        // Invoice A: 5_000 × 2 × 5 % = 500
        $this->assertEquals(500, SalesInvoice::find($invoiceAId)->estimated_commercial_commission);
        // Invoice B: 5_000 × 3 × 5 % = 750
        $this->assertEquals(750, SalesInvoice::find($invoiceBId)->estimated_commercial_commission);
    }

    public function test_invoice_on_different_day_is_not_updated(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $product->id,
            'rate' => 0.0200,
        ]);

        // Invoice created on Tuesday, recalculation runs for Wednesday.
        $payment = $this->makePaidInvoiceOnDate($product, 1, 10_000, Carbon::parse('2026-03-03'));
        $invoiceId = $payment->salesInvoice->id;

        DB::table('sales_invoices')->where('id', $invoiceId)->update(['estimated_commercial_commission' => 55_555]);

        $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-04', // different day
        );

        // The Tuesday invoice must remain untouched.
        $this->assertEquals(55_555, SalesInvoice::find($invoiceId)->estimated_commercial_commission);
    }

    public function test_invoice_with_no_commission_rate_gets_zero_after_recompute(): void
    {
        // No CommercialProductCommissionRate created — rate resolves to 0.
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        $payment = $this->makePaidInvoiceOnDate($product, 1, 10_000, Carbon::parse('2026-03-03'));
        $invoiceId = $payment->salesInvoice->id;

        // Force a non-zero stale value.
        DB::table('sales_invoices')->where('id', $invoiceId)->update(['estimated_commercial_commission' => 12_345]);

        $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-03',
        );

        $this->assertEquals(0, SalesInvoice::find($invoiceId)->estimated_commercial_commission);
    }
}
