<?php /** @noinspection ALL */

namespace Tests\Feature\Commission;

use App\Models\Commercial;
use App\Models\CommercialCategoryCommissionRate;
use App\Models\CommercialProductCommissionRate;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Commission\CommissionRateResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionRateResolverServiceTest extends TestCase
{
    use RefreshDatabase;

    private CommissionRateResolverService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CommissionRateResolverService;
    }

    private function makeCommercial(): Commercial
    {
        $user = User::factory()->create();

        return Commercial::create([
            'name' => 'Commercial Test '.rand(1, 999),
            'phone_number' => '221'.rand(700000000, 799999999),
            'gender' => 'male',
            'user_id' => $user->id,
        ]);
    }

    private function makeCategory(string $name = 'ALM', ?float $commissionRate = null): ProductCategory
    {
        return ProductCategory::create([
            'name' => $name,
            'description' => "Catégorie $name",
            'commission_rate' => $commissionRate,
        ]);
    }

    private function makeProduct(?ProductCategory $category = null): Product
    {
        return Product::create([
            'name' => 'Produit Test '.rand(1, 999),
            'price' => 5000,
            'cost_price' => 3000,
            'product_category_id' => $category?->id,
        ]);
    }

    /** @noinspection PhpRedundantOptionalArgumentInspection */
    public function test_returns_product_level_override_rate_when_one_is_set(): void
    {
        $commercial = $this->makeCommercial();
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category);

        // Category-level default: 1%
        CommercialCategoryCommissionRate::create([
            'commercial_id' => $commercial->id,
            'product_category_id' => $category->id,
            'rate' => 0.0100,
        ]);

        // Product-level override: 1.5%
        CommercialProductCommissionRate::create([
            'commercial_id' => $commercial->id,
            'product_id' => $product->id,
            'rate' => 0.0150,
        ]);

        $resolvedRate = $this->service->resolveRateForCommercialAndProduct($commercial, $product);

        $this->assertEqualsWithDelta(0.0150, $resolvedRate, 0.00001);
    }

    public function test_falls_back_to_category_rate_when_no_product_override_exists(): void
    {
        $commercial = $this->makeCommercial();
        $category = $this->makeCategory('JET');
        $product = $this->makeProduct($category);

        CommercialCategoryCommissionRate::create([
            'commercial_id' => $commercial->id,
            'product_category_id' => $category->id,
            'rate' => 0.0125,
        ]);

        $resolvedRate = $this->service->resolveRateForCommercialAndProduct($commercial, $product);

        $this->assertEqualsWithDelta(0.0125, $resolvedRate, 0.00001);
    }

    public function test_returns_zero_when_no_rate_is_configured_at_any_level(): void
    {
        $commercial = $this->makeCommercial();
        $category = $this->makeCategory('HYG');
        $product = $this->makeProduct($category);

        // No rates configured at all.

        $resolvedRate = $this->service->resolveRateForCommercialAndProduct($commercial, $product);

        $this->assertEquals(0.0, $resolvedRate);
    }

    public function test_returns_zero_for_product_with_no_category_and_no_product_override(): void
    {
        $commercial = $this->makeCommercial();
    /** @noinspection PhpRedundantOptionalArgumentInspection */
        $product = $this->makeProduct(null); // no category

        $resolvedRate = $this->service->resolveRateForCommercialAndProduct($commercial, $product);

        $this->assertEquals(0.0, $resolvedRate);
    }

    public function test_product_override_takes_precedence_over_category_even_when_category_rate_is_higher(): void
    {
        $commercial = $this->makeCommercial();
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category);

        // Category rate is higher (2%)
        CommercialCategoryCommissionRate::create([
            'commercial_id' => $commercial->id,
            'product_category_id' => $category->id,
            'rate' => 0.0200,
        ]);

        // Product-level override is lower (0.5%)
        CommercialProductCommissionRate::create([
            'commercial_id' => $commercial->id,
            'product_id' => $product->id,
            'rate' => 0.0050,
        ]);

        $resolvedRate = $this->service->resolveRateForCommercialAndProduct($commercial, $product);

        // Product-level must always win.
        $this->assertEqualsWithDelta(0.0050, $resolvedRate, 0.00001);
    }

    /** @noinspection PhpRedundantOptionalArgumentInspection */
    public function test_different_commercials_have_independent_rates_for_the_same_product(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category);

        $commercialNearZone = $this->makeCommercial();
        $commercialFarZone = $this->makeCommercial();

        CommercialProductCommissionRate::create([
            'commercial_id' => $commercialNearZone->id,
            'product_id' => $product->id,
            'rate' => 0.0100, // 1% for near zone
        ]);

        CommercialProductCommissionRate::create([
            'commercial_id' => $commercialFarZone->id,
            'product_id' => $product->id,
            'rate' => 0.0150, // 1.5% for far zone
        ]);

        $this->assertEqualsWithDelta(0.0100, $this->service->resolveRateForCommercialAndProduct($commercialNearZone, $product), 0.00001);
        $this->assertEqualsWithDelta(0.0150, $this->service->resolveRateForCommercialAndProduct($commercialFarZone, $product), 0.00001);
    }

    // ─── Priority 3: category default rate ────────────────────────────────────

    public function test_falls_back_to_category_default_rate_when_no_commercial_override_exists(): void
    {
        $commercial = $this->makeCommercial();
        $category = $this->makeCategory('ALM', 0.0200); // 2% default
        $product = $this->makeProduct($category);

        // No commercial-specific overrides configured.
        $resolvedRate = $this->service->resolveRateForCommercialAndProduct($commercial, $product);

        $this->assertEqualsWithDelta(0.0200, $resolvedRate, 0.00001);
    }

    public function test_category_default_rate_applies_to_all_commercials_without_override(): void
    {
        $commercial1 = $this->makeCommercial();
        $commercial2 = $this->makeCommercial();
        $category = $this->makeCategory('JET', 0.0150); // 1.5% default
        $product = $this->makeProduct($category);

        // Neither commercial has a specific override.
        $this->assertEqualsWithDelta(0.0150, $this->service->resolveRateForCommercialAndProduct($commercial1, $product), 0.00001);
        $this->assertEqualsWithDelta(0.0150, $this->service->resolveRateForCommercialAndProduct($commercial2, $product), 0.00001);
    }

    public function test_commercial_category_override_takes_precedence_over_category_default_rate(): void
    {
        $commercial = $this->makeCommercial();
        $category = $this->makeCategory('HYG', 0.0200); // 2% default
        $product = $this->makeProduct($category);

        // Commercial override at 1% — must win over 2% category default.
        CommercialCategoryCommissionRate::create([
            'commercial_id' => $commercial->id,
            'product_category_id' => $category->id,
            'rate' => 0.0100,
        ]);

        $resolvedRate = $this->service->resolveRateForCommercialAndProduct($commercial, $product);

        $this->assertEqualsWithDelta(0.0100, $resolvedRate, 0.00001);
    }

    public function test_product_level_override_takes_precedence_over_category_default_rate(): void
    {
        $commercial = $this->makeCommercial();
        $category = $this->makeCategory('ALM', 0.0200); // 2% default
        $product = $this->makeProduct($category);

        // Product-level override at 0.5% — must win over 2% category default.
        CommercialProductCommissionRate::create([
            'commercial_id' => $commercial->id,
            'product_id' => $product->id,
            'rate' => 0.0050,
        ]);

        $resolvedRate = $this->service->resolveRateForCommercialAndProduct($commercial, $product);

        $this->assertEqualsWithDelta(0.0050, $resolvedRate, 0.00001);
    }

    public function test_returns_zero_when_category_has_null_commission_rate_and_no_commercial_overrides(): void
    {
        $commercial = $this->makeCommercial();
        $category = $this->makeCategory('HYG', null); // null = no default
        $product = $this->makeProduct($category);

        $resolvedRate = $this->service->resolveRateForCommercialAndProduct($commercial, $product);

        $this->assertEquals(0.0, $resolvedRate);
    }

    public function test_category_rate_does_not_spill_over_to_other_commercials(): void
    {
        $commercial1 = $this->makeCommercial();
        $commercial2 = $this->makeCommercial();
        $category = $this->makeCategory('HYG');
        $product = $this->makeProduct($category);

        // Only commercial1 has a category rate configured.
        CommercialCategoryCommissionRate::create([
            'commercial_id' => $commercial1->id,
            'product_category_id' => $category->id,
            'rate' => 0.0100,
        ]);

        // commercial2 should get 0.
        $resolvedRate = $this->service->resolveRateForCommercialAndProduct($commercial2, $product);

        $this->assertEquals(0.0, $resolvedRate);
    }
}
