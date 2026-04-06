<?php

namespace Tests\Feature\CarLoad;

use App\Enums\CarLoadItemSource;
use App\Enums\CarLoadStatus;
use App\Models\CarLoad;
use App\Models\CarLoadInventory;
use App\Models\CarLoadItem;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\StockEntry;
use App\Models\Team;
use App\Models\User;
use App\Models\Vente;
use App\Services\CarLoadService;
use App\Services\ProductService;
use App\Services\SalesInvoiceStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for FIFO cost price propagation through the CarLoadItem chain.
 *
 * Covers:
 *  - consumeWarehouseStockInFifoReturningBatchCosts: correct batches consumed, costs returned
 *  - createItemsToCarLoad: one CarLoadItem per batch, cost_price_per_unit locked
 *  - createItemsToCarLoad: quantity spanning multiple batches splits into multiple items
 *  - transformToVariants: paquet cost = (parent_cost / paquets_per_carton) + packaging_cost
 *  - transformToVariants: null parent cost propagates as null (legacy)
 *  - computeFifoWeightedCostPriceForQuantityInCarLoad: weighted average across batches
 *  - computeFifoWeightedCostPriceForQuantityInCarLoad: returns null for legacy items
 *  - createFromInventory: cost_price_per_unit is carried over via FIFO weighted average
 *  - SalesInvoiceStatsService: profit uses CarLoadItem cost when available
 *  - SalesInvoiceStatsService: falls back to weighted-average when CarLoadItem has null cost
 *  - SalesInvoiceStatsService: falls back to weighted-average when no car_load_id on invoice
 */
class CarLoadItemCostPriceTest extends TestCase
{
    use RefreshDatabase;

    private CarLoadService $carLoadService;

    private ProductService $productService;

    private SalesInvoiceStatsService $statsService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carLoadService = app(CarLoadService::class);
        $this->productService = app(ProductService::class);
        $this->statsService = app(SalesInvoiceStatsService::class);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeTeam(): Team
    {
        $manager = User::factory()->create();

        return Team::create(['name' => 'Test Team '.rand(1000, 9999), 'user_id' => $manager->id]);
    }

    private function makeCarLoad(Team $team): CarLoad
    {
        return CarLoad::create([
            'name' => 'Test Load',
            'team_id' => $team->id,
            'status' => CarLoadStatus::Loading,
            'load_date' => now(),
            'return_date' => now()->addDays(5),
        ]);
    }

    private function makeProduct(string $name = 'Product', int $costPrice = 1000, int $packagingCost = 0): Product
    {
        return Product::factory()->create([
            'name' => $name,
            'cost_price' => $costPrice,
            'packaging_cost' => $packagingCost,
            'price' => 2000,
            'parent_id' => null,
        ]);
    }

