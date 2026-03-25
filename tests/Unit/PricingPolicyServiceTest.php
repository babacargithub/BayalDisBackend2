<?php

namespace Tests\Unit;

use App\Models\PricingPolicy;
use App\Models\Product;
use App\Services\PricingPolicyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private PricingPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PricingPolicyService::class);
    }

    public function test_no_policy_does_nothing(): void
    {
        $items = [
            ['product_id' => 1, 'quantity' => 1, 'price' => 1000],
        ];
        $result = $this->service->applyPolicyToItems($items, null, false);
        $this->assertSame($items, $result);
    }

    public function test_active_deferred_only_applies_when_due_beyond_grace(): void
    {
        PricingPolicy::factory()->create([
            'surcharge_percent' => 20,
            'grace_days' => 2,
            'apply_to_deferred_only' => true,
            'active' => true,
        ]);

        Carbon::setTestNow('2026-03-25 13:01:00');

        $items = [
            ['product_id' => 1, 'quantity' => 1, 'price' => 1000],
        ];

        // Due within grace → no change
        $withinGrace = Carbon::parse('2026-03-27 00:00:00');
        $resultWithin = $this->service->applyPolicyToItems($items, $withinGrace, false);
        $this->assertSame($items, $resultWithin);

        // Due beyond grace → +20%
        $beyondGrace = Carbon::parse('2026-03-28 00:00:00');
        $resultBeyond = $this->service->applyPolicyToItems($items, $beyondGrace, false);
        $this->assertSame(1200, $resultBeyond[0]['price']);
    }

    public function test_paid_invoices_ignored_when_deferred_only(): void
    {
        PricingPolicy::factory()->create([
            'surcharge_percent' => 15,
            'grace_days' => 0,
            'apply_to_deferred_only' => true,
            'active' => true,
        ]);

        $items = [
            ['product_id' => 1, 'quantity' => 2, 'price' => 1000],
        ];

        $resultPaid = $this->service->applyPolicyToItems($items, Carbon::parse('2026-03-30'), true);
        $this->assertSame($items, $resultPaid);
    }

    public function test_non_deferred_policy_applies_to_all(): void
    {
        PricingPolicy::factory()->create([
            'surcharge_percent' => 10,
            'apply_to_deferred_only' => false,
            'active' => true,
        ]);

        $items = [
            ['product_id' => 1, 'quantity' => 3, 'price' => 1000],
        ];

        $result = $this->service->applyPolicyToItems($items, null, false);
        $this->assertSame(1100, $result[0]['price']);
    }

    // =========================================================================
    // applyCreditPricingToItems tests
    // =========================================================================

    public function test_apply_credit_pricing_replaces_price_when_unpaid_and_policy_enabled(): void
    {
        $product = Product::create([
            'name' => 'Product A',
            'price' => 1000,
            'credit_price' => 1200,
            'cost_price' => 600,
        ]);

        PricingPolicy::factory()->create([
            'apply_credit_price' => true,
            'active' => true,
        ]);

        $items = [
            ['product_id' => $product->id, 'quantity' => 2, 'price' => 1000],
        ];

        $result = $this->service->applyCreditPricingToItems($items, isPaid: false);

        $this->assertSame(1200, $result[0]['price']);
    }

    public function test_apply_credit_pricing_does_not_replace_when_invoice_is_paid(): void
    {
        $product = Product::create([
            'name' => 'Product B',
            'price' => 1000,
            'credit_price' => 1500,
            'cost_price' => 600,
        ]);

        PricingPolicy::factory()->create([
            'apply_credit_price' => true,
            'active' => true,
        ]);

        $items = [
            ['product_id' => $product->id, 'quantity' => 1, 'price' => 1000],
        ];

        $result = $this->service->applyCreditPricingToItems($items, isPaid: true);

        $this->assertSame(1000, $result[0]['price']);
    }

    public function test_apply_credit_pricing_does_not_replace_when_policy_has_apply_credit_price_disabled(): void
    {
        $product = Product::create([
            'name' => 'Product C',
            'price' => 1000,
            'credit_price' => 1300,
            'cost_price' => 600,
        ]);

        PricingPolicy::factory()->create([
            'apply_credit_price' => false,
            'active' => true,
        ]);

        $items = [
            ['product_id' => $product->id, 'quantity' => 1, 'price' => 1000],
        ];

        $result = $this->service->applyCreditPricingToItems($items, isPaid: false);

        $this->assertSame(1000, $result[0]['price']);
    }

    public function test_apply_credit_pricing_leaves_price_unchanged_when_product_has_no_credit_price(): void
    {
        $product = Product::create([
            'name' => 'Product D',
            'price' => 1000,
            'credit_price' => null,
            'cost_price' => 600,
        ]);

        PricingPolicy::factory()->create([
            'apply_credit_price' => true,
            'active' => true,
        ]);

        $items = [
            ['product_id' => $product->id, 'quantity' => 1, 'price' => 1000],
        ];

        $result = $this->service->applyCreditPricingToItems($items, isPaid: false);

        $this->assertSame(1000, $result[0]['price']);
    }

    public function test_apply_credit_pricing_does_nothing_when_no_active_policy_exists(): void
    {
        $product = Product::create([
            'name' => 'Product E',
            'price' => 1000,
            'credit_price' => 1400,
            'cost_price' => 600,
        ]);

        $items = [
            ['product_id' => $product->id, 'quantity' => 1, 'price' => 1000],
        ];

        $result = $this->service->applyCreditPricingToItems($items, isPaid: false);

        $this->assertSame(1000, $result[0]['price']);
    }

    public function test_apply_credit_pricing_handles_multiple_items_correctly(): void
    {
        $productWithCreditPrice = Product::create([
            'name' => 'Product With Credit',
            'price' => 1000,
            'credit_price' => 1200,
            'cost_price' => 600,
        ]);
        $productWithoutCreditPrice = Product::create([
            'name' => 'Product Without Credit',
            'price' => 500,
            'credit_price' => null,
            'cost_price' => 300,
        ]);

        PricingPolicy::factory()->create([
            'apply_credit_price' => true,
            'active' => true,
        ]);

        $items = [
            ['product_id' => $productWithCreditPrice->id, 'quantity' => 3, 'price' => 1000],
            ['product_id' => $productWithoutCreditPrice->id, 'quantity' => 2, 'price' => 500],
        ];

        $result = $this->service->applyCreditPricingToItems($items, isPaid: false);

        // Product with credit_price → replaced
        $this->assertSame(1200, $result[0]['price']);
        // Product without credit_price → unchanged
        $this->assertSame(500, $result[1]['price']);
    }
}
