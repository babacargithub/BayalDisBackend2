<?php

namespace Tests\Unit;

use App\Enums\CarLoadStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\CarLoad;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use App\Services\CarLoadService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CarLoadStockTest extends TestCase
{
    use RefreshDatabase;

    private CarLoadService $carLoadService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carLoadService = app(CarLoadService::class);
    }

    private function makeBaseProduct(string $name = 'Base Product'): Product
    {
        return Product::create([
            'name' => $name,
            'price' => 1000,
            'cost_price' => 500,
            'base_quantity' => 12,
        ]);
    }

    private function makeActiveCarLoad(): CarLoad
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'Team '.uniqid(),
            'user_id' => $user->id,
        ]);

        return CarLoad::create([
            'name' => 'CarLoad '.uniqid(),
            'team_id' => $team->id,
            'status' =>  CarLoadStatus::Selling,
            'load_date' => Carbon::now()->subDay(),
            'return_date' => Carbon::now()->addDay(),
            'returned' => false,
        ]);
    }

    public function test_fifo_decrease_across_multiple_items_and_prevent_oversell(): void
    {
        $product = $this->makeBaseProduct();
        $carLoad = $this->makeActiveCarLoad();

        $now = Carbon::now();

        $older = $carLoad->items()->create([
            'product_id' => $product->id,
            'quantity_loaded' => 10,
            'quantity_left' => 10,
            'loaded_at' => $now->copy()->subHours(5),
        ]);

        $newer = $carLoad->items()->create([
            'product_id' => $product->id,
            'quantity_loaded' => 8,
            'quantity_left' => 8,
            'loaded_at' => $now->copy()->subHours(1),
        ]);

        $this->carLoadService->decreaseProductStockInCarLoadUsingFifo($product, 12, $carLoad);

        $older->refresh();
        $newer->refresh();

        $this->assertSame(0, $older->quantity_left);
        $this->assertSame(6, $newer->quantity_left);

        $this->carLoadService->decreaseProductStockInCarLoadUsingFifo($product, 6, $carLoad);
        $newer->refresh();
        $this->assertSame(0, $newer->quantity_left);

        $this->expectException(InsufficientStockException::class);
        $this->carLoadService->decreaseProductStockInCarLoadUsingFifo($product, 1, $carLoad);
    }

    public function test_increase_stock_puts_back_into_latest_item(): void
    {
        $product = $this->makeBaseProduct('Another Base');
        $carLoad = $this->makeActiveCarLoad();

        $now = Carbon::now();

        $carLoad->items()->create([
            'product_id' => $product->id,
            'quantity_loaded' => 5,
            'quantity_left' => 0,
            'loaded_at' => $now->copy()->subHours(3),
        ]);

        $last = $carLoad->items()->create([
            'product_id' => $product->id,
            'quantity_loaded' => 7,
            'quantity_left' => 1,
            'loaded_at' => $now->copy()->subHour(),
        ]);

        $this->carLoadService->increaseProductStockInCarLoad($product, 3, $carLoad);

        $last->refresh();
        $this->assertSame(4, $last->quantity_left);
    }

    public function test_get_total_quantity_left_of_product_in_car_load_sums_across_all_items(): void
    {
        $product = $this->makeBaseProduct();
        $carLoad = $this->makeActiveCarLoad();

        $carLoad->items()->create(['product_id' => $product->id, 'quantity_loaded' => 10, 'quantity_left' => 7, 'loaded_at' => Carbon::now()->subHour()]);
        $carLoad->items()->create(['product_id' => $product->id, 'quantity_loaded' => 5, 'quantity_left' => 3, 'loaded_at' => Carbon::now()]);

        $this->assertSame(10, $this->carLoadService->getTotalQuantityLeftOfProductInCarLoad($carLoad, $product));
    }

    public function test_get_total_quantity_left_of_product_in_car_load_returns_zero_when_no_items_exist(): void
    {
        $product = $this->makeBaseProduct();
        $carLoad = $this->makeActiveCarLoad();

        $this->assertSame(0, $this->carLoadService->getTotalQuantityLeftOfProductInCarLoad($carLoad, $product));
    }

    public function test_get_total_quantity_loaded_of_product_in_car_load_sums_quantity_loaded(): void
    {
        $product = $this->makeBaseProduct();
        $carLoad = $this->makeActiveCarLoad();

        $carLoad->items()->create(['product_id' => $product->id, 'quantity_loaded' => 10, 'quantity_left' => 0, 'loaded_at' => Carbon::now()->subHour()]);
        $carLoad->items()->create(['product_id' => $product->id, 'quantity_loaded' => 6, 'quantity_left' => 0, 'loaded_at' => Carbon::now()]);

        $this->assertSame(16, $this->carLoadService->getTotalQuantityLoadedOfProductInCarLoad($carLoad, $product));
    }

    public function test_decrease_product_stock_in_car_load_throws_insufficient_stock_exception(): void
    {
        $product = $this->makeBaseProduct();
        $carLoad = $this->makeActiveCarLoad();

        $carLoad->items()->create(['product_id' => $product->id, 'quantity_loaded' => 5, 'quantity_left' => 5, 'loaded_at' => Carbon::now()]);

        $this->expectException(InsufficientStockException::class);

        $this->carLoadService->decreaseProductStockInCarLoadUsingFifo($product, 10, $carLoad);
    }

    public function test_increase_product_stock_in_car_load_throws_exception_when_product_not_in_car_load(): void
    {
        $product = $this->makeBaseProduct();
        $carLoad = $this->makeActiveCarLoad();

        // No items for this product in the car load
        $this->expectException(\Exception::class);

        $this->carLoadService->increaseProductStockInCarLoad($product, 5, $carLoad);
    }
}
