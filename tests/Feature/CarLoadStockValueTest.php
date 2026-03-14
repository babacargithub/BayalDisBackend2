<?php

namespace Tests\Feature;

use App\Enums\CarLoadItemSource;
use App\Enums\CarLoadStatus;
use App\Models\CarLoad;
use App\Models\CarLoadItem;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use App\Services\CarLoadService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CarLoadStockValueTest extends TestCase
{
    use RefreshDatabase;

    private CarLoadService $carLoadService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carLoadService = app(CarLoadService::class);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    private function makeCarLoad(CarLoadStatus $status = CarLoadStatus::Selling): CarLoad
    {
        $user = User::factory()->create();
        $team = Team::create(['name' => 'Team '.uniqid(), 'user_id' => $user->id]);

        return CarLoad::create([
            'name' => 'CarLoad '.uniqid(),
            'team_id' => $team->id,
            'status' => $status,
            'load_date' => Carbon::now()->subDay(),
            'return_date' => Carbon::now()->addDay(),
            'returned' => false,
        ]);
    }

    private function makeProduct(string $name, int $costPrice): Product
    {
        return Product::create([
            'name' => $name,
            'price' => $costPrice * 2,
            'cost_price' => $costPrice,
            'stock_available' => 0,
        ]);
    }

    private function addItemToCarLoad(CarLoad $carLoad, Product $product, int $quantityLoaded, int $quantityLeft, CarLoadItemSource $source = CarLoadItemSource::Warehouse): CarLoadItem
    {
        return CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $product->id,
            'quantity_loaded' => $quantityLoaded,
            'quantity_left' => $quantityLeft,
            'loaded_at' => now(),
            'source' => $source,
        ]);
    }

    // ─── Tests ───────────────────────────────────────────────────────────────────

    public function test_stock_value_sums_quantity_left_times_cost_price_across_all_items(): void
    {
        $carLoad = $this->makeCarLoad();
        $productA = $this->makeProduct('Carton Lait 1KG', 5_000);   // 3 left → 15 000
        $productB = $this->makeProduct('Bouteille Huile 1L', 2_000); // 10 left → 20 000

        $this->addItemToCarLoad($carLoad, $productA, 10, 3);
        $this->addItemToCarLoad($carLoad, $productB, 20, 10);

        $this->assertEquals(35_000, $this->carLoadService->calculateCarLoadStockValue($carLoad));
    }

    public function test_stock_value_is_zero_when_all_items_have_zero_quantity_left(): void
    {
        $carLoad = $this->makeCarLoad();
        $product = $this->makeProduct('Carton Farine 5KG', 10_000);

        $this->addItemToCarLoad($carLoad, $product, 5, 0);

        $this->assertEquals(0, $this->carLoadService->calculateCarLoadStockValue($carLoad));
    }

    public function test_stock_value_is_zero_when_car_load_has_no_items(): void
    {
        $carLoad = $this->makeCarLoad();

        $this->assertEquals(0, $this->carLoadService->calculateCarLoadStockValue($carLoad));
    }

    public function test_stock_value_is_always_zero_for_terminated_and_transferred_car_load(): void
    {
        // Even if quantity_left was not zeroed (defensive guard), terminated car loads return 0
        $carLoad = $this->makeCarLoad(CarLoadStatus::TerminatedAndTransferred);
        $product = $this->makeProduct('Carton Sucre 50KG', 25_000);

        $this->addItemToCarLoad($carLoad, $product, 10, 5);

        $this->assertEquals(0, $this->carLoadService->calculateCarLoadStockValue($carLoad));
    }

    public function test_stock_value_includes_rolled_over_items_from_previous_car_load(): void
    {
        $carLoad = $this->makeCarLoad();
        $product = $this->makeProduct('Carton Tomate', 3_000);

        // 4 items physically still in vehicle from previous cycle — count toward current stock
        $this->addItemToCarLoad($carLoad, $product, 4, 4, CarLoadItemSource::FromPreviousCarLoad);

        $this->assertEquals(12_000, $this->carLoadService->calculateCarLoadStockValue($carLoad)); // 4 × 3 000
    }

    public function test_stock_value_includes_transformed_variant_items(): void
    {
        $carLoad = $this->makeCarLoad();
        $variantProduct = $this->makeProduct('1KG 20pcs', 500);

        // 15 packs created by transformation — physically in the vehicle, count toward value
        $this->addItemToCarLoad($carLoad, $variantProduct, 15, 15, CarLoadItemSource::TransformedFromParent);

        $this->assertEquals(7_500, $this->carLoadService->calculateCarLoadStockValue($carLoad)); // 15 × 500
    }

    public function test_stock_value_combines_multiple_fifo_batches_of_same_product(): void
    {
        $carLoad = $this->makeCarLoad();
        $product = $this->makeProduct('Carton Café 250g', 4_000);

        $this->addItemToCarLoad($carLoad, $product, 10, 2); // first batch: 2 left
        $this->addItemToCarLoad($carLoad, $product, 8, 8);  // second batch: 8 left

        $this->assertEquals(40_000, $this->carLoadService->calculateCarLoadStockValue($carLoad)); // (2 + 8) × 4 000
    }

    public function test_stock_value_ignores_fully_sold_batches_in_mixed_fifo_scenario(): void
    {
        $carLoad = $this->makeCarLoad();
        $product = $this->makeProduct('Bouteille Eau 1.5L', 1_000);

        $this->addItemToCarLoad($carLoad, $product, 20, 0); // first batch: fully sold
        $this->addItemToCarLoad($carLoad, $product, 15, 7); // second batch: 7 left

        $this->assertEquals(7_000, $this->carLoadService->calculateCarLoadStockValue($carLoad)); // 7 × 1 000
    }

    public function test_stock_value_returns_integer_type(): void
    {
        $carLoad = $this->makeCarLoad();
        $product = $this->makeProduct('Produit Test', 3_333);

        $this->addItemToCarLoad($carLoad, $product, 3, 3);

        $stockValue = $this->carLoadService->calculateCarLoadStockValue($carLoad);

        $this->assertIsInt($stockValue);
        $this->assertEquals(9_999, $stockValue);
    }
}
