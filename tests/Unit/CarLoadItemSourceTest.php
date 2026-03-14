<?php

namespace Tests\Unit;

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

class CarLoadItemSourceTest extends TestCase
{
    use RefreshDatabase;

    private CarLoadService $carLoadService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carLoadService = app(CarLoadService::class);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    private function makeActiveCarLoad(): CarLoad
    {
        $user = User::factory()->create();
        $team = Team::create(['name' => 'Team '.uniqid(), 'user_id' => $user->id]);

        return CarLoad::create([
            'name' => 'CarLoad '.uniqid(),
            'team_id' => $team->id,
            'status' => CarLoadStatus::Selling,
            'load_date' => Carbon::now()->subDay(),
            'return_date' => Carbon::now()->addDay(),
            'returned' => false,
        ]);
    }

    private function makeCarLoadItemWithSource(CarLoad $carLoad, Product $product, int $quantityLoaded, CarLoadItemSource $source): CarLoadItem
    {
        return CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $product->id,
            'quantity_loaded' => $quantityLoaded,
            'quantity_left' => $quantityLoaded,
            'loaded_at' => now(),
            'source' => $source,
        ]);
    }

    // ─── Enum helper methods ─────────────────────────────────────────────────────

    public function test_warehouse_source_is_correctly_identified(): void
    {
        $source = CarLoadItemSource::Warehouse;

        $this->assertTrue($source->isFromWarehouse());
        $this->assertFalse($source->isTransformed());
        $this->assertFalse($source->isFromPreviousCarLoad());
        $this->assertFalse($source->isInternalTransfer());
    }

    public function test_transformed_from_parent_source_is_correctly_identified(): void
    {
        $source = CarLoadItemSource::TransformedFromParent;

        $this->assertFalse($source->isFromWarehouse());
        $this->assertTrue($source->isTransformed());
        $this->assertFalse($source->isFromPreviousCarLoad());
        $this->assertTrue($source->isInternalTransfer());
    }

    public function test_from_previous_car_load_source_is_correctly_identified(): void
    {
        $source = CarLoadItemSource::FromPreviousCarLoad;

        $this->assertFalse($source->isFromWarehouse());
        $this->assertFalse($source->isTransformed());
        $this->assertTrue($source->isFromPreviousCarLoad());
        $this->assertTrue($source->isInternalTransfer());
    }

    // ─── Model persistence ───────────────────────────────────────────────────────

    public function test_car_load_item_created_via_add_item_has_warehouse_source(): void
    {
        $carLoad = $this->makeActiveCarLoad();
        $product = Product::create(['name' => 'Carton 1KG', 'price' => 1000, 'cost_price' => 500, 'base_quantity' => 20, 'stock_available' => 100]);

        $item = $this->carLoadService->addItem($carLoad, [
            'product_id' => $product->id,
            'quantity_loaded' => 10,
        ]);

        $this->assertEquals(CarLoadItemSource::Warehouse, $item->fresh()->source);
    }

    public function test_car_load_item_default_source_is_warehouse(): void
    {
        $carLoad = $this->makeActiveCarLoad();
        $product = Product::create(['name' => 'Produit Test', 'price' => 500, 'cost_price' => 200, 'stock_available' => 50]);

        $item = CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $product->id,
            'quantity_loaded' => 5,
            'quantity_left' => 5,
            'loaded_at' => now(),
            // source intentionally omitted — should default to 'warehouse'
        ]);

        $this->assertEquals(CarLoadItemSource::Warehouse, $item->fresh()->source);
    }

    public function test_transformed_item_is_persisted_with_transformed_from_parent_source(): void
    {
        $carLoad = $this->makeActiveCarLoad();
        $product = Product::create(['name' => 'Pack 20pcs', 'price' => 100, 'cost_price' => 50, 'stock_available' => 0]);

        $item = $this->makeCarLoadItemWithSource($carLoad, $product, 20, CarLoadItemSource::TransformedFromParent);

        $this->assertEquals(CarLoadItemSource::TransformedFromParent, $item->fresh()->source);
    }

    public function test_from_previous_car_load_item_is_persisted_with_correct_source(): void
    {
        $carLoad = $this->makeActiveCarLoad();
        $product = Product::create(['name' => 'Carton A', 'price' => 800, 'cost_price' => 400, 'stock_available' => 0]);

        $item = $this->makeCarLoadItemWithSource($carLoad, $product, 3, CarLoadItemSource::FromPreviousCarLoad);

        $this->assertEquals(CarLoadItemSource::FromPreviousCarLoad, $item->fresh()->source);
    }

    // ─── Distinction between warehouse and transformed children ─────────────────

    public function test_can_filter_only_warehouse_loaded_items_in_car_load(): void
    {
        $carLoad = $this->makeActiveCarLoad();
        $productA = Product::create(['name' => 'Carton 1KG', 'price' => 1000, 'cost_price' => 500, 'stock_available' => 50]);
        $productB = Product::create(['name' => '1KG 20pcs', 'price' => 100, 'cost_price' => 50, 'stock_available' => 0]);
        $productC = Product::create(['name' => 'Remaining Carton', 'price' => 900, 'cost_price' => 450, 'stock_available' => 0]);

        $this->makeCarLoadItemWithSource($carLoad, $productA, 10, CarLoadItemSource::Warehouse);
        $this->makeCarLoadItemWithSource($carLoad, $productB, 40, CarLoadItemSource::TransformedFromParent);
        $this->makeCarLoadItemWithSource($carLoad, $productC, 2, CarLoadItemSource::FromPreviousCarLoad);

        $warehouseItems = $carLoad->items()->where('source', CarLoadItemSource::Warehouse->value)->get();
        $transformedItems = $carLoad->items()->where('source', CarLoadItemSource::TransformedFromParent->value)->get();
        $previousCarLoadItems = $carLoad->items()->where('source', CarLoadItemSource::FromPreviousCarLoad->value)->get();

        $this->assertCount(1, $warehouseItems);
        $this->assertEquals($productA->id, $warehouseItems->first()->product_id);

        $this->assertCount(1, $transformedItems);
        $this->assertEquals($productB->id, $transformedItems->first()->product_id);

        $this->assertCount(1, $previousCarLoadItems);
        $this->assertEquals($productC->id, $previousCarLoadItems->first()->product_id);
    }

    public function test_transformed_items_are_excluded_from_internal_transfer_false_check(): void
    {
        // Transformed and FromPreviousCarLoad are both "internal transfers" — not from warehouse
        $this->assertTrue(CarLoadItemSource::TransformedFromParent->isInternalTransfer());
        $this->assertTrue(CarLoadItemSource::FromPreviousCarLoad->isInternalTransfer());
        $this->assertFalse(CarLoadItemSource::Warehouse->isInternalTransfer());
    }

    public function test_enum_string_values_are_stable(): void
    {
        // These string values are stored in the database — they must never change
        $this->assertEquals('warehouse', CarLoadItemSource::Warehouse->value);
        $this->assertEquals('transformed_from_parent', CarLoadItemSource::TransformedFromParent->value);
        $this->assertEquals('from_previous_car_load', CarLoadItemSource::FromPreviousCarLoad->value);
    }
}
