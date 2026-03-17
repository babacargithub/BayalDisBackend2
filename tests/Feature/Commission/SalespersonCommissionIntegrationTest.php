<?php

namespace Tests\Feature\Commission;

use App\Enums\CarLoadItemSource;
use App\Enums\CarLoadStatus;
use App\Models\CarLoad;
use App\Models\CarLoadItem;
use App\Models\Commercial;
use App\Models\CommercialObjectiveTier;
use App\Models\CommercialProductCommissionRate;
use App\Models\CommercialWorkPeriod;
use App\Models\CommissionPaymentLine;
use App\Models\CommissionPeriodSetting;
use App\Models\Customer;
use App\Models\DailyCommission;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Integration tests for the commission system from the salesperson's perspective.
 *
 * These tests simulate the mobile salesperson app flow end-to-end:
 *   1. POST /api/salesperson/sales-invoices  (create invoice + immediate payment)
 *   2. POST /api/salesperson/invoices/{id}/pay  (pay an existing invoice)
 *
 * DailyCommission records are asserted after each API call.
 * No service or model methods are called directly inside the test bodies —
 * everything flows through the real HTTP routes + Payment model events +
 * RecalculateDailyCommissionJob (QUEUE_CONNECTION=sync).
 */
class SalespersonCommissionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** Fixed weekly period: Mon 2 Mar → Sat 7 Mar 2026 */
    private string $periodStart = '2026-03-02';

    private string $periodEnd = '2026-03-07';

    private User $user;

    private Commercial $commercial;

    private Team $team;

    private CarLoad $activeCarLoad;

    private CommercialWorkPeriod $weeklyWorkPeriod;

    private ProductCategory $categoryAlm;

    private ProductCategory $categoryJet;

    private Product $productAlm;

    private Product $productJet;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->team = Team::create([
            'name' => 'Équipe Test',
            'user_id' => $this->user->id,
        ]);

        $this->commercial = Commercial::create([
            'name' => 'Commercial Test',
            'phone_number' => '221700000001',
            'gender' => 'male',
            'user_id' => $this->user->id,
        ]);

        // Attach the commercial to the team (team_id is not in $fillable, update directly).
        $this->commercial->team_id = $this->team->id;
        $this->commercial->save();

        // Active car load whose return_date is far in the future — will always be found
        // by getCurrentCarLoadForTeam() regardless of the frozen Carbon date used in tests.
        $this->activeCarLoad = CarLoad::create([
            'name' => 'Chargement Test',
            'load_date' => now(),
            'return_date' => Carbon::parse('2026-12-31'),
            'team_id' => $this->team->id,
            'status' => CarLoadStatus::Selling,
        ]);

        $this->weeklyWorkPeriod = CommercialWorkPeriod::create([
            'commercial_id' => $this->commercial->id,
            'period_start_date' => $this->periodStart,
            'period_end_date' => $this->periodEnd,
        ]);

        $this->categoryAlm = ProductCategory::create(['name' => 'ALM']);
        $this->categoryJet = ProductCategory::create(['name' => 'JET']);

        $this->productAlm = Product::create([
            'name' => 'Produit ALM',
            'price' => 10_000,
            'cost_price' => 7_000,
            'product_category_id' => $this->categoryAlm->id,
        ]);

        $this->productJet = Product::create([
            'name' => 'Produit JET',
            'price' => 10_000,
            'cost_price' => 7_000,
            'product_category_id' => $this->categoryJet->id,
        ]);

        // Seed car load stock so the API's FIFO decrease never throws InsufficientStockException.
        $this->addCarLoadStock($this->productAlm, 999);
        $this->addCarLoadStock($this->productJet, 999);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $this->productAlm->id,
            'rate' => 0.0100, // 1 %
        ]);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $this->productJet->id,
            'rate' => 0.0100, // 1 %
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

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Add stock to the active car load so the salesperson can sell the product.
     */
    private function addCarLoadStock(Product $product, int $quantity): void
    {
        CarLoadItem::create([
            'car_load_id' => $this->activeCarLoad->id,
            'product_id' => $product->id,
            'quantity_loaded' => $quantity,
            'quantity_left' => $quantity,
            'cost_price_per_unit' => $product->cost_price,
            'loaded_at' => now(),
            'source' => CarLoadItemSource::Warehouse,
        ]);
    }

    /**
     * Simulate the salesperson app creating one invoice with a single product line
     * and recording an immediate full payment via the dedicated API route.
     *
     * Carbon time must be frozen by the caller so that Payment.created_at lands on
     * the intended work day.
     *
     * POST /api/salesperson/sales-invoices  →  201
     * RecalculateDailyCommissionJob runs synchronously (QUEUE_CONNECTION=sync),
     * so by the time this method returns the DailyCommission record already exists.
     */
    private function salespersonMakesSale(
        Product $product,
        int $quantity,
        int $pricePerUnit,
    ): Payment {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/salesperson/sales-invoices', [
            'customer_id' => $this->customer->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => $quantity, 'price' => $pricePerUnit],
            ],
            'paid' => true,
            'payment_method' => 'Cash',
        ]);

        $response->assertStatus(201);

        return Payment::where('user_id', $this->user->id)->latest('id')->firstOrFail();
    }

    /**
     * Simulate the salesperson app creating one invoice with multiple product lines
     * and recording an immediate full payment via the dedicated API route.
     * Basket bonus scenarios require all required categories to appear in one invoice.
     *
     * POST /api/salesperson/sales-invoices  →  201
     *
     * @param  array<array{0: Product, 1: int, 2: int}>  $productQuantityPriceTuples  [[product, qty, price], ...]
     */
    private function salespersonMakesMultiProductSale(array $productQuantityPriceTuples): Payment
    {
        Sanctum::actingAs($this->user);

        $items = array_map(
            fn (array $tuple) => ['product_id' => $tuple[0]->id, 'quantity' => $tuple[1], 'price' => $tuple[2]],
            $productQuantityPriceTuples,
        );

        $response = $this->postJson('/api/salesperson/sales-invoices', [
            'customer_id' => $this->customer->id,
            'items' => $items,
            'paid' => true,
            'payment_method' => 'Cash',
        ]);

        $response->assertStatus(201);

        return Payment::where('user_id', $this->user->id)->latest('id')->firstOrFail();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Basic commission creation
    // ─────────────────────────────────────────────────────────────────────────

    public function test_recording_a_payment_via_api_automatically_creates_a_daily_commission(): void
    {
        Carbon::setTestNow('2026-03-03'); // Tuesday, inside work period

        $this->salespersonMakesSale($this->productAlm, 1, 10_000);

        // base_commission = 10 000 × 1 % = 100
        $dailyCommission = DailyCommission::whereDate('work_day', '2026-03-03')
            ->where('commercial_work_period_id', $this->weeklyWorkPeriod->id)
            ->first();

        $this->assertNotNull($dailyCommission, 'DailyCommission must be created automatically after a payment.');
        $this->assertEquals(100, $dailyCommission->base_commission);
        $this->assertEquals(100, $dailyCommission->net_commission);
        $this->assertFalse($dailyCommission->basket_achieved);
        $this->assertEquals(0, $dailyCommission->basket_bonus);
        $this->assertEquals(0, $dailyCommission->objective_bonus);
    }

    public function test_commission_payment_lines_are_persisted_for_each_product_in_the_invoice(): void
    {
        Carbon::setTestNow('2026-03-03');

        $payment = $this->salespersonMakesSale($this->productAlm, 2, 10_000);

        $dailyCommission = DailyCommission::whereDate('work_day', '2026-03-03')->first();

        $this->assertNotNull($dailyCommission);
        $this->assertEquals(1, $dailyCommission->paymentLines()->count());

        $paymentLine = CommissionPaymentLine::where('payment_id', $payment->id)->first();

        $this->assertNotNull($paymentLine);
        $this->assertEquals($this->productAlm->id, $paymentLine->product_id);
        $this->assertEquals(20_000, $paymentLine->payment_amount_allocated);
        $this->assertEquals(200, $paymentLine->commission_amount); // 20 000 × 1 %
        $this->assertEquals(0.0100, $paymentLine->rate_applied);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Same-day accumulation
    // ─────────────────────────────────────────────────────────────────────────

    public function test_second_payment_on_the_same_day_recalculates_and_accumulates_the_daily_commission(): void
    {
        Carbon::setTestNow('2026-03-03');

        $this->salespersonMakesSale($this->productAlm, 1, 10_000); // +100
        $this->salespersonMakesSale($this->productJet, 1, 10_000); // +100

        $this->assertEquals(1, DailyCommission::count(), 'Only one DailyCommission record must exist for the day.');

        $dailyCommission = DailyCommission::whereDate('work_day', '2026-03-03')->first();

        $this->assertEquals(200, $dailyCommission->base_commission);
        $this->assertEquals(200, $dailyCommission->net_commission);
        $this->assertEquals(2, $dailyCommission->paymentLines()->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Payment deletion recalculates commission
    // ─────────────────────────────────────────────────────────────────────────

    public function test_deleting_a_payment_recalculates_the_daily_commission(): void
    {
        Carbon::setTestNow('2026-03-03');

        $firstPayment = $this->salespersonMakesSale($this->productAlm, 1, 10_000); // 100
        $this->salespersonMakesSale($this->productAlm, 2, 10_000);                  // 200

        $dailyCommission = DailyCommission::whereDate('work_day', '2026-03-03')->first();
        $this->assertEquals(300, $dailyCommission->base_commission);

        // First payment deleted (e.g., error correction)
        $firstPayment->delete();

        $dailyCommission->refresh();
        $this->assertEquals(200, $dailyCommission->base_commission);
        $this->assertEquals(200, $dailyCommission->net_commission);
        $this->assertEquals(1, $dailyCommission->paymentLines()->count());
    }

    public function test_deleting_the_only_payment_on_a_day_zeroes_out_the_daily_commission(): void
    {
        Carbon::setTestNow('2026-03-03');

        $payment = $this->salespersonMakesSale($this->productAlm, 1, 10_000);

        $dailyCommission = DailyCommission::whereDate('work_day', '2026-03-03')->first();
        $this->assertEquals(100, $dailyCommission->base_commission);

        $payment->delete();

        $dailyCommission->refresh();
        $this->assertEquals(0, $dailyCommission->base_commission);
        $this->assertEquals(0, $dailyCommission->net_commission);
        $this->assertEquals(0, $dailyCommission->paymentLines()->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Multi-day isolation
    // ─────────────────────────────────────────────────────────────────────────

    public function test_payments_on_different_days_produce_independent_daily_commission_records(): void
    {
        Carbon::setTestNow('2026-03-02'); // Monday
        $this->salespersonMakesSale($this->productAlm, 1, 10_000); // 100

        Carbon::setTestNow('2026-03-03'); // Tuesday
        $this->salespersonMakesSale($this->productAlm, 3, 10_000); // 300

        Carbon::setTestNow('2026-03-04'); // Wednesday
        $this->salespersonMakesSale($this->productAlm, 5, 10_000); // 500

        $this->assertEquals(3, DailyCommission::count());

        $monday = DailyCommission::whereDate('work_day', '2026-03-02')->first();
        $tuesday = DailyCommission::whereDate('work_day', '2026-03-03')->first();
        $wednesday = DailyCommission::whereDate('work_day', '2026-03-04')->first();

        $this->assertEquals(100, $monday->base_commission);
        $this->assertEquals(300, $tuesday->base_commission);
        $this->assertEquals(500, $wednesday->base_commission);
    }

    public function test_recalculating_one_day_does_not_affect_commissions_on_other_days(): void
    {
        Carbon::setTestNow('2026-03-02'); // Monday
        $this->salespersonMakesSale($this->productAlm, 1, 10_000); // 100

        Carbon::setTestNow('2026-03-03'); // Tuesday
        $tuesdayPayment = $this->salespersonMakesSale($this->productAlm, 2, 10_000); // 200

        $tuesdayPayment->delete(); // Only Tuesday recalculates

        $monday = DailyCommission::whereDate('work_day', '2026-03-02')->first();
        $tuesday = DailyCommission::whereDate('work_day', '2026-03-03')->first();

        $this->assertEquals(100, $monday->base_commission, 'Monday commission must be unaffected.');
        $this->assertEquals(0, $tuesday->base_commission, 'Tuesday commission must be zeroed.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Basket bonus
    // ─────────────────────────────────────────────────────────────────────────

    public function test_basket_bonus_is_applied_when_salesperson_sells_all_required_categories_in_one_invoice(): void
    {
        CommissionPeriodSetting::create([
            'period_start_date' => $this->periodStart,
            'period_end_date' => $this->periodEnd,
            'basket_multiplier' => 1.30,
            'required_category_ids' => [$this->categoryAlm->id, $this->categoryJet->id],
        ]);

        Carbon::setTestNow('2026-03-03');

        $this->salespersonMakesMultiProductSale([
            [$this->productAlm, 1, 10_000],
            [$this->productJet, 1, 10_000],
        ]);

        $dailyCommission = DailyCommission::whereDate('work_day', '2026-03-03')->first();

        $this->assertTrue($dailyCommission->basket_achieved);
        $this->assertEquals(200, $dailyCommission->base_commission); // 10 000 × 1 % × 2
        $this->assertEquals(60, $dailyCommission->basket_bonus);     // 200 × 30 %
        $this->assertEquals(260, $dailyCommission->net_commission);
        $this->assertEquals(1.30, $dailyCommission->basket_multiplier_applied);
    }

    public function test_basket_bonus_is_not_triggered_when_required_categories_are_split_across_separate_invoices(): void
    {
        CommissionPeriodSetting::create([
            'period_start_date' => $this->periodStart,
            'period_end_date' => $this->periodEnd,
            'basket_multiplier' => 1.30,
            'required_category_ids' => [$this->categoryAlm->id, $this->categoryJet->id],
        ]);

        Carbon::setTestNow('2026-03-03');

        // Both categories sold on the same day but via separate invoices/API calls
        $this->salespersonMakesSale($this->productAlm, 1, 10_000);
        $this->salespersonMakesSale($this->productJet, 1, 10_000);

        $dailyCommission = DailyCommission::whereDate('work_day', '2026-03-03')->first();

        $this->assertFalse($dailyCommission->basket_achieved);
        $this->assertEquals(200, $dailyCommission->base_commission);
        $this->assertEquals(0, $dailyCommission->basket_bonus);
        $this->assertEquals(200, $dailyCommission->net_commission);
    }

    public function test_basket_bonus_is_not_applied_when_only_one_required_category_is_sold(): void
    {
        CommissionPeriodSetting::create([
            'period_start_date' => $this->periodStart,
            'period_end_date' => $this->periodEnd,
            'basket_multiplier' => 1.30,
            'required_category_ids' => [$this->categoryAlm->id, $this->categoryJet->id],
        ]);

        Carbon::setTestNow('2026-03-03');

        $this->salespersonMakesSale($this->productAlm, 1, 10_000); // JET is missing

        $dailyCommission = DailyCommission::whereDate('work_day', '2026-03-03')->first();

        $this->assertFalse($dailyCommission->basket_achieved);
        $this->assertEquals(0, $dailyCommission->basket_bonus);
    }

    public function test_basket_bonus_on_one_day_does_not_pollute_a_day_with_an_incomplete_basket(): void
    {
        CommissionPeriodSetting::create([
            'period_start_date' => $this->periodStart,
            'period_end_date' => $this->periodEnd,
            'basket_multiplier' => 1.30,
            'required_category_ids' => [$this->categoryAlm->id, $this->categoryJet->id],
        ]);

        Carbon::setTestNow('2026-03-02'); // Monday — full basket in one invoice
        $this->salespersonMakesMultiProductSale([
            [$this->productAlm, 1, 10_000],
            [$this->productJet, 1, 10_000],
        ]);

        Carbon::setTestNow('2026-03-03'); // Tuesday — only ALM
        $this->salespersonMakesSale($this->productAlm, 1, 10_000);

        $monday = DailyCommission::whereDate('work_day', '2026-03-02')->first();
        $tuesday = DailyCommission::whereDate('work_day', '2026-03-03')->first();

        $this->assertTrue($monday->basket_achieved);
        $this->assertEquals(60, $monday->basket_bonus); // 200 × 30 %

        $this->assertFalse($tuesday->basket_achieved);
        $this->assertEquals(0, $tuesday->basket_bonus);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Objective tier bonus
    // ─────────────────────────────────────────────────────────────────────────

    public function test_objective_tier_bonus_is_applied_when_daily_encaissement_meets_threshold(): void
    {
        CommercialObjectiveTier::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'tier_level' => 1,
            'ca_threshold' => 100_000,
            'bonus_amount' => 15_000,
        ]);

        Carbon::setTestNow('2026-03-03');

        // 10 units × 10 000 XOF = 100 000 — exactly meets tier 1
        $this->salespersonMakesSale($this->productAlm, 10, 10_000);

        $dailyCommission = DailyCommission::whereDate('work_day', '2026-03-03')->first();

        $this->assertEquals(1_000, $dailyCommission->base_commission); // 100 000 × 1 %
        $this->assertEquals(15_000, $dailyCommission->objective_bonus);
        $this->assertEquals(1, $dailyCommission->achieved_tier_level);
        $this->assertEquals(16_000, $dailyCommission->net_commission);
    }

    public function test_highest_objective_tier_is_applied_when_multiple_tiers_are_configured(): void
    {
        CommercialObjectiveTier::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'tier_level' => 1, 'ca_threshold' => 100_000, 'bonus_amount' => 10_000,
        ]);
        CommercialObjectiveTier::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'tier_level' => 2, 'ca_threshold' => 200_000, 'bonus_amount' => 25_000,
        ]);
        CommercialObjectiveTier::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'tier_level' => 3, 'ca_threshold' => 300_000, 'bonus_amount' => 50_000,
        ]);

        Carbon::setTestNow('2026-03-03');

        // 250 000 XOF — tier 2 is the highest reached (tier 3 requires 300 000)
        $this->salespersonMakesSale($this->productAlm, 25, 10_000);

        $dailyCommission = DailyCommission::whereDate('work_day', '2026-03-03')->first();

        $this->assertEquals(2_500, $dailyCommission->base_commission);
        $this->assertEquals(25_000, $dailyCommission->objective_bonus);
        $this->assertEquals(2, $dailyCommission->achieved_tier_level);
    }

    public function test_no_objective_bonus_when_encaissement_is_below_the_lowest_tier_threshold(): void
    {
        CommercialObjectiveTier::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'tier_level' => 1, 'ca_threshold' => 100_000, 'bonus_amount' => 10_000,
        ]);

        Carbon::setTestNow('2026-03-03');

        $this->salespersonMakesSale($this->productAlm, 5, 10_000); // 50 000 — below threshold

        $dailyCommission = DailyCommission::whereDate('work_day', '2026-03-03')->first();

        $this->assertEquals(0, $dailyCommission->objective_bonus);
        $this->assertNull($dailyCommission->achieved_tier_level);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard rails — no work period / finalized period / no invoice
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_daily_commission_is_created_when_payment_falls_outside_any_work_period(): void
    {
        Carbon::setTestNow('2026-03-10'); // After the period ends (Sat 7 Mar)

        $this->salespersonMakesSale($this->productAlm, 1, 10_000);

        $this->assertEquals(0, DailyCommission::count());
    }

    public function test_payment_in_a_finalized_period_does_not_update_the_daily_commission(): void
    {
        Carbon::setTestNow('2026-03-03');

        $this->salespersonMakesSale($this->productAlm, 1, 10_000); // 100

        $dailyCommission = DailyCommission::whereDate('work_day', '2026-03-03')->first();
        $this->assertEquals(100, $dailyCommission->net_commission);

        // Manager finalizes the period
        $this->weeklyWorkPeriod->update([
            'is_finalized' => true,
            'finalized_at' => now(),
        ]);

        // Another sale arrives — must be silently ignored
        $this->salespersonMakesSale($this->productAlm, 5, 10_000);

        $dailyCommission->refresh();
        $this->assertEquals(100, $dailyCommission->net_commission, 'Commission must not change after finalization.');
        $this->assertEquals(1, $dailyCommission->paymentLines()->count(), 'Payment lines must not be rebuilt for a finalized period.');
    }
}
