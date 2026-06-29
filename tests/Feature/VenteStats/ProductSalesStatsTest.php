<?php

namespace Tests\Feature\VenteStats;

use App\Data\Vente\ProductSalesStatsDTO;
use App\Data\Vente\VenteStatsFilter;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\User;
use App\Models\Vente;
use App\Services\SalesInvoiceStatsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for SalesInvoiceStatsService::getProductSalesStats().
 *
 * This method is the single source of truth for per-product sales breakdowns.
 * It feeds the "Stats Produits" dialog on the Ventes page.
 */
class ProductSalesStatsTest extends TestCase
{
    use RefreshDatabase;

    private SalesInvoiceStatsService $salesInvoiceStatsService;

    private Product $productA;

    private Product $productB;

    private Team $defaultTeam;

    private Commercial $defaultCommercial;

    private Customer $defaultCustomer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->salesInvoiceStatsService = app(SalesInvoiceStatsService::class);
        $this->productA = $this->makeProduct('Product A', 2000, 800);
        $this->productB = $this->makeProduct('Product B', 1000, 300);
        $this->defaultTeam = $this->makeTeamWithManager();
        $this->defaultCommercial = $this->makeCommercialForTeam($this->defaultTeam);
        $this->defaultCustomer = $this->makeCustomer($this->defaultCommercial);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeProduct(string $name = 'Product', int $price = 1000, int $costPrice = 400): Product
    {
        return Product::create([
            'name' => $name,
            'price' => $price,
            'cost_price' => $costPrice,
            'base_quantity' => 1,
        ]);
    }

