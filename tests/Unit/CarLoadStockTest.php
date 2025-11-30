<?php

namespace Tests\Unit;

use App\Models\CarLoad;
use App\Models\CarLoadItem;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CarLoadStockTest extends TestCase
{
    use DatabaseTransactions;

    private function makeBaseProduct(string $name = 'Base Product'): Product
    {
        return Product::create([
            'name'          => $name,
            'price'         => 1000,
            'cost_price'    => 500,
            'base_quantity' => 12,
        ]);
    }

    private function makeActiveCarLoad(int $teamId = 1): CarLoad
    {
        $user = User::factory()->create();

        $team = Team::create([
            'name'    => "Team {$teamId}",
            'user_id' => $user->id,
        ]);

        return CarLoad::create([
            'name'        => "Team {$team->id} Load",
            'team_id'     => $team->id,
            'status'      => 'ACTIVE',
            'load_date'   => Carbon::now()->subDay(),
            'return_date' => Carbon::now()->addDay(),
            'returned'    => false,
        ]);
    }

    public function test_fifo_decrease_across_multiple_items_and_prevent_oversell(): void
    {
        $product = $this->makeBaseProduct();
        $carLoad = $this->makeActiveCarLoad();

        $now = Carbon::now();

        $older = $carLoad->items()->create([
            'product_id'      => $product->id,
            'quantity_loaded' => 10,
            'quantity_left'   => 10,
            'loaded_at'       => $now->copy()->subHours(5),
        ]);

        $newer = $carLoad->items()->create([
            'product_id'      => $product->id,
            'quantity_loaded' => 8,
            'quantity_left'   => 8,
            'loaded_at'       => $now->copy()->subHours(1),
        ]);

        $carLoad->decreaseStockOfProduct($product, 12);

        $older->refresh();
        $newer->refresh();

        $this->assertSame(0, $older->quantity_left);
        $this->assertSame(6, $newer->quantity_left);

        $carLoad->decreaseStockOfProduct($product, 6);
        $newer->refresh();
        $this->assertSame(0, $newer->quantity_left);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Stock insuffisant');

        $carLoad->decreaseStockOfProduct($product, 1);
    }

    public function test_increase_stock_puts_back_into_latest_item(): void
    {
        $product = $this->makeBaseProduct('Another Base');
        $carLoad = $this->makeActiveCarLoad(2);

        $now = Carbon::now();

        $carLoad->items()->create([
            'product_id'      => $product->id,
            'quantity_loaded' => 5,
            'quantity_left'   => 0,
            'loaded_at'       => $now->copy()->subHours(3),
        ]);

        $last = $carLoad->items()->create([
            'product_id'      => $product->id,
            'quantity_loaded' => 7,
            'quantity_left'   => 1,
            'loaded_at'       => $now->copy()->subHour(),
        ]);

        $carLoad->increaseStockOfProduct($product, 3);

        $last->refresh();
        $this->assertSame(4, $last->quantity_left);
    }

    // other tests...
}