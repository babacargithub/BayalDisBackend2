<?php

namespace Tests\Feature\Abc;

use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockEntry;
use App\Models\Vente;
use App\Models\Warehouse;
use App\Services\SalesInvoiceStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests that transportation_cost and packaging_cost on StockEntry are correctly
 * included in the historical weighted-average cost used for backfill profit calculation.
 *
 * All tests use calculateProfitForVenteFromHistoricalAverage() — the backfill path
 * that reads from StockEntry records. Real-time invoice creation uses
 * calculateProfitForVente() (FIFO from CarLoadItem) instead.
 *
 * The profit formula is:
 *   profit = (selling_price - effective_unit_cost) * quantity
 * where:
 *   effective_unit_cost = weighted_avg(unit_price + transportation_cost + packaging_cost)
 */
class StockEntryTransportPackagingProfitTest extends TestCase
{
    use RefreshDatabase;

    private SalesInvoiceStatsService $statsService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->statsService = app(SalesInvoiceStatsService::class);
    }

    private function makeProduct(int $costPrice = 6_000): Product
    {
        return Product::create([
            'name' => 'Produit Test',
            'price' => 8_000,
            'cost_price' => $costPrice,
        ]);
    }

    private function makeWarehouse(): Warehouse
    {
        return Warehouse::factory()->create();
    }

    private function makeCustomer(): Customer
    {
        $commercial = Commercial::create([
            'name' => 'Commercial Test',
            'phone_number' => '221'.rand(700000000, 799999999),
            'gender' => 'male',
        ]);

        return Customer::create([
            'name' => 'Client Test',
            'phone_number' => '221'.rand(700000000, 799999999),
            'owner_number' => '221'.rand(700000000, 799999999),
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $commercial->id,
        ]);
    }

    private function makeVente(Product $product, int $price, int $quantity): Vente
    {
        $customer = $this->makeCustomer();

        return Vente::create([
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'price' => $price,
            'quantity' => $quantity,
            'profit' => 0,
        ]);
    }

    public function test_profit_includes_transportation_cost_in_effective_unit_cost(): void
    {
        $product = $this->makeProduct(costPrice: 6_000);
        $warehouse = $this->makeWarehouse();

        StockEntry::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 100,
            'quantity_left' => 100,
            'unit_price' => 6_000,
            'transportation_cost' => 113,
            'packaging_cost' => 0,
        ]);

        $vente = $this->makeVente($product, price: 8_889, quantity: 1);

        // effective_cost = 6000 + 113 + 0 = 6113
        // profit = (8889 - 6113) * 1 = 2776
        $expectedProfit = (8_889 - 6_113) * 1;

        $this->assertEquals($expectedProfit, $this->statsService->calculateProfitForVenteFromHistoricalAverage($vente));
    }

    public function test_profit_includes_packaging_cost_in_effective_unit_cost(): void
    {
        $product = $this->makeProduct(costPrice: 6_000);
        $warehouse = $this->makeWarehouse();

        StockEntry::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 100,
            'quantity_left' => 100,
            'unit_price' => 6_000,
            'transportation_cost' => 0,
            'packaging_cost' => 15,
        ]);

        $vente = $this->makeVente($product, price: 8_889, quantity: 1);

        // effective_cost = 6000 + 0 + 15 = 6015
        $expectedProfit = (8_889 - 6_015) * 1;

        $this->assertEquals($expectedProfit, $this->statsService->calculateProfitForVenteFromHistoricalAverage($vente));
    }

    public function test_profit_includes_both_transportation_and_packaging_cost(): void
    {
        $product = $this->makeProduct(costPrice: 6_000);
        $warehouse = $this->makeWarehouse();

        StockEntry::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 150,
            'quantity_left' => 150,
            'unit_price' => 6_000,
            'transportation_cost' => 113,
            'packaging_cost' => 15,
        ]);

        $vente = $this->makeVente($product, price: 8_889, quantity: 150);

        // effective_cost = 6000 + 113 + 15 = 6128
        // profit = (8889 - 6128) * 150 = 414,150
        $expectedProfit = (int) round((8_889 - 6_128) * 150);

        $this->assertEquals($expectedProfit, $this->statsService->calculateProfitForVenteFromHistoricalAverage($vente));
    }

    public function test_profit_uses_weighted_average_when_multiple_stock_entries_exist(): void
    {
        $product = $this->makeProduct(costPrice: 6_000);
        $warehouse = $this->makeWarehouse();

        // Batch 1: unit_price=6000, transport=113, packaging=13 → total=6126
        StockEntry::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 100,
            'quantity_left' => 100,
            'unit_price' => 6_000,
            'transportation_cost' => 113,
            'packaging_cost' => 13,
            'created_at' => now()->subDays(30),
        ]);

        // Batch 2: unit_price=6200, transport=95, packaging=15 → total=6310
        StockEntry::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 100,
            'quantity_left' => 100,
            'unit_price' => 6_200,
            'transportation_cost' => 95,
            'packaging_cost' => 15,
            'created_at' => now()->subDays(10),
        ]);

        $vente = $this->makeVente($product, price: 9_000, quantity: 1);

        // Weighted average total cost:
        // total_value = 100*(6000+113+13) + 100*(6200+95+15) = 100*6126 + 100*6310
        // total_qty   = 200
        // weighted_avg = (612600 + 631000) / 200 = 1243600 / 200 = 6218
        $expectedWeightedCost = (100 * 6_126 + 100 * 6_310) / 200;
        $expectedProfit = (int) round((9_000 - $expectedWeightedCost) * 1);

        $this->assertEquals($expectedProfit, $this->statsService->calculateProfitForVenteFromHistoricalAverage($vente));
    }

    public function test_profit_falls_back_to_product_cost_price_when_no_stock_entries(): void
    {
        $product = $this->makeProduct(costPrice: 6_000);
        $vente = $this->makeVente($product, price: 8_889, quantity: 1);

        // No stock entries — falls back to product.cost_price (no transport/packaging)
        $expectedProfit = (8_889 - 6_000) * 1;

        $this->assertEquals($expectedProfit, $this->statsService->calculateProfitForVenteFromHistoricalAverage($vente));
    }

    public function test_profit_is_zero_for_zero_cost_packaging_product(): void
    {
        $product = $this->makeProduct(costPrice: 6_000);
        $warehouse = $this->makeWarehouse();

        // packaging_cost = 0 (pre-packaged product)
        StockEntry::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 100,
            'quantity_left' => 100,
            'unit_price' => 6_000,
            'transportation_cost' => 0,
            'packaging_cost' => 0,
        ]);

        $vente = $this->makeVente($product, price: 6_000, quantity: 5);

        // price = cost → profit = 0
        $this->assertEquals(0, $this->statsService->calculateProfitForVenteFromHistoricalAverage($vente));
    }
}
