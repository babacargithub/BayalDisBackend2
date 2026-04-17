<?php

namespace Tests\Feature\Commission;

use App\Models\Commercial;
use App\Models\CommercialObjectiveTier;
use App\Models\CommercialWorkPeriod;
use App\Models\Customer;
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
use Tests\TestCase;

/**
 * Tests for the global objective tier fallback.
 *
 * Global tiers (is_global = true, commercial_work_period_id = null) apply
 * to all commercials whose work period has no custom tiers defined.
 * If a work period has at least one custom tier, global tiers are ignored entirely.
 */
class GlobalObjectiveTierTest extends TestCase
{
    use RefreshDatabase;

    private DailyCommissionService $service;

    private User $user;

    private Commercial $commercial;

    private CommercialWorkPeriod $weeklyWorkPeriod;

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
            'name' => 'Commercial Global Tier Test',
            'phone_number' => '221700000099',
            'gender' => 'male',
            'user_id' => $this->user->id,
        ]);

        $this->weeklyWorkPeriod = CommercialWorkPeriod::create([
            'commercial_id' => $this->commercial->id,
            'period_start_date' => $this->periodStart,
            'period_end_date' => $this->periodEnd,
        ]);
    }

    private function makeProduct(?ProductCategory $category = null, int $price = 10_000): Product
    {
        return Product::create([
            'name' => 'Produit '.rand(1, 9999),
            'price' => $price,
            'cost_price' => 5_000,
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

    private function makePaidInvoiceOnDate(
        Product $product,
        int $quantity,
        int $pricePerUnit,
        Carbon $paymentDate,
    ): Payment {
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

    private function createGlobalTier(int $tierLevel, int $caThreshold, int $bonusAmount): CommercialObjectiveTier
    {
        return CommercialObjectiveTier::create([
            'commercial_work_period_id' => null,
            'is_global' => true,
            'tier_level' => $tierLevel,
            'ca_threshold' => $caThreshold,
            'bonus_amount' => $bonusAmount,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Global tier scope
    // ─────────────────────────────────────────────────────────────────────────

    public function test_global_scope_returns_only_global_tiers(): void
    {
        $this->createGlobalTier(1, 50_000, 10_000);
        $this->createGlobalTier(2, 100_000, 25_000);

        CommercialObjectiveTier::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'is_global' => false,
            'tier_level' => 1,
            'ca_threshold' => 80_000,
            'bonus_amount' => 15_000,
        ]);

        $globalTiers = CommercialObjectiveTier::global()->get();

        $this->assertCount(2, $globalTiers);
        $this->assertTrue($globalTiers->every(fn ($tier) => $tier->is_global === true));
        $this->assertTrue($globalTiers->every(fn ($tier) => $tier->commercial_work_period_id === null));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fallback: global tiers apply when work period has no custom tiers
    // ─────────────────────────────────────────────────────────────────────────

    public function test_global_tier_applies_as_objective_bonus_when_work_period_has_no_custom_tiers(): void
    {
        $product = $this->makeProduct();

        $this->createGlobalTier(1, 50_000, 20_000);
        $this->createGlobalTier(2, 100_000, 50_000);

        // Encaissement = 60_000 → global tier 1 achieved (50_000), tier 2 not reached.
        $this->makePaidInvoiceOnDate($product, 6, 10_000, Carbon::parse('2026-03-04'));

        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-04'
        );

        $this->assertEquals(20_000, $dailyCommission->objective_bonus);
        $this->assertEquals(1, $dailyCommission->achieved_tier_level);
    }

    public function test_global_tier_returns_highest_achieved_tier_when_multiple_thresholds_met(): void
    {
        $product = $this->makeProduct();

        $this->createGlobalTier(1, 30_000, 10_000);
        $this->createGlobalTier(2, 60_000, 25_000);
        $this->createGlobalTier(3, 120_000, 60_000);

        // Encaissement = 80_000 → tiers 1 and 2 reached, tier 3 not reached.
        $this->makePaidInvoiceOnDate($product, 8, 10_000, Carbon::parse('2026-03-04'));

        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-04'
        );

        // Non-cumulative: only highest achieved tier (tier 2) pays out.
        $this->assertEquals(25_000, $dailyCommission->objective_bonus);
        $this->assertEquals(2, $dailyCommission->achieved_tier_level);
    }

    public function test_objective_bonus_is_zero_when_encaissement_is_below_all_global_tiers(): void
    {
        $product = $this->makeProduct();

        $this->createGlobalTier(1, 100_000, 30_000);

        // Encaissement = 20_000 → below all global tiers.
        $this->makePaidInvoiceOnDate($product, 2, 10_000, Carbon::parse('2026-03-04'));

        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-04'
        );

        $this->assertEquals(0, $dailyCommission->objective_bonus);
        $this->assertNull($dailyCommission->achieved_tier_level);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Custom tiers override global tiers completely
    // ─────────────────────────────────────────────────────────────────────────

    public function test_custom_work_period_tiers_take_precedence_over_global_tiers(): void
    {
        $product = $this->makeProduct();

        // Global tier: threshold 50_000 → bonus 20_000.
        $this->createGlobalTier(1, 50_000, 20_000);

        // Custom tier for this work period: threshold 80_000 → bonus 40_000.
        CommercialObjectiveTier::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'is_global' => false,
            'tier_level' => 1,
            'ca_threshold' => 80_000,
            'bonus_amount' => 40_000,
        ]);

        // Encaissement = 60_000 → satisfies global tier threshold but NOT custom tier threshold.
        $this->makePaidInvoiceOnDate($product, 6, 10_000, Carbon::parse('2026-03-04'));

        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-04'
        );

        // Custom tiers are in effect, and 60_000 < 80_000, so bonus = 0 (not falling back to global).
        $this->assertEquals(0, $dailyCommission->objective_bonus);
        $this->assertNull($dailyCommission->achieved_tier_level);
    }

    public function test_global_tier_is_fully_ignored_when_work_period_has_any_custom_tier(): void
    {
        $product = $this->makeProduct();

        // Global tier is low — would give a massive bonus if used.
        $this->createGlobalTier(1, 10_000, 999_999);

        // One custom tier for this period — threshold higher than encaissement.
        CommercialObjectiveTier::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'is_global' => false,
            'tier_level' => 1,
            'ca_threshold' => 200_000,
            'bonus_amount' => 5_000,
        ]);

        // Encaissement = 50_000 → reaches global tier (10_000) but NOT custom tier (200_000).
        $this->makePaidInvoiceOnDate($product, 5, 10_000, Carbon::parse('2026-03-04'));

        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial, $this->weeklyWorkPeriod, '2026-03-04'
        );

        // Must be 0, not 999_999 — global tier must not bleed through.
        $this->assertEquals(0, $dailyCommission->objective_bonus);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // computeNextTierProgressForCommercialOnDay also falls back to global tiers
    // ─────────────────────────────────────────────────────────────────────────

    public function test_next_tier_progress_falls_back_to_global_tier_when_no_custom_tiers(): void
    {
        $this->createGlobalTier(1, 50_000, 20_000);
        $this->createGlobalTier(2, 100_000, 40_000);

        $nextTierProgress = $this->service->computeNextTierProgressForCommercialOnDay(
            $this->commercial,
            '2026-03-04',
            currentDailyEncaissement: 60_000,
        );

        // 60_000 < 100_000 (tier 2) → next tier is tier 2.
        $this->assertNotNull($nextTierProgress);
        $this->assertEquals(2, $nextTierProgress->tierLevel);
        $this->assertEquals(100_000, $nextTierProgress->caThreshold);
        $this->assertEquals(40_000, $nextTierProgress->bonusAmount);
        $this->assertEquals(40_000, $nextTierProgress->missingAmount);
    }

    public function test_next_tier_progress_uses_custom_tiers_when_work_period_has_them(): void
    {
        // Global tier at low threshold — should be ignored.
        $this->createGlobalTier(1, 10_000, 5_000);

        // Custom tier at higher threshold.
        CommercialObjectiveTier::create([
            'commercial_work_period_id' => $this->weeklyWorkPeriod->id,
            'is_global' => false,
            'tier_level' => 1,
            'ca_threshold' => 80_000,
            'bonus_amount' => 30_000,
        ]);

        $nextTierProgress = $this->service->computeNextTierProgressForCommercialOnDay(
            $this->commercial,
            '2026-03-04',
            currentDailyEncaissement: 50_000,
        );

        // Must use custom tier (80_000), not global tier (10_000, already exceeded at 50_000).
        $this->assertNotNull($nextTierProgress);
        $this->assertEquals(80_000, $nextTierProgress->caThreshold);
        $this->assertEquals(30_000, $nextTierProgress->missingAmount);
    }

    public function test_next_tier_progress_returns_null_when_all_global_tiers_are_already_achieved(): void
    {
        $this->createGlobalTier(1, 30_000, 10_000);

        $nextTierProgress = $this->service->computeNextTierProgressForCommercialOnDay(
            $this->commercial,
            '2026-03-04',
            currentDailyEncaissement: 50_000,
        );

        // 50_000 > 30_000 → all global tiers achieved, nothing left.
        $this->assertNull($nextTierProgress);
    }
}
