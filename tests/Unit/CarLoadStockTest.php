<?php

namespace Tests\Unit;

use App\Models\CarLoad;
use App\Models\CarLoadItem;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Inventory\InventoryAggregationTest;
use Tests\TestCase;
use Carbon\Carbon;

class CarLoadStockTest extends TestCase
{
    use RefreshDatabase;

    private function makeBaseProduct(string $name = 'Base Product'): Product
    {
        return Product::create([
            'name' => $name,
            'price' => 1000,
            'cost_price' => 500,
            'base_quantity' => 12, // e.g., 12 units per carton
        ]);
    }

    private function makeActiveCarLoad(int $teamId = 1): CarLoad
    {
        $team = Team::create([
            'name' => " Team {$teamId}",
            'user_id' => User::factory()->create()->id,

        ]);
        return CarLoad::create([
            'name' => 'Team '.$team->id.' Load',
            'team_id' => $team->id,
            'status' => 'ACTIVE',
            'load_date' => Carbon::now()->subDay(),
            'return_date' => Carbon::now()->addDay(),
            'returned' => false,
        ]);
    }

    public function test_fifo_decrease_across_multiple_items_and_prevent_oversell(): void
    {
        $product = $this->makeBaseProduct();
        $carLoad = $this->makeActiveCarLoad();

        // Two separate load items (older then newer)
        /** @var CarLoadItem $older */
        $older = $carLoad->items()->create([
            'product_id' => $product->id,
            'quantity_loaded' => 10,
            'quantity_left' => 10,
            'loaded_at' => Carbon::now()->subHours(5),
        ]);
        /** @var CarLoadItem $newer */
        $newer = $carLoad->items()->create([
            'product_id' => $product->id,
            'quantity_loaded' => 8,
            'quantity_left' => 8,
            'loaded_at' => Carbon::now()->subHours(1),
        ]);

        // Decrease 12 should consume 10 from older and 2 from newer (FIFO)
        $carLoad->decreaseStockOfProduct($product, 12);

        $older->refresh();
        $newer->refresh();
        $this->assertSame(0, $older->quantity_left, 'Older lot should be fully consumed');
        $this->assertSame(6, $newer->quantity_left, 'Newer lot should have remaining after FIFO');

        // Decrease another 6: consume the rest of newer
        $carLoad->decreaseStockOfProduct($product, 6);
        $newer->refresh();
        $this->assertSame(0, $newer->quantity_left, 'Newer lot should now be fully consumed');

        // Attempt to oversell should throw
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Stock insuffisant');
        $carLoad->decreaseStockOfProduct($product, 1);
    }

    public function test_increase_stock_puts_back_into_latest_item(): void
    {
        $product = $this->makeBaseProduct('Another Base');
        $carLoad = $this->makeActiveCarLoad(2);

        $first = $carLoad->items()->create([
            'product_id' => $product->id,
            'quantity_loaded' => 5,
            'quantity_left' => 0,
            'loaded_at' => Carbon::now()->subHours(3),
        ]);
        $last = $carLoad->items()->create([
            'product_id' => $product->id,
            'quantity_loaded' => 7,
            'quantity_left' => 1,
            'loaded_at' => Carbon::now()->subHour(),
        ]);

        $carLoad->increaseStockOfProduct($product, 3);

        $first->refresh();
        $last->refresh();

        // Per implementation, increase goes to the latest entry
        $this->assertSame(4, $last->quantity_left);
        $this->assertSame(0, $first->quantity_left);
    }

    public function test_car_load_stock_value_returns_the_expected_value(): void{
        $user = User::factory()->create();

        $team = Team::create([
            'name' => " Team dllds",
            'user_id' => $user->id,

        ]);
        $carLoad =  CarLoad::create([
            'name' => 'Team '.$team->id.' Load',
            'team_id' => $team->id,
            'status' => 'ACTIVE',
            'load_date' => Carbon::now()->subDay(),
            'return_date' => Carbon::now()->addDay(),
            'returned' => false,
        ]);
        // Create two products with different cost prices
        $productA = $this->makeBaseProduct('Prod A'); // cost_price = 500
        $productB = Product::create([
            'name' => 'Prod B',
            'price' => 2000,
            'cost_price' => 800,
            'base_quantity' => 6,
        ]);
        $productC = Product::create([
            'name' => 'Prod C',
            'price' => 20000,
            'cost_price' => 4000,
            'base_quantity' => 6,
        ]);
        // Add multiple items, including a zero-quantity-left entry which should not affect value
        $carLoad->items()->create([
            'product_id' => $productA->id,
            'quantity_loaded' => 10,
            'quantity_left' => 4,
            'loaded_at' => Carbon::now()->subHours(4),
        ]);
        $carLoad->items()->create([
            'product_id' => $productA->id,
            'quantity_loaded' => 5,
            'quantity_left' => 0, // should contribute 0 to value
            'loaded_at' => Carbon::now()->subHours(2),
        ]);
        $carLoad->items()->create([
            'product_id' => $productB->id,
            'quantity_loaded' => 7,
            'quantity_left' => 7,
            'loaded_at' => Carbon::now()->subHour(),
        ]);
        $carLoad->items()->create([
            'product_id' => $productC->id,
            'quantity_loaded' => 7,
            'quantity_left' => 10,
            'loaded_at' => Carbon::now()->subHour(),
        ]);

// Expected value: (4 * 500) + (7 * 800) + (10 * 4000)= 2000 + 5600 + 40000 = 47600
        $this->assertSame(47600, $carLoad->stock_value);
        $customer = $this->makeCustomer();
        // post a new vente of product C and check if stock value changes
        // simulate a sale of 2 units of product C from this car load
        $commercial = Commercial::create([
            'name' => 'Seller',
            'phone_number' => '221711111'.rand(100, 999),
            'gender' => 'male',
            'user_id' => $user->id,
        ]);
        $commercial->team()->associate($team);
        $commercial->save();
        $response = $this
            ->actingAs($user)
            ->postJson(route('sales_person.sales-invoices.create'),[
            "customer_id" => $customer->id,
                "should_be_paid_at" => Carbon::now()->toDateString(),
            'paid'=>true,
            "payment_method"=> "Wave",
            "items"=>[[
                "product_id" => $productC->id,
                "quantity"=> 2,
                "price"=> 6000,

                "paid"=>true,
                "comment"=> 'Comment',
            ]

            ]
        ]);
        if (!$response->status() == 200 || !$response->status() == 201) {
            dump($response->getContent());

        }
        $response->assertStatus(201);

        // New expected value: 47600 - (2 * 4000) = 39600
        $this->assertSame(39600, $carLoad->stock_value);
        $productC->refresh();
        $this->assertEquals(8, $carLoad->items()->where('product_id', $productC->id)->sum('quantity_left'));
        $carLoad->items()->create([
            'product_id' => $productC->id,
            'quantity_loaded' => 7,
            'quantity_left' => 21,
            'loaded_at' => Carbon::now()->subHour(),
        ]);

        $carLoad->refresh();
        $this->assertEquals(29, $carLoad->items()->where('product_id', $productC->id)->sum('quantity_left'));


    }

    private function makeCustomer(): Customer
    {
        $team = Team::create(['name' => 'Team 1111',"user_id" => User::factory()->create()->id]);
        return Customer::create([
            'name' => 'John Doe',
            "phone_number" => "773300853",
            "owner_number" => "773300853",
            "gps_coordinates" => '13.2494904,17.19390043',
            "commercial_id" =>  InventoryAggregationTest::makeCommercialForTeam($team)->id,
        ]);
    }
}