    private function makeStockEntry(Product $product, int $quantity, int $unitPrice, int $transportationCost = 0, int $packagingCost = 0): StockEntry
    {
        return StockEntry::create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'quantity_left' => $quantity,
            'unit_price' => $unitPrice,
            'transportation_cost' => $transportationCost,
            'packaging_cost' => $packagingCost,
        ]);
    }

    private function makeCommercialAndCustomer(Team $team): array
    {
        $manager = User::factory()->create();
        $commercial = Commercial::create([
            'name' => 'Commercial '.rand(1000, 9999),
            'phone_number' => '221700'.rand(100000, 999999),
            'gender' => 'male',
            'user_id' => $manager->id,
        ]);
        $commercial->team()->associate($team);
        $commercial->save();

        $customer = Customer::create([
            'name' => 'Customer '.rand(1000, 9999),
            'phone_number' => '221700'.rand(100000, 999999),
            'owner_number' => '221700'.rand(100000, 999999),
            'address' => 'Test Address',
            'gps_coordinates' => '0,0,0',
            'commercial_id' => $commercial->id,
        ]);

        return [$commercial, $customer];
    }

    private function makeVenteLinkedToCarLoad(Product $product, CarLoad $carLoad, int $price, int $quantity): Vente
    {
        [$commercial, $customer] = $this->makeCommercialAndCustomer($carLoad->team);

        $invoice = SalesInvoice::create([
            'customer_id' => $customer->id,
            'car_load_id' => $carLoad->id,
            'commercial_id' => $commercial->id,
        ]);

        $vente = new Vente([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $price,
            'type' => Vente::TYPE_INVOICE,
            'sales_invoice_id' => $invoice->id,
        ]);
        $vente->save();
        $vente->load('salesInvoice', 'product');

        return $vente;
    }

    private function makeVenteWithoutCarLoad(Product $product, int $price, int $quantity): Vente
    {
        $team = $this->makeTeam();
        [$commercial, $customer] = $this->makeCommercialAndCustomer($team);

        $invoice = SalesInvoice::create([
            'customer_id' => $customer->id,
            'car_load_id' => null,
            'commercial_id' => $commercial->id,
        ]);

        $vente = new Vente([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $price,
            'type' => Vente::TYPE_INVOICE,
            'sales_invoice_id' => $invoice->id,
        ]);
        $vente->save();
        $vente->load('salesInvoice', 'product');

        return $vente;
    }

    // =========================================================================
    // consumeWarehouseStockInFifoReturningBatchCosts
    // =========================================================================

    public function test_consume_warehouse_stock_returns_single_batch_with_full_unit_cost(): void
    {
        $product = $this->makeProduct();
        $this->makeStockEntry($product, 100, unitPrice: 5_000, transportationCost: 200, packagingCost: 50);

        $batches = $this->productService->consumeWarehouseStockInFifoReturningBatchCosts($product, 40);

        $this->assertCount(1, $batches);
        $this->assertEquals(40, $batches[0]['quantity']);
        $this->assertEquals(5_250, $batches[0]['cost_price_per_unit']); // 5000+200+50
    }

    public function test_consume_warehouse_stock_returns_two_batches_when_quantity_spans_two_entries(): void
    {
        $product = $this->makeProduct();
        $this->makeStockEntry($product, 50, unitPrice: 4_000); // older
        $this->makeStockEntry($product, 50, unitPrice: 6_000); // newer

        $batches = $this->productService->consumeWarehouseStockInFifoReturningBatchCosts($product, 70);

        $this->assertCount(2, $batches);
        $this->assertEquals(50, $batches[0]['quantity']);
        $this->assertEquals(4_000, $batches[0]['cost_price_per_unit']);
        $this->assertEquals(20, $batches[1]['quantity']);
        $this->assertEquals(6_000, $batches[1]['cost_price_per_unit']);
    }

    public function test_consume_warehouse_stock_decrements_quantity_left_on_stock_entry(): void
    {
        $product = $this->makeProduct();
        $entry = $this->makeStockEntry($product, 100, unitPrice: 5_000);

        $this->productService->consumeWarehouseStockInFifoReturningBatchCosts($product, 30);

        $this->assertEquals(70, $entry->fresh()->quantity_left);
    }

    public function test_consume_warehouse_stock_fully_exhausts_older_batch_before_touching_newer(): void
    {
        $product = $this->makeProduct();
        $older = $this->makeStockEntry($product, 30, unitPrice: 4_000);
        $newer = $this->makeStockEntry($product, 30, unitPrice: 7_000);

        $this->productService->consumeWarehouseStockInFifoReturningBatchCosts($product, 30);

        $this->assertEquals(0, $older->fresh()->quantity_left);
        $this->assertEquals(30, $newer->fresh()->quantity_left);
    }

    // =========================================================================
    // createItemsToCarLoad — cost_price_per_unit locking
    // =========================================================================

    public function test_create_items_to_car_load_sets_cost_price_per_unit_from_single_batch(): void
    {
        $team = $this->makeTeam();
        $carLoad = $this->makeCarLoad($team);
        $product = $this->makeProduct();
        $this->makeStockEntry($product, 100, unitPrice: 8_000, transportationCost: 300, packagingCost: 100);

        $this->carLoadService->createItemsToCarLoad($carLoad, [
            ['product_id' => $product->id, 'quantity_loaded' => 50, 'quantity_left' => 50],
        ]);

        $item = $carLoad->items()->where('product_id', $product->id)->sole();
        $this->assertEquals(50, $item->quantity_loaded);
        $this->assertEquals(8_400, $item->cost_price_per_unit); // 8000+300+100
        $this->assertEquals(CarLoadItemSource::Warehouse, $item->source);
    }

    public function test_create_items_to_car_load_splits_into_multiple_items_when_spanning_two_batches(): void
    {
        $team = $this->makeTeam();
        $carLoad = $this->makeCarLoad($team);
        $product = $this->makeProduct();
        $this->makeStockEntry($product, 30, unitPrice: 4_000); // older
        $this->makeStockEntry($product, 30, unitPrice: 7_000); // newer

        $this->carLoadService->createItemsToCarLoad($carLoad, [
            ['product_id' => $product->id, 'quantity_loaded' => 50, 'quantity_left' => 50],
        ]);

        $items = $carLoad->items()->where('product_id', $product->id)->orderBy('id')->get();
        $this->assertCount(2, $items);
        $this->assertEquals(30, $items[0]->quantity_loaded);
        $this->assertEquals(4_000, $items[0]->cost_price_per_unit);
        $this->assertEquals(20, $items[1]->quantity_loaded);
        $this->assertEquals(7_000, $items[1]->cost_price_per_unit);
    }

    public function test_create_items_to_car_load_without_decrement_flag_sets_null_cost(): void
    {
        $team = $this->makeTeam();
        $carLoad = $this->makeCarLoad($team);
        $product = $this->makeProduct();

        $this->carLoadService->createItemsToCarLoad($carLoad, [
            ['product_id' => $product->id, 'quantity_loaded' => 20, 'quantity_left' => 20],
        ], decrementWarehouseStock: false);

        $item = $carLoad->items()->where('product_id', $product->id)->sole();
        $this->assertNull($item->cost_price_per_unit);
    }

    // =========================================================================
    // computeFifoWeightedCostPriceForQuantityInCarLoad
    // =========================================================================

    public function test_compute_fifo_weighted_cost_returns_cost_when_single_batch_item(): void
    {
        $team = $this->makeTeam();
        $carLoad = $this->makeCarLoad($team);
        $product = $this->makeProduct();

        CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $product->id,
            'quantity_loaded' => 50,
            'quantity_left' => 50,
            'cost_price_per_unit' => 6_000,
            'loaded_at' => now(),
            'source' => CarLoadItemSource::Warehouse,
        ]);

        $cost = $this->carLoadService->computeFifoWeightedCostPriceForQuantityInCarLoad($product, 30, $carLoad);

        $this->assertEquals(6_000, $cost);
    }

    public function test_compute_fifo_weighted_cost_returns_weighted_average_across_two_batches(): void
    {
        $team = $this->makeTeam();
        $carLoad = $this->makeCarLoad($team);
        $product = $this->makeProduct();

        CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $product->id,
            'quantity_loaded' => 40,
            'quantity_left' => 40,
            'cost_price_per_unit' => 4_000,
            'loaded_at' => now()->subDay(),
            'source' => CarLoadItemSource::Warehouse,
        ]);

        CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $product->id,
            'quantity_loaded' => 60,
            'quantity_left' => 60,
            'cost_price_per_unit' => 8_000,
            'loaded_at' => now(),
            'source' => CarLoadItemSource::Warehouse,
        ]);

        // Consuming 60: 40 units at 4000 + 20 units at 8000
        // weighted avg = (40×4000 + 20×8000) / 60 = 320_000 / 60 ≈ 5333
        $cost = $this->carLoadService->computeFifoWeightedCostPriceForQuantityInCarLoad($product, 60, $carLoad);

        $this->assertEquals(5_333, $cost);
    }

    public function test_compute_fifo_weighted_cost_returns_null_when_item_has_null_cost(): void
    {
        $team = $this->makeTeam();
        $carLoad = $this->makeCarLoad($team);
        $product = $this->makeProduct();

        CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $product->id,
            'quantity_loaded' => 50,
            'quantity_left' => 50,
            'cost_price_per_unit' => null,
            'loaded_at' => now(),
            'source' => CarLoadItemSource::Warehouse,
        ]);

        $cost = $this->carLoadService->computeFifoWeightedCostPriceForQuantityInCarLoad($product, 30, $carLoad);

        $this->assertNull($cost);
    }

    // =========================================================================
    // transformToVariants — cost propagation to paquets
    // =========================================================================

    public function test_transform_carton_to_paquets_sets_cost_including_packaging_cost(): void
    {
        $team = $this->makeTeam();
        $carLoad = $this->makeCarLoad($team);

        // Carton: 12 paquets per carton
        $carton = Product::factory()->create([
            'name' => 'Carton',
            'cost_price' => 12_000,
            'packaging_cost' => 0,
            'base_quantity' => 12,
            'parent_id' => null,
            'price' => 15_000,
        ]);

        // Paquet: packaging_cost = 50 per unit (plastic bag)
        $paquet = Product::factory()->create([
            'name' => 'Paquet',
            'cost_price' => 1_000,
            'packaging_cost' => 50,
            'base_quantity' => 1,
            'parent_id' => $carton->id,
            'price' => 1_500,
        ]);

        CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $carton->id,
            'quantity_loaded' => 5,
            'quantity_left' => 5,
            'cost_price_per_unit' => 7_200, // XOF per carton
            'loaded_at' => now(),
            'source' => CarLoadItemSource::Warehouse,
        ]);

        // Transform 2 cartons → 24 paquets
        $this->carLoadService->transformToVariants($carton, [
            'quantityOfBaseProductToTransform' => 2,
            'items' => [
                ['product_id' => $paquet->id, 'quantity' => 24, 'unused_quantity' => 0],
            ],
        ], $carLoad);

        $paquetItem = $carLoad->items()->where('product_id', $paquet->id)->sole();

        // cost_per_paquet = round(7200 / 12) = 600, + 50 packaging = 650
        $this->assertEquals(24, $paquetItem->quantity_loaded);
        $this->assertEquals(650, $paquetItem->cost_price_per_unit);
        $this->assertEquals(CarLoadItemSource::TransformedFromParent, $paquetItem->source);
    }

    public function test_transform_carton_to_paquets_sets_null_cost_when_parent_item_has_no_cost(): void
    {
        $team = $this->makeTeam();
        $carLoad = $this->makeCarLoad($team);

        $carton = Product::factory()->create([
            'name' => 'Carton Legacy',
            'cost_price' => 12_000,
            'packaging_cost' => 0,
            'base_quantity' => 12,
            'parent_id' => null,
            'price' => 15_000,
        ]);

        $paquet = Product::factory()->create([
            'name' => 'Paquet Legacy',
            'cost_price' => 1_000,
            'packaging_cost' => 50,
            'base_quantity' => 1,
            'parent_id' => $carton->id,
            'price' => 1_500,
        ]);

        CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $carton->id,
            'quantity_loaded' => 5,
            'quantity_left' => 5,
            'cost_price_per_unit' => null, // legacy item
            'loaded_at' => now(),
            'source' => CarLoadItemSource::Warehouse,
        ]);

        $this->carLoadService->transformToVariants($carton, [
            'quantityOfBaseProductToTransform' => 2,
            'items' => [
                ['product_id' => $paquet->id, 'quantity' => 24, 'unused_quantity' => 0],
            ],
        ], $carLoad);

        $paquetItem = $carLoad->items()->where('product_id', $paquet->id)->sole();
        $this->assertNull($paquetItem->cost_price_per_unit);
    }

    // =========================================================================
    // createFromInventory — cost carried over via FIFO weighted average
    // =========================================================================

    public function test_rollover_car_load_carries_cost_price_per_unit_to_new_car_load(): void
    {
        $team = $this->makeTeam();
        $manager = User::factory()->create();
        $previousCarLoad = CarLoad::create([
            'name' => 'Previous Load',
            'team_id' => $team->id,
            'status' => CarLoadStatus::FullInventory,
            'load_date' => now()->subDays(10),
            'return_date' => now()->subDay(),
        ]);

        $product = $this->makeProduct();

        CarLoadItem::create([
            'car_load_id' => $previousCarLoad->id,
            'product_id' => $product->id,
            'quantity_loaded' => 30,
            'quantity_left' => 10,
            'cost_price_per_unit' => 5_500,
            'loaded_at' => now()->subDays(10),
            'source' => CarLoadItemSource::Warehouse,
        ]);

        $inventory = CarLoadInventory::create([
            'car_load_id' => $previousCarLoad->id,
            'name' => 'Inventaire Clôturé',
            'user_id' => $manager->id,
            'closed' => true,
        ]);
        $inventory->items()->create([
            'product_id' => $product->id,
            'total_loaded' => 30,
            'total_sold' => 20,
            'total_returned' => 10,
        ]);

        $newCarLoad = $this->carLoadService->createFromInventory($inventory);

        $newItem = $newCarLoad->items()->where('product_id', $product->id)->sole();
        $this->assertEquals(10, $newItem->quantity_loaded, 'New item quantity must equal total_returned, not total_loaded');
        $this->assertEquals(5_500, $newItem->cost_price_per_unit, 'FIFO weighted cost must be carried over to the new car load item');
        $this->assertEquals(CarLoadItemSource::FromPreviousCarLoad, $newItem->source);
    }

    public function test_rollover_car_load_carries_null_cost_when_previous_item_had_no_cost(): void
    {
        $team = $this->makeTeam();
        $manager = User::factory()->create();
        $previousCarLoad = CarLoad::create([
            'name' => 'Previous Legacy Load',
            'team_id' => $team->id,
            'status' => CarLoadStatus::FullInventory,
            'load_date' => now()->subDays(10),
            'return_date' => now()->subDay(),
        ]);

        $product = $this->makeProduct();

        CarLoadItem::create([
            'car_load_id' => $previousCarLoad->id,
            'product_id' => $product->id,
            'quantity_loaded' => 20,
            'quantity_left' => 5,
            'cost_price_per_unit' => null,
            'loaded_at' => now()->subDays(10),
            'source' => CarLoadItemSource::Warehouse,
        ]);

        $inventory = CarLoadInventory::create([
            'car_load_id' => $previousCarLoad->id,
            'name' => 'Inventaire Clôturé Legacy',
            'user_id' => $manager->id,
            'closed' => true,
        ]);
        $inventory->items()->create([
            'product_id' => $product->id,
            'total_loaded' => 20,
            'total_sold' => 15,
            'total_returned' => 5,
        ]);

        $newCarLoad = $this->carLoadService->createFromInventory($inventory);

        $newItem = $newCarLoad->items()->where('product_id', $product->id)->sole();
        $this->assertEquals(5, $newItem->quantity_loaded, 'New item quantity must equal total_returned');
        $this->assertNull($newItem->cost_price_per_unit, 'Null cost from legacy item must propagate as null');
    }

    // =========================================================================
    // SalesInvoiceStatsService — profit calculation path
    // =========================================================================

    public function test_profit_uses_car_load_item_cost_price_per_unit_when_available(): void
    {
        $team = $this->makeTeam();
        $carLoad = $this->makeCarLoad($team);
        $product = $this->makeProduct(costPrice: 1_000);

        CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $product->id,
            'quantity_loaded' => 50,
            'quantity_left' => 50,
            'cost_price_per_unit' => 3_000,
            'loaded_at' => now(),
            'source' => CarLoadItemSource::Warehouse,
        ]);

        $vente = $this->makeVenteLinkedToCarLoad($product, $carLoad, price: 5_000, quantity: 10);

        // profit = (5000 - 3000) × 10 = 20 000
        $this->assertEquals(20_000, $this->statsService->calculateProfitForVente($vente));
    }

    public function test_profit_uses_fifo_cost_of_oldest_active_batch_when_multiple_car_load_items_exist(): void
    {
        $team = $this->makeTeam();
        $carLoad = $this->makeCarLoad($team);
        $product = $this->makeProduct(costPrice: 1_000);

        // Two batches loaded: oldest at 4000 (still has stock), newer at 6000.
        // FIFO picks the oldest batch with quantity_left > 0, i.e. cost = 4000.
        CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $product->id,
            'quantity_loaded' => 30,
            'quantity_left' => 30,
            'cost_price_per_unit' => 4_000,
            'loaded_at' => now()->subDay(),
            'source' => CarLoadItemSource::Warehouse,
        ]);

        CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $product->id,
            'quantity_loaded' => 20,
            'quantity_left' => 20,
            'cost_price_per_unit' => 6_000,
            'loaded_at' => now(),
            'source' => CarLoadItemSource::Warehouse,
        ]);

        $vente = $this->makeVenteLinkedToCarLoad($product, $carLoad, price: 8_000, quantity: 5);

        // FIFO cost = 4000 (oldest batch with quantity_left > 0)
        // profit = (8000 - 4000) × 5 = 20 000
        $this->assertEquals(20_000, $this->statsService->calculateProfitForVente($vente));
    }

    public function test_profit_uses_fifo_cost_of_next_batch_when_oldest_batch_is_exhausted(): void
    {
        $team = $this->makeTeam();
        $carLoad = $this->makeCarLoad($team);
        $product = $this->makeProduct(costPrice: 1_000);

        // Oldest batch fully consumed (quantity_left = 0). FIFO advances to the next one.
        CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $product->id,
            'quantity_loaded' => 30,
            'quantity_left' => 0,
            'cost_price_per_unit' => 4_000,
            'loaded_at' => now()->subDay(),
            'source' => CarLoadItemSource::Warehouse,
        ]);

        CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $product->id,
            'quantity_loaded' => 20,
            'quantity_left' => 20,
            'cost_price_per_unit' => 6_000,
            'loaded_at' => now(),
            'source' => CarLoadItemSource::Warehouse,
        ]);

        $vente = $this->makeVenteLinkedToCarLoad($product, $carLoad, price: 8_000, quantity: 5);

        // FIFO cost = 6000 (oldest batch is exhausted, next batch has stock)
        // profit = (8000 - 6000) × 5 = 10 000
        $this->assertEquals(10_000, $this->statsService->calculateProfitForVente($vente));
    }

    public function test_profit_uses_latest_batch_cost_when_all_batches_are_exhausted(): void
    {
        $team = $this->makeTeam();
        $carLoad = $this->makeCarLoad($team);
        $product = $this->makeProduct(costPrice: 1_000);

        // All batches exhausted. Falls back to latest item by id.
        CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $product->id,
            'quantity_loaded' => 30,
            'quantity_left' => 0,
            'cost_price_per_unit' => 4_000,
            'loaded_at' => now()->subDay(),
            'source' => CarLoadItemSource::Warehouse,
        ]);

        CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $product->id,
            'quantity_loaded' => 20,
            'quantity_left' => 0,
            'cost_price_per_unit' => 6_000,
            'loaded_at' => now(),
            'source' => CarLoadItemSource::Warehouse,
        ]);

        $vente = $this->makeVenteLinkedToCarLoad($product, $carLoad, price: 8_000, quantity: 5);

        // All batches exhausted → falls back to latest item, cost = 6000
        // profit = (8000 - 6000) × 5 = 10 000
        $this->assertEquals(10_000, $this->statsService->calculateProfitForVente($vente));
    }

    public function test_fifo_profit_falls_back_to_product_cost_price_when_car_load_item_has_null_cost(): void
    {
        $team = $this->makeTeam();
        $carLoad = $this->makeCarLoad($team);
        $product = $this->makeProduct(costPrice: 1_000);

        $this->makeStockEntry($product, 100, unitPrice: 2_000);

        CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $product->id,
            'quantity_loaded' => 50,
            'quantity_left' => 50,
            'cost_price_per_unit' => null, // legacy — no cost recorded
            'loaded_at' => now(),
            'source' => CarLoadItemSource::Warehouse,
        ]);

        $vente = $this->makeVenteLinkedToCarLoad($product, $carLoad, price: 5_000, quantity: 5);

        // No CarLoadItem with cost_price_per_unit → falls back to product.cost_price = 1000
        // profit = (5000 - 1000) × 5 = 20 000
        $this->assertEquals(20_000, $this->statsService->calculateProfitForVente($vente));
    }

    public function test_fifo_profit_falls_back_to_product_cost_price_when_invoice_has_no_car_load(): void
    {
        $product = $this->makeProduct(costPrice: 1_000);
        $this->makeStockEntry($product, 100, unitPrice: 4_000);

        $vente = $this->makeVenteWithoutCarLoad($product, price: 6_000, quantity: 3);

        // No car_load_id → falls back to product.cost_price = 1000
        // profit = (6000 - 1000) × 3 = 15 000
        $this->assertEquals(15_000, $this->statsService->calculateProfitForVente($vente));
    }

    public function test_fifo_profit_falls_back_to_product_cost_price_when_no_stock_entries_exist(): void
    {
        $product = $this->makeProduct(costPrice: 500);

        $vente = $this->makeVenteWithoutCarLoad($product, price: 2_000, quantity: 4);

        // No car load, no stock entries → falls back to product.cost_price = 500
        // profit = (2000 - 500) × 4 = 6 000
        $this->assertEquals(6_000, $this->statsService->calculateProfitForVente($vente));
    }

    // =========================================================================
    // calculateProfitForVenteFromHistoricalAverage — backfill / legacy path
    // =========================================================================

    public function test_historical_average_profit_uses_stock_entry_weighted_average_when_car_load_item_has_null_cost(): void
    {
        $team = $this->makeTeam();
        $carLoad = $this->makeCarLoad($team);
        $product = $this->makeProduct(costPrice: 1_000);

        $this->makeStockEntry($product, 100, unitPrice: 2_000);

        CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $product->id,
            'quantity_loaded' => 50,
            'quantity_left' => 50,
            'cost_price_per_unit' => null, // legacy
            'loaded_at' => now(),
            'source' => CarLoadItemSource::Warehouse,
        ]);

        $vente = $this->makeVenteLinkedToCarLoad($product, $carLoad, price: 5_000, quantity: 5);

        // Historical average from StockEntry = 2000
        // profit = (5000 - 2000) × 5 = 15 000
        $this->assertEquals(15_000, $this->statsService->calculateProfitForVenteFromHistoricalAverage($vente));
    }

    public function test_historical_average_profit_uses_stock_entry_weighted_average_when_invoice_has_no_car_load(): void
    {
        $product = $this->makeProduct(costPrice: 1_000);
        $this->makeStockEntry($product, 100, unitPrice: 4_000);

        $vente = $this->makeVenteWithoutCarLoad($product, price: 6_000, quantity: 3);

        // Historical average from StockEntry = 4000
        // profit = (6000 - 4000) × 3 = 6 000
        $this->assertEquals(6_000, $this->statsService->calculateProfitForVenteFromHistoricalAverage($vente));
    }

    public function test_historical_average_profit_falls_back_to_product_cost_price_when_no_stock_entries_exist(): void
    {
        $product = $this->makeProduct(costPrice: 500);

        $vente = $this->makeVenteWithoutCarLoad($product, price: 2_000, quantity: 4);

        // No stock entries → falls back to product.cost_price = 500
        // profit = (2000 - 500) × 4 = 6 000
        $this->assertEquals(6_000, $this->statsService->calculateProfitForVenteFromHistoricalAverage($vente));
    }
}