    private function makeTeamWithManager(): Team
    {
        return Team::create([
            'name' => 'Team '.uniqid(),
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function makeCommercialForTeam(Team $team): Commercial
    {
        $commercial = Commercial::create([
            'name' => 'Commercial '.uniqid(),
            'phone_number' => '221'.rand(700000000, 799999999),
            'gender' => 'male',
            'user_id' => User::factory()->create()->id,
        ]);
        $commercial->team()->associate($team);
        $commercial->save();

        return $commercial;
    }

    private function makeCustomer(Commercial $commercial): Customer
    {
        return Customer::create([
            'name' => 'Customer '.uniqid(),
            'address' => 'Test Address',
            'phone_number' => '221'.rand(700000000, 799999999),
            'owner_number' => '221'.rand(700000000, 799999999),
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $commercial->id,
        ]);
    }

    /**
     * Create a SalesInvoice with one INVOICE_ITEM vente, optionally backdated.
     */
    private function makeInvoiceVente(
        Product $product,
        Customer $customer,
        int $quantity,
        int $price,
        int $profit,
        ?Carbon $createdAt = null,
        ?Commercial $commercial = null,
    ): Vente {
        $commercial ??= $this->defaultCommercial;

        $invoice = SalesInvoice::create([
            'customer_id' => $customer->id,
            'commercial_id' => $commercial->id,
        ]);

        $vente = Vente::create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'quantity' => $quantity,
            'price' => $price,
            'profit' => $profit,
            'paid' => false,
            'type' => Vente::TYPE_INVOICE,
        ]);

        if ($createdAt !== null) {
            Vente::query()->where('id', $vente->id)->update(['created_at' => $createdAt]);
        }

        return $vente->fresh();
    }

    private function allFilter(): VenteStatsFilter
    {
        return VenteStatsFilter::regardlessOfPaymentStatus();
    }

    // =========================================================================
    // Tests
    // =========================================================================

    public function test_returns_empty_collection_when_no_ventes_in_period(): void
    {
        $filter = $this->allFilter()->inDateInterval(
            Carbon::today()->startOfDay(),
            Carbon::today()->endOfDay(),
        );

        $result = $this->salesInvoiceStatsService->getProductSalesStats($filter);

        $this->assertCount(0, $result);
    }

    public function test_returns_one_dto_per_product(): void
    {
        $this->makeInvoiceVente($this->productA, $this->defaultCustomer, 2, 2000, 400);
        $this->makeInvoiceVente($this->productB, $this->defaultCustomer, 3, 1000, 200);

        $result = $this->salesInvoiceStatsService->getProductSalesStats($this->allFilter());

        $this->assertCount(2, $result);
        $this->assertContainsOnlyInstancesOf(ProductSalesStatsDTO::class, $result);
    }

    public function test_aggregates_quantity_sold_per_product(): void
    {
        $this->makeInvoiceVente($this->productA, $this->defaultCustomer, 3, 2000, 600);
        $this->makeInvoiceVente($this->productA, $this->defaultCustomer, 5, 2000, 1000);

        $result = $this->salesInvoiceStatsService->getProductSalesStats($this->allFilter());

        $this->assertCount(1, $result);
        $this->assertEquals(8, $result[0]->totalQuantitySold);
    }

    public function test_aggregates_total_amount_per_product(): void
    {
        $this->makeInvoiceVente($this->productA, $this->defaultCustomer, 2, 2000, 400);
        $this->makeInvoiceVente($this->productA, $this->defaultCustomer, 3, 2000, 600);

        $result = $this->salesInvoiceStatsService->getProductSalesStats($this->allFilter());

        $this->assertCount(1, $result);
        // (2 × 2000) + (3 × 2000) = 10 000
        $this->assertEquals(10_000, $result[0]->totalAmountSold);
    }

    public function test_aggregates_total_profit_per_product(): void
    {
        $this->makeInvoiceVente($this->productA, $this->defaultCustomer, 1, 2000, 500);
        $this->makeInvoiceVente($this->productA, $this->defaultCustomer, 1, 2000, 700);

        $result = $this->salesInvoiceStatsService->getProductSalesStats($this->allFilter());

        $this->assertEquals(1_200, $result[0]->totalEstimatedProfit);
    }

    public function test_counts_distinct_customers_correctly(): void
    {
        $customerB = $this->makeCustomer($this->defaultCommercial);

        // Same customer buys productA twice — should count as 1 distinct customer
        $this->makeInvoiceVente($this->productA, $this->defaultCustomer, 1, 2000, 400);
        $this->makeInvoiceVente($this->productA, $this->defaultCustomer, 2, 2000, 400);

        // A different customer also buys productA — total distinct = 2
        $this->makeInvoiceVente($this->productA, $customerB, 1, 2000, 400);

        $result = $this->salesInvoiceStatsService->getProductSalesStats($this->allFilter());

        $this->assertCount(1, $result);
        $this->assertEquals(2, $result[0]->distinctCustomersCount);
    }

    public function test_date_range_filter_includes_only_ventes_in_period(): void
    {
        $today = Carbon::today();

        $this->makeInvoiceVente($this->productA, $this->defaultCustomer, 1, 2000, 400, $today);
        $this->makeInvoiceVente($this->productB, $this->defaultCustomer, 1, 1000, 200, $today->copy()->subDays(2));

        $filter = $this->allFilter()->inDateInterval(
            $today->copy()->startOfDay(),
            $today->copy()->endOfDay(),
        );

        $result = $this->salesInvoiceStatsService->getProductSalesStats($filter);

        $this->assertCount(1, $result);
        $this->assertEquals($this->productA->id, $result[0]->productId);
    }

    public function test_commercial_filter_excludes_other_commercials_sales(): void
    {
        $otherCommercial = $this->makeCommercialForTeam($this->defaultTeam);

        $this->makeInvoiceVente($this->productA, $this->defaultCustomer, 2, 2000, 400, null, $this->defaultCommercial);
        $this->makeInvoiceVente($this->productB, $this->defaultCustomer, 3, 1000, 200, null, $otherCommercial);

        $filter = $this->allFilter()->thatAreMadeByCommercial($this->defaultCommercial->id);
        $result = $this->salesInvoiceStatsService->getProductSalesStats($filter);

        $this->assertCount(1, $result);
        $this->assertEquals($this->productA->id, $result[0]->productId);
    }

    public function test_sales_contribution_percentage_sums_to_100_across_products(): void
    {
        // productA: 3 × 2000 = 6000, productB: 4 × 1000 = 4000 → total 10 000
        $this->makeInvoiceVente($this->productA, $this->defaultCustomer, 3, 2000, 600);
        $this->makeInvoiceVente($this->productB, $this->defaultCustomer, 4, 1000, 200);

        $result = $this->salesInvoiceStatsService->getProductSalesStats($this->allFilter());

        $totalPercentage = $result->sum(fn (ProductSalesStatsDTO $dto) => $dto->salesContributionPercentage);

        $this->assertEqualsWithDelta(100.0, $totalPercentage, 0.01);
    }

    public function test_profit_contribution_percentage_sums_to_100_across_products(): void
    {
        $this->makeInvoiceVente($this->productA, $this->defaultCustomer, 1, 2000, 600);
        $this->makeInvoiceVente($this->productB, $this->defaultCustomer, 1, 1000, 400);

        $result = $this->salesInvoiceStatsService->getProductSalesStats($this->allFilter());

        $totalPercentage = $result->sum(fn (ProductSalesStatsDTO $dto) => $dto->profitContributionPercentage);

        $this->assertEqualsWithDelta(100.0, $totalPercentage, 0.01);
    }

    public function test_correct_percentage_values_for_known_totals(): void
    {
        // productA: 6 000 XOF → 60% of 10 000
        // productB: 4 000 XOF → 40% of 10 000
        $this->makeInvoiceVente($this->productA, $this->defaultCustomer, 3, 2000, 0);
        $this->makeInvoiceVente($this->productB, $this->defaultCustomer, 4, 1000, 0);

        $result = $this->salesInvoiceStatsService->getProductSalesStats($this->allFilter());
        $byProduct = $result->keyBy('productId');

        $this->assertEquals(60.0, $byProduct[$this->productA->id]->salesContributionPercentage);
        $this->assertEquals(40.0, $byProduct[$this->productB->id]->salesContributionPercentage);
    }

    public function test_result_is_sorted_descending_by_total_amount(): void
    {
        $this->makeInvoiceVente($this->productB, $this->defaultCustomer, 4, 1000, 200); // 4 000
        $this->makeInvoiceVente($this->productA, $this->defaultCustomer, 3, 2000, 600); // 6 000

        $result = $this->salesInvoiceStatsService->getProductSalesStats($this->allFilter());

        $this->assertEquals($this->productA->id, $result[0]->productId);
        $this->assertEquals($this->productB->id, $result[1]->productId);
    }

    public function test_product_name_is_populated_from_product_model(): void
    {
        $this->makeInvoiceVente($this->productA, $this->defaultCustomer, 1, 2000, 400);

        $result = $this->salesInvoiceStatsService->getProductSalesStats($this->allFilter());

        $this->assertEquals('Product A', $result[0]->productName);
    }

    public function test_zero_profit_gives_zero_profit_contribution_percentage(): void
    {
        $this->makeInvoiceVente($this->productA, $this->defaultCustomer, 1, 2000, 0);

        $result = $this->salesInvoiceStatsService->getProductSalesStats($this->allFilter());

        $this->assertEquals(0.0, $result[0]->profitContributionPercentage);
    }

    public function test_all_dto_fields_are_correct_for_single_product(): void
    {
        // 2 ventes for productA from the same customer
        // vente 1: qty=3, price=2000 → subtotal=6 000, profit=500
        // vente 2: qty=2, price=2000 → subtotal=4 000, profit=300
        // expected totals: qty=5, amount=10 000, profit=800, 1 distinct customer
        // only one product → contributions are both 100%
        $this->makeInvoiceVente($this->productA, $this->defaultCustomer, 3, 2000, 500);
        $this->makeInvoiceVente($this->productA, $this->defaultCustomer, 2, 2000, 300);

        $result = $this->salesInvoiceStatsService->getProductSalesStats($this->allFilter());

        $this->assertCount(1, $result);

        $dto = $result[0];
        $this->assertEquals($this->productA->id, $dto->productId);
        $this->assertEquals('Product A', $dto->productName);
        $this->assertEquals(5, $dto->totalQuantitySold);
        $this->assertEquals(1, $dto->distinctCustomersCount);
        $this->assertEquals(10_000, $dto->totalAmountSold);
        $this->assertEquals(800, $dto->totalEstimatedProfit);
        $this->assertEquals(100.0, $dto->salesContributionPercentage);
        $this->assertEquals(100.0, $dto->profitContributionPercentage);
    }

    public function test_all_dto_fields_are_correct_for_multiple_products(): void
    {
        $customerB = $this->makeCustomer($this->defaultCommercial);

        // productA: 2 ventes, 2 distinct customers
        //   vente 1: qty=2, price=2000 → subtotal=4 000, profit=600
        //   vente 2: qty=3, price=2000 → subtotal=6 000, profit=900
        //   totals:  qty=5, amount=10 000, profit=1 500
        $this->makeInvoiceVente($this->productA, $this->defaultCustomer, 2, 2000, 600);
        $this->makeInvoiceVente($this->productA, $customerB, 3, 2000, 900);

        // productB: 1 vente, 1 distinct customer
        //   vente:  qty=4, price=1000 → subtotal=4 000, profit=400
        $this->makeInvoiceVente($this->productB, $this->defaultCustomer, 4, 1000, 400);

        // grand total: amount=14 000, profit=1 900
        // productA: sales%=10000/14000×100≈71.43%, profit%=1500/1900×100≈78.95%
        // productB: sales%=4000/14000×100≈28.57%, profit%=400/1900×100≈21.05%

        $result = $this->salesInvoiceStatsService->getProductSalesStats($this->allFilter());
        $this->assertCount(2, $result);

        // result is sorted desc by amount, so productA is first
        $dtoA = $result->firstWhere('productId', $this->productA->id);
        $dtoB = $result->firstWhere('productId', $this->productB->id);

        $this->assertNotNull($dtoA);
        $this->assertEquals(5, $dtoA->totalQuantitySold);
        $this->assertEquals(2, $dtoA->distinctCustomersCount);
        $this->assertEquals(10_000, $dtoA->totalAmountSold);
        $this->assertEquals(1_500, $dtoA->totalEstimatedProfit);
        $this->assertEqualsWithDelta(71.43, $dtoA->salesContributionPercentage, 0.01);
        $this->assertEqualsWithDelta(78.95, $dtoA->profitContributionPercentage, 0.01);

        $this->assertNotNull($dtoB);
        $this->assertEquals(4, $dtoB->totalQuantitySold);
        $this->assertEquals(1, $dtoB->distinctCustomersCount);
        $this->assertEquals(4_000, $dtoB->totalAmountSold);
        $this->assertEquals(400, $dtoB->totalEstimatedProfit);
        $this->assertEqualsWithDelta(28.57, $dtoB->salesContributionPercentage, 0.01);
        $this->assertEqualsWithDelta(21.05, $dtoB->profitContributionPercentage, 0.01);
    }

    public function test_to_array_returns_expected_keys(): void
    {
        $dto = new ProductSalesStatsDTO(
            productId: 1,
            productName: 'Test',
            totalQuantitySold: 5,
            distinctCustomersCount: 2,
            totalAmountSold: 10_000,
            totalEstimatedProfit: 3_000,
            salesContributionPercentage: 100.0,
            profitContributionPercentage: 100.0,
        );

        $array = $dto->toArray();

        $this->assertArrayHasKey('product_id', $array);
        $this->assertArrayHasKey('product_name', $array);
        $this->assertArrayHasKey('total_quantity_sold', $array);
        $this->assertArrayHasKey('distinct_customers_count', $array);
        $this->assertArrayHasKey('total_amount_sold', $array);
        $this->assertArrayHasKey('total_estimated_profit', $array);
        $this->assertArrayHasKey('sales_contribution_percentage', $array);
        $this->assertArrayHasKey('profit_contribution_percentage', $array);
    }
}
