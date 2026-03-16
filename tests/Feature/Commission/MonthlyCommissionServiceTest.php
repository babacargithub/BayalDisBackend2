<?php

namespace Tests\Feature\Commission;

use App\Data\Commission\CommissionPeriodData;
use App\Models\Commercial;
use App\Models\CommercialObjectiveTier;
use App\Models\CommercialPenalty;
use App\Models\CommercialProductCommissionRate;
use App\Models\CommercialWorkPeriod;
use App\Models\Commission;
use App\Models\CommissionPaymentLine;
use App\Models\CommissionPeriodSetting;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Vente;
use App\Services\Commission\CommissionCalculatorService;
use App\Services\Commission\CommissionPeriodService;
use App\Services\Commission\CommissionRateResolverService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class MonthlyCommissionServiceTest extends TestCase
{
    use RefreshDatabase;

    private CommissionPeriodService $service;

    private User $user;

    private Commercial $commercial;

    /** A fixed weekly period: Mon 2 Mar → Sat 7 Mar 2026. */
    private CommissionPeriodData $weeklyPeriod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CommissionPeriodService(
            new CommissionCalculatorService(new CommissionRateResolverService)
        );

        $this->user = User::factory()->create();
        $this->commercial = Commercial::create([
            'name' => 'Commercial Test',
            'phone_number' => '221700000001',
            'gender' => 'male',
            'user_id' => $this->user->id,
        ]);

        $this->weeklyPeriod = new CommissionPeriodData(
            CarbonImmutable::parse('2026-03-02'), // Monday
            CarbonImmutable::parse('2026-03-07'), // Saturday
        );
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

    /**
     * Returns the CommercialWorkPeriod for the default weekly period, creating it if needed.
     * Avoids duplicating the work period creation across test methods that seed tiers or penalties.
     */
    private function getOrCreateWeeklyWorkPeriod(): CommercialWorkPeriod
    {
        return CommercialWorkPeriod::firstOrCreate([
            'commercial_id' => $this->commercial->id,
            'period_start_date' => $this->weeklyPeriod->startDate->startOfDay(),
            'period_end_date' => $this->weeklyPeriod->endDate->startOfDay(),
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
    private function makePaidInvoiceInPeriod(
        Product $product,
        int $quantity,
        int $pricePerUnit,
        Carbon $paymentDate,
    ): Payment {
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
            'payment_method' => 'Cash',
            'user_id' => $this->user->id,
        ]);

        // Backdate the payment to land within the required period.
        $payment->created_at = $paymentDate;
        $payment->saveQuietly();

        return $payment;
    }

    // -------------------------------------------------------------------------
    // Base commission
    // -------------------------------------------------------------------------

    public function test_base_commission_is_zero_when_no_payments_in_period(): void
    {
        $commission = $this->service->computeOrRefreshCommissionForPeriod($this->commercial, $this->weeklyPeriod);

        $this->assertEquals(0, $commission->base_commission);
        $this->assertEquals(0, $commission->net_commission);
    }

    public function test_base_commission_sums_across_multiple_payments_within_period(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $product->id,
            'rate' => 0.0100, // 1%
        ]);

        // Two payments inside the week: 20_000 + 30_000 → total 50_000 × 1% = 500
        $this->makePaidInvoiceInPeriod($product, 2, 10_000, Carbon::parse('2026-03-03')); // Tuesday
        $this->makePaidInvoiceInPeriod($product, 3, 10_000, Carbon::parse('2026-03-05')); // Thursday

        $commission = $this->service->computeOrRefreshCommissionForPeriod($this->commercial, $this->weeklyPeriod);

        $this->assertEquals(500, $commission->base_commission);
    }

    public function test_payments_outside_period_dates_are_excluded(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $product->id,
            'rate' => 0.0100,
        ]);

        // Sunday 1 Mar — before the Mon 2 Mar start date — must be excluded.
        $this->makePaidInvoiceInPeriod($product, 1, 10_000, Carbon::parse('2026-03-01'));
        // Sunday 8 Mar — after the Sat 7 Mar end date — must be excluded.
        $this->makePaidInvoiceInPeriod($product, 1, 10_000, Carbon::parse('2026-03-08'));
        // Tuesday 3 Mar — inside the period — must be included.
        $this->makePaidInvoiceInPeriod($product, 2, 10_000, Carbon::parse('2026-03-03'));

        $commission = $this->service->computeOrRefreshCommissionForPeriod($this->commercial, $this->weeklyPeriod);

        // Only 20_000 × 1% = 200 from the in-period payment.
        $this->assertEquals(200, $commission->base_commission);
    }

    public function test_period_start_and_end_dates_are_stored_on_the_work_period_record(): void
    {
        $commission = $this->service->computeOrRefreshCommissionForPeriod($this->commercial, $this->weeklyPeriod);

        $workPeriod = $commission->workPeriod;

        $this->assertEquals('2026-03-02', $workPeriod->period_start_date->toDateString());
        $this->assertEquals('2026-03-07', $workPeriod->period_end_date->toDateString());
        $this->assertEquals($this->commercial->id, $workPeriod->commercial_id);
    }

    public function test_commission_payment_lines_are_persisted_to_database(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $product->id,
            'rate' => 0.0100,
        ]);

        $this->makePaidInvoiceInPeriod($product, 1, 10_000, Carbon::parse('2026-03-04'));

        $commission = $this->service->computeOrRefreshCommissionForPeriod($this->commercial, $this->weeklyPeriod);

        $this->assertDatabaseHas('commission_payment_lines', [
            'commission_id' => $commission->id,
            'product_id' => $product->id,
            'commission_amount' => 100, // 10_000 × 1%
        ]);
    }

    public function test_refreshing_a_commission_replaces_old_payment_lines_not_duplicates(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $product->id,
            'rate' => 0.0100,
        ]);

        $this->makePaidInvoiceInPeriod($product, 1, 10_000, Carbon::parse('2026-03-04'));

        $firstRun = $this->service->computeOrRefreshCommissionForPeriod($this->commercial, $this->weeklyPeriod);
        $lineCountAfterFirstRun = CommissionPaymentLine::where('commission_id', $firstRun->id)->count();

        $secondRun = $this->service->computeOrRefreshCommissionForPeriod($this->commercial, $this->weeklyPeriod);
        $lineCountAfterSecondRun = CommissionPaymentLine::where('commission_id', $secondRun->id)->count();

        $this->assertEquals($lineCountAfterFirstRun, $lineCountAfterSecondRun);
        $this->assertEquals(1, Commission::count());          // only one Commission record
        $this->assertEquals(1, CommercialWorkPeriod::count()); // only one work period
    }

    // -------------------------------------------------------------------------
    // Weekly vs monthly periods
    // -------------------------------------------------------------------------

    public function test_weekly_period_factory_produces_monday_to_saturday(): void
    {
        $period = CommissionPeriodData::weekly(Carbon::parse('2026-03-04')); // Wednesday

        $this->assertEquals('2026-03-02', $period->startDate->toDateString()); // Monday
        $this->assertEquals('2026-03-07', $period->endDate->toDateString());   // Saturday
    }

    public function test_monthly_period_factory_covers_full_calendar_month(): void
    {
        $period = CommissionPeriodData::monthly(2026, 3);

        $this->assertEquals('2026-03-01', $period->startDate->toDateString());
        $this->assertEquals('2026-03-31', $period->endDate->toDateString());
    }

    public function test_monthly_period_includes_payments_on_any_day_of_month(): void
    {
        $monthlyPeriod = CommissionPeriodData::monthly(2026, 3);
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $product->id,
            'rate' => 0.0100,
        ]);

        // Payment on Sunday (day not covered by a weekly period)
        $this->makePaidInvoiceInPeriod($product, 1, 10_000, Carbon::parse('2026-03-08')); // Sunday

        $commission = $this->service->computeOrRefreshCommissionForPeriod($this->commercial, $monthlyPeriod);

        $this->assertEquals(100, $commission->base_commission); // 10_000 × 1%
    }

    // -------------------------------------------------------------------------
    // Basket bonus
    // -------------------------------------------------------------------------

    public function test_basket_bonus_is_zero_when_no_period_settings_configured(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $product->id,
            'rate' => 0.0100,
        ]);

        $this->makePaidInvoiceInPeriod($product, 1, 10_000, Carbon::parse('2026-03-04'));

        $commission = $this->service->computeOrRefreshCommissionForPeriod($this->commercial, $this->weeklyPeriod);

        $this->assertEquals(0, $commission->basket_bonus);
        $this->assertFalse($commission->basket_achieved);
    }

    public function test_basket_bonus_is_applied_when_all_required_categories_are_sold(): void
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
            'period_start_date' => '2026-03-02',
            'period_end_date' => '2026-03-07',
            'basket_multiplier' => 1.30,
            'required_category_ids' => [$categoryAlm->id, $categoryJet->id],
        ]);

        // base = 100 + 100 = 200, basket_bonus = 200 × 0.30 = 60
        $this->makePaidInvoiceInPeriod($productAlm, 1, 10_000, Carbon::parse('2026-03-03'));
        $this->makePaidInvoiceInPeriod($productJet, 1, 10_000, Carbon::parse('2026-03-04'));

        $commission = $this->service->computeOrRefreshCommissionForPeriod($this->commercial, $this->weeklyPeriod);

        $this->assertTrue($commission->basket_achieved);
        $this->assertEquals(200, $commission->base_commission);
        $this->assertEquals(60, $commission->basket_bonus);
        $this->assertEquals(260, $commission->net_commission);
    }

    public function test_basket_bonus_is_not_applied_when_only_some_required_categories_are_sold(): void
    {
        $categoryAlm = $this->makeCategory('ALM');
        $categoryJet = $this->makeCategory('JET');
        $productAlm = $this->makeProduct($categoryAlm, 10_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id, 'product_id' => $productAlm->id, 'rate' => 0.0100,
        ]);

        CommissionPeriodSetting::create([
            'period_start_date' => '2026-03-02',
            'period_end_date' => '2026-03-07',
            'basket_multiplier' => 1.30,
            'required_category_ids' => [$categoryAlm->id, $categoryJet->id],
        ]);

        $this->makePaidInvoiceInPeriod($productAlm, 1, 10_000, Carbon::parse('2026-03-03'));

        $commission = $this->service->computeOrRefreshCommissionForPeriod($this->commercial, $this->weeklyPeriod);

        $this->assertFalse($commission->basket_achieved);
        $this->assertEquals(0, $commission->basket_bonus);
    }

    // -------------------------------------------------------------------------
    // Objective bonus (non-cumulative tiers)
    // -------------------------------------------------------------------------

    public function test_objective_bonus_is_zero_when_ca_is_below_all_tiers(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        $workPeriod = $this->getOrCreateWeeklyWorkPeriod();
        CommercialObjectiveTier::create([
            'commercial_work_period_id' => $workPeriod->id,
            'tier_level' => 1,
            'ca_threshold' => 100_000,
            'bonus_amount' => 50_000,
        ]);

        $this->makePaidInvoiceInPeriod($product, 1, 10_000, Carbon::parse('2026-03-04'));

        $commission = $this->service->computeOrRefreshCommissionForPeriod($this->commercial, $this->weeklyPeriod);

        $this->assertEquals(0, $commission->objective_bonus);
        $this->assertNull($commission->achieved_tier_level);
    }

    public function test_objective_bonus_uses_highest_achieved_tier_not_cumulative(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        $workPeriod = $this->getOrCreateWeeklyWorkPeriod();
        CommercialObjectiveTier::create([
            'commercial_work_period_id' => $workPeriod->id,
            'tier_level' => 1, 'ca_threshold' => 10_000, 'bonus_amount' => 10_000,
        ]);
        CommercialObjectiveTier::create([
            'commercial_work_period_id' => $workPeriod->id,
            'tier_level' => 2, 'ca_threshold' => 50_000, 'bonus_amount' => 30_000,
        ]);
        CommercialObjectiveTier::create([
            'commercial_work_period_id' => $workPeriod->id,
            'tier_level' => 3, 'ca_threshold' => 100_000, 'bonus_amount' => 70_000,
        ]);

        // Total encaissement = 60_000 → tiers 1 and 2 reached, tier 3 not reached.
        $this->makePaidInvoiceInPeriod($product, 2, 10_000, Carbon::parse('2026-03-02'));
        $this->makePaidInvoiceInPeriod($product, 2, 10_000, Carbon::parse('2026-03-04'));
        $this->makePaidInvoiceInPeriod($product, 2, 10_000, Carbon::parse('2026-03-06'));

        $commission = $this->service->computeOrRefreshCommissionForPeriod($this->commercial, $this->weeklyPeriod);

        // Non-cumulative: only tier 2 (the highest achieved) pays out.
        $this->assertEquals(30_000, $commission->objective_bonus);
        $this->assertEquals(2, $commission->achieved_tier_level);
    }

    // -------------------------------------------------------------------------
    // Penalties
    // -------------------------------------------------------------------------

    public function test_penalties_are_deducted_from_net_commission(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 100_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id, 'product_id' => $product->id, 'rate' => 0.0100,
        ]);

        // base_commission = 100_000 × 1% = 1_000
        $this->makePaidInvoiceInPeriod($product, 1, 100_000, Carbon::parse('2026-03-04'));

        $workPeriod = $this->getOrCreateWeeklyWorkPeriod();
        CommercialPenalty::create([
            'commercial_work_period_id' => $workPeriod->id,
            'amount' => 500,
            'reason' => 'Retard de remise de caisse',
        ]);

        $commission = $this->service->computeOrRefreshCommissionForPeriod($this->commercial, $this->weeklyPeriod);

        $this->assertEquals(1_000, $commission->base_commission);
        $this->assertEquals(500, $commission->total_penalties);
        $this->assertEquals(500, $commission->net_commission); // 1_000 − 500
    }

    public function test_net_commission_cannot_go_below_zero(): void
    {
        $workPeriod = $this->getOrCreateWeeklyWorkPeriod();
        CommercialPenalty::create([
            'commercial_work_period_id' => $workPeriod->id,
            'amount' => 50_000, // larger than any commission earned
            'reason' => 'Pénalité test',
        ]);

        $commission = $this->service->computeOrRefreshCommissionForPeriod($this->commercial, $this->weeklyPeriod);

        $this->assertEquals(0, $commission->net_commission);
    }

    // -------------------------------------------------------------------------
    // Finalization
    // -------------------------------------------------------------------------

    public function test_finalized_commission_cannot_be_recomputed(): void
    {
        $commission = $this->service->computeOrRefreshCommissionForPeriod($this->commercial, $this->weeklyPeriod);
        $this->service->finalizeCommission($commission);

        $this->expectException(RuntimeException::class);

        $this->service->computeOrRefreshCommissionForPeriod($this->commercial, $this->weeklyPeriod);
    }

    public function test_finalize_sets_is_finalized_and_finalized_at(): void
    {
        $commission = $this->service->computeOrRefreshCommissionForPeriod($this->commercial, $this->weeklyPeriod);

        $this->assertFalse($commission->is_finalized);
        $this->assertNull($commission->finalized_at);

        $finalizedCommission = $this->service->finalizeCommission($commission);

        $this->assertTrue($finalizedCommission->is_finalized);
        $this->assertNotNull($finalizedCommission->finalized_at);
    }

    public function test_finalizing_an_already_finalized_commission_throws(): void
    {
        $commission = $this->service->computeOrRefreshCommissionForPeriod($this->commercial, $this->weeklyPeriod);
        $this->service->finalizeCommission($commission);

        $this->expectException(RuntimeException::class);
        $this->service->finalizeCommission($commission->fresh());
    }

    // -------------------------------------------------------------------------
    // Full flow
    // -------------------------------------------------------------------------

    public function test_full_flow_base_plus_basket_plus_objective_minus_penalty(): void
    {
        $categoryAlm = $this->makeCategory('ALM');
        $categoryJet = $this->makeCategory('JET');
        $categoryHyg = $this->makeCategory('HYG');

        $productAlm = $this->makeProduct($categoryAlm, 100_000);
        $productJet = $this->makeProduct($categoryJet, 50_000);
        $productHyg = $this->makeProduct($categoryHyg, 20_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id, 'product_id' => $productAlm->id, 'rate' => 0.0100,
        ]);
        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id, 'product_id' => $productJet->id, 'rate' => 0.0150,
        ]);
        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id, 'product_id' => $productHyg->id, 'rate' => 0.0200,
        ]);

        CommissionPeriodSetting::create([
            'period_start_date' => '2026-03-02',
            'period_end_date' => '2026-03-07',
            'basket_multiplier' => 1.30,
            'required_category_ids' => [$categoryAlm->id, $categoryJet->id, $categoryHyg->id],
        ]);

        $workPeriod = $this->getOrCreateWeeklyWorkPeriod();
        CommercialObjectiveTier::create([
            'commercial_work_period_id' => $workPeriod->id,
            'tier_level' => 1, 'ca_threshold' => 150_000, 'bonus_amount' => 25_000,
        ]);

        CommercialPenalty::create([
            'commercial_work_period_id' => $workPeriod->id,
            'amount' => 2_000, 'reason' => 'Test penalty',
        ]);

        // Encaissement: ALM 100_000, JET 50_000, HYG 20_000 = 170_000 (tier 1 ✓)
        $this->makePaidInvoiceInPeriod($productAlm, 1, 100_000, Carbon::parse('2026-03-03'));
        $this->makePaidInvoiceInPeriod($productJet, 1, 50_000, Carbon::parse('2026-03-04'));
        $this->makePaidInvoiceInPeriod($productHyg, 1, 20_000, Carbon::parse('2026-03-05'));

        $commission = $this->service->computeOrRefreshCommissionForPeriod($this->commercial, $this->weeklyPeriod);

        // base = 1_000 + 750 + 400 = 2_150
        $this->assertEquals(2_150, $commission->base_commission);
        $this->assertTrue($commission->basket_achieved);
        $this->assertEquals(645, $commission->basket_bonus);    // 2_150 × 0.30
        $this->assertEquals(25_000, $commission->objective_bonus);
        $this->assertEquals(2_000, $commission->total_penalties);
        $this->assertEquals(25_795, $commission->net_commission); // 2_150 + 645 + 25_000 − 2_000
    }
}
