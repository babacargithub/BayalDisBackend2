<?php

namespace Tests\Feature;

use App\Enums\CarLoadStatus;
use App\Enums\StockEntryTransferType;
use App\Models\CarLoad;
use App\Models\Product;
use App\Models\StockEntry;
use App\Models\StockEntryTransfer;
use App\Models\Team;
use App\Models\User;
use App\Services\CarLoadService;
use App\Services\ProductService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockEntryTransferTest extends TestCase
{
    use RefreshDatabase;

    private ProductService $productService;

    private CarLoadService $carLoadService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->productService = app(ProductService::class);
        $this->carLoadService = app(CarLoadService::class);
    }

    private function makeProduct(string $name = 'Test Product'): Product
    {
        return Product::create([
            'name' => $name,
            'price' => 35000,
            'cost_price' => 28000,
            'base_quantity' => 1,
        ]);
    }

    private function makeStockEntry(Product $product, int $quantity, int $unitPrice = 28000, ?string $createdAt = null): StockEntry
    {
        $entry = StockEntry::create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'quantity_left' => $quantity,
            'unit_price' => $unitPrice,
            'transportation_cost' => 0,
            'packaging_cost' => 0,
        ]);

        if ($createdAt) {
            $entry->created_at = $createdAt;
            $entry->save();
        }

        return $entry;
    }

    private function makeActiveCarLoad(): CarLoad
    {
        $user = User::factory()->create();
        $team = Team::create(['name' => 'Team '.uniqid(), 'user_id' => $user->id]);

        return CarLoad::create([
            'name' => 'CarLoad '.uniqid(),
            'team_id' => $team->id,
            'status' => CarLoadStatus::Loading,
            'load_date' => Carbon::now()->subDay(),
            'return_date' => Carbon::now()->addDay(),
            'returned' => false,
        ]);
    }

    // ─── computeQuantityLeftFromTransfers ────────────────────────────────────

    public function test_compute_quantity_left_returns_full_quantity_when_no_transfers_exist(): void
    {
        $product = $this->makeProduct();
        $stockEntry = $this->makeStockEntry($product, 30);

        $this->assertEquals(30, $stockEntry->computeQuantityLeftFromTransfers());
    }

    public function test_compute_quantity_left_subtracts_out_transfers(): void
    {
        $product = $this->makeProduct();
        $stockEntry = $this->makeStockEntry($product, 30);

        StockEntryTransfer::create([
            'stock_entry_id' => $stockEntry->id,
            'quantity' => 10,
            'transfer_type' => StockEntryTransferType::Out,
            'transferred_at' => now(),
        ]);

        StockEntryTransfer::create([
            'stock_entry_id' => $stockEntry->id,
            'quantity' => 5,
            'transfer_type' => StockEntryTransferType::Out,
            'transferred_at' => now(),
        ]);

        $this->assertEquals(15, $stockEntry->computeQuantityLeftFromTransfers());
    }

    public function test_compute_quantity_left_adds_in_transfers(): void
    {
        $product = $this->makeProduct();
        $stockEntry = $this->makeStockEntry($product, 30);

        StockEntryTransfer::create([
            'stock_entry_id' => $stockEntry->id,
            'quantity' => 10,
            'transfer_type' => StockEntryTransferType::Out,
            'transferred_at' => now(),
        ]);

        StockEntryTransfer::create([
            'stock_entry_id' => $stockEntry->id,
            'quantity' => 3,
            'transfer_type' => StockEntryTransferType::In,
            'transferred_at' => now(),
        ]);

        // 30 - 10 + 3 = 23
        $this->assertEquals(23, $stockEntry->computeQuantityLeftFromTransfers());
    }

    public function test_update_quantity_left_from_transfers_persists_to_database(): void
    {
        $product = $this->makeProduct();
        $stockEntry = $this->makeStockEntry($product, 30);

        StockEntryTransfer::create([
            'stock_entry_id' => $stockEntry->id,
            'quantity' => 12,
            'transfer_type' => StockEntryTransferType::Out,
            'transferred_at' => now(),
        ]);

        $stockEntry->updateQuantityLeftFromTransfers();

        $this->assertEquals(18, $stockEntry->quantity_left);
        $this->assertEquals(18, $stockEntry->fresh()->quantity_left);
    }

    // ─── consumeWarehouseStockInFifoReturningBatchCosts ──────────────────────

    public function test_consuming_warehouse_stock_creates_out_transfer_and_updates_quantity_left(): void
    {
        $product = $this->makeProduct();
        $stockEntry = $this->makeStockEntry($product, 30);

        $this->productService->consumeWarehouseStockInFifoReturningBatchCosts($product, 10);

        $this->assertCount(1, $stockEntry->transfers);

        $transfer = $stockEntry->transfers->first();
        $this->assertEquals(StockEntryTransferType::Out, $transfer->transfer_type);
        $this->assertEquals(10, $transfer->quantity);

        $stockEntry->refresh();
        $this->assertEquals(20, $stockEntry->quantity_left);
    }

    public function test_consuming_warehouse_stock_returns_batch_with_transfer_id(): void
    {
        $product = $this->makeProduct();
        $this->makeStockEntry($product, 30);

        $consumedBatches = $this->productService->consumeWarehouseStockInFifoReturningBatchCosts($product, 10);

        $this->assertCount(1, $consumedBatches);
        $this->assertEquals(10, $consumedBatches[0]['quantity']);
        $this->assertArrayHasKey('stock_entry_transfer_id', $consumedBatches[0]);
        $this->assertNotNull($consumedBatches[0]['stock_entry_transfer_id']);
    }

    public function test_fifo_consumption_across_multiple_stock_entries_creates_one_transfer_per_entry(): void
    {
        $product = $this->makeProduct();
        $olderEntry = $this->makeStockEntry($product, 10, 28000, now()->subDays(5)->toDateTimeString());
        $newerEntry = $this->makeStockEntry($product, 15, 30000, now()->subDay()->toDateTimeString());

        // Consume 12: exhausts older entry (10) and takes 2 from newer
        $consumedBatches = $this->productService->consumeWarehouseStockInFifoReturningBatchCosts($product, 12);

        $this->assertCount(2, $consumedBatches);

        // Older entry fully consumed
        $this->assertEquals(10, $consumedBatches[0]['quantity']);
        $this->assertEquals(28000, $consumedBatches[0]['cost_price_per_unit']);
        $olderEntry->refresh();
        $this->assertEquals(0, $olderEntry->quantity_left);
        $this->assertCount(1, $olderEntry->transfers);

        // Newer entry partially consumed
        $this->assertEquals(2, $consumedBatches[1]['quantity']);
        $this->assertEquals(30000, $consumedBatches[1]['cost_price_per_unit']);
        $newerEntry->refresh();
        $this->assertEquals(13, $newerEntry->quantity_left);
        $this->assertCount(1, $newerEntry->transfers);
    }

    // ─── createItemsToCarLoad links transfer to CarLoadItem ──────────────────

    public function test_creating_car_load_items_links_transfer_to_the_car_load_item(): void
    {
        $product = $this->makeProduct();
        $this->makeStockEntry($product, 30);
        $carLoad = $this->makeActiveCarLoad();

        $this->carLoadService->createItemsToCarLoad($carLoad, [
            ['product_id' => $product->id, 'quantity_loaded' => 5],
        ]);

        $transfer = StockEntryTransfer::first();
        $this->assertNotNull($transfer->car_load_item_id);

        $carLoadItem = $carLoad->items()->first();
        $this->assertEquals($carLoadItem->id, $transfer->car_load_item_id);
    }

    public function test_creating_car_load_items_spanning_multiple_entries_links_each_transfer_to_its_car_load_item(): void
    {
        $product = $this->makeProduct();
        $this->makeStockEntry($product, 5, 28000, now()->subDays(3)->toDateTimeString());
        $this->makeStockEntry($product, 10, 30000, now()->toDateTimeString());
        $carLoad = $this->makeActiveCarLoad();

        // 8 units spans both entries: 5 from older, 3 from newer → 2 CarLoadItems
        $this->carLoadService->createItemsToCarLoad($carLoad, [
            ['product_id' => $product->id, 'quantity_loaded' => 8],
        ]);

        $carLoadItems = $carLoad->items()->orderBy('id')->get();
        $this->assertCount(2, $carLoadItems);

        $transfers = StockEntryTransfer::all();
        $this->assertCount(2, $transfers);

        foreach ($transfers as $transfer) {
            $this->assertNotNull($transfer->car_load_item_id);
        }

        // Each transfer linked to a different car load item
        $linkedItemIds = $transfers->pluck('car_load_item_id')->sort()->values();
        $carLoadItemIds = $carLoadItems->pluck('id')->sort()->values();
        $this->assertEquals($carLoadItemIds, $linkedItemIds);
    }

    // ─── moveToCarLoad ───────────────────────────────────────────────────────

    public function test_move_to_car_load_creates_transfer_car_load_item_and_updates_quantity_left(): void
    {
        $product = $this->makeProduct();
        $stockEntry = $this->makeStockEntry($product, 30);
        $carLoad = $this->makeActiveCarLoad();

        $carLoadItem = $this->carLoadService->moveToCarLoad($stockEntry, 10, $carLoad, now()->toDateString());

        // CarLoadItem created with correct values
        $this->assertEquals($product->id, $carLoadItem->product_id);
        $this->assertEquals(10, $carLoadItem->quantity_loaded);
        $this->assertEquals(10, $carLoadItem->quantity_left);
        $this->assertEquals($stockEntry->total_unit_cost, $carLoadItem->cost_price_per_unit);

        // Transfer created and linked
        $this->assertCount(1, StockEntryTransfer::all());
        $transfer = StockEntryTransfer::first();
        $this->assertEquals(StockEntryTransferType::Out, $transfer->transfer_type);
        $this->assertEquals(10, $transfer->quantity);
        $this->assertEquals($stockEntry->id, $transfer->stock_entry_id);
        $this->assertEquals($carLoadItem->id, $transfer->car_load_item_id);

        // quantity_left recomputed from ledger
        $stockEntry->refresh();
        $this->assertEquals(20, $stockEntry->quantity_left);
    }

    public function test_move_to_car_load_throws_when_requested_quantity_exceeds_entry_quantity_left(): void
    {
        $this->expectException(\App\Exceptions\InsufficientStockException::class);

        $product = $this->makeProduct();
        $stockEntry = $this->makeStockEntry($product, 5);
        $carLoad = $this->makeActiveCarLoad();

        $this->carLoadService->moveToCarLoad($stockEntry, 10, $carLoad, now()->toDateString());
    }

    public function test_move_to_car_load_fully_depletes_entry_when_quantity_equals_quantity_left(): void
    {
        $product = $this->makeProduct();
        $stockEntry = $this->makeStockEntry($product, 8);
        $carLoad = $this->makeActiveCarLoad();

        $this->carLoadService->moveToCarLoad($stockEntry, 8, $carLoad, now()->toDateString());

        $stockEntry->refresh();
        $this->assertEquals(0, $stockEntry->quantity_left);
    }

    public function test_move_to_car_load_with_comment_sets_it_on_car_load_item(): void
    {
        $product = $this->makeProduct();
        $stockEntry = $this->makeStockEntry($product, 15);
        $carLoad = $this->makeActiveCarLoad();

        $carLoadItem = $this->carLoadService->moveToCarLoad(
            $stockEntry,
            5,
            $carLoad,
            now()->toDateString(),
            'Urgence client'
        );

        $this->assertEquals('Urgence client', $carLoadItem->comment);
    }

    public function test_multiple_moves_to_same_car_load_accumulate_transfers_correctly(): void
    {
        $product = $this->makeProduct();
        $stockEntry = $this->makeStockEntry($product, 30);
        $carLoad = $this->makeActiveCarLoad();

        $this->carLoadService->moveToCarLoad($stockEntry, 8, $carLoad, now()->toDateString());
        $this->carLoadService->moveToCarLoad($stockEntry, 5, $carLoad, now()->toDateString());

        $stockEntry->refresh();
        $this->assertEquals(17, $stockEntry->quantity_left); // 30 - 8 - 5

        $this->assertCount(2, $stockEntry->transfers);
        $this->assertEquals(2, $carLoad->items()->count());
    }

    // ─── incrementWarehouseStockOnLatestEntry ────────────────────────────────

    public function test_incrementing_warehouse_stock_creates_in_transfer_and_updates_quantity_left(): void
    {
        $product = $this->makeProduct();
        $stockEntry = $this->makeStockEntry($product, 20);

        // First consume some stock
        $this->productService->consumeWarehouseStockInFifoReturningBatchCosts($product, 8);
        $stockEntry->refresh();
        $this->assertEquals(12, $stockEntry->quantity_left);

        // Now return 3 units
        $this->productService->incrementWarehouseStockOnLatestEntry($product, 3);
        $stockEntry->refresh();

        $this->assertEquals(15, $stockEntry->quantity_left);

        $inTransfers = $stockEntry->transfers()->where('transfer_type', StockEntryTransferType::In->value)->get();
        $this->assertCount(1, $inTransfers);
        $this->assertEquals(3, $inTransfers->first()->quantity);
    }
}
