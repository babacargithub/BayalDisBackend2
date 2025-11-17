<?php

namespace Tests\Feature\Inventory;

use App\Http\Controllers\CarLoadController;
use App\Models\CarLoad;
use App\Models\CarLoadInventory;
use App\Models\CarLoadItem;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use App\Models\Vente;
use App\Services\CarLoadService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryAggregationTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeamWithManager(): Team
    {
        $manager = User::factory()->create();
        return Team::create([
            'name' => 'Team Inventory '.rand(1, 1000),
            'user_id' => $manager->id,
        ]);
    }

    public static function createCommercialForTeam(Team $team): Commercial
    {
        $user = User::factory()->create();
        $commercial = Commercial::create([
            'name' => 'Seller',
            'phone_number' => '221711111'.rand(100, 999),
            'gender' => 'male',
            'user_id' => $user->id,
        ]);
        $commercial->team()->associate($team);
        $commercial->save();
        return $commercial;
    }
    public static function makeCommercialForTeam(Team $team): Commercial
    {
       return self::createCommercialForTeam($team);
    }

    private function makeActiveCarLoadForTeam(Team $team): CarLoad
    {
        return CarLoad::create([
            'name' => 'Inventory Load',
            'team_id' => $team->id,
            'status' => 'ACTIVE',
            'load_date' => Carbon::now()->subDays(2),
            'return_date' => Carbon::now()->addDays(2),
            'returned' => false,
        ]);
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'name' => 'Inventory Customer',
            'address' => 'Some Addr',
            'phone_number' => '221733333333',
            'owner_number' => '221733333333',
            'gps_coordinates' => '143,1292020,94009030404',
            'commercial_id' => $this->makeCommercialForTeam($this->makeTeamWithManager())->id,

        ]);
    }

    public function test_add_inventory_items_calculates_loaded_and_sold(): void
    {
        $team = $this->makeTeamWithManager();
        $commercial = $this->makeCommercialForTeam($team);
        $this->actingAs($commercial->user);

        // Parent and variant
        $parent = Product::create([
            'name' => 'Parent Box',
            'price' => 3000,
            'cost_price' => 2000,
            'base_quantity' => 1000,
        ]);
        $variant = Product::create([
            'name' => 'Variant Half',
            'price' => 1700,
            'cost_price' => 1100,
            'parent_id' => $parent->id,
            'base_quantity' => 20,
        ]);

        $carLoad = $this->makeActiveCarLoadForTeam($team);
        // Load 15 parent and 8 variant into the car
        $carLoad->items()->createMany([
            ['product_id' => $parent->id, 'quantity_loaded' => 15, 'quantity_left' => 15, 'loaded_at' => now()->subDay()],
            ['product_id' => $variant->id, 'quantity_loaded' => 8, 'quantity_left' => 8, 'loaded_at' => now()->subDay()],
        ]);

        $customer = $this->makeCustomer();

        // Record ventes within the date range: 4 parent and 6 variant
        Vente::create([
            'product_id' => $parent->id,
            'customer_id' => $customer->id,
            'commercial_id' => $commercial->id,
            'quantity' => 4,
            'price' => 3000,
            'type' => 'SINGLE',
            'paid' => true,
        ]);
        Vente::create([
            'product_id' => $variant->id,
            'customer_id' => $customer->id,
            'commercial_id' => $commercial->id,
            'quantity' => 6,
            'price' => 1700,
            'type' => 'SINGLE',
            'paid' => true,
        ]);

        // Create an inventory first
        $response = $this->post(route('car-loads.inventories.store', $carLoad), [
            'name' => 'End of Week Inventory',
        ]);
        $response->assertStatus(302);
        $inventory = $carLoad->inventory()->firstOrFail();

        // Add inventory items for both products, providing total_returned only
        $response = $this->post(route('car-loads.inventories.items.store', [$carLoad, $inventory]), [
            'items' => [
                ['product_id' => $parent->id, 'total_returned' => 5],
                ['product_id' => $variant->id, 'total_returned' => 2],
            ],
        ]);
        $response->assertStatus(302);

        $items = $inventory->items()->orderBy('product_id')->get();
        $this->assertCount(2, $items);

        $parentItem = $items->firstWhere('product_id', $parent->id);
        $variantItem = $items->firstWhere('product_id', $variant->id);

        // total_loaded should be equal to loaded quantities for that product
        $this->assertSame(15, (int)$parentItem->total_loaded);
        $this->assertSame(8, (int)$variantItem->total_loaded);

        // total_sold is computed from ventes between dates (global), in our isolated test DB it should match
        $this->assertSame(4, (int)$parentItem->total_sold, 'Parent direct sales counted');
        $this->assertSame(6, (int)$variantItem->total_sold, 'Variant direct sales counted');
    }

    public function test_determine_total_sold_of_parent_includes_converted_children_but_excludes_parent_direct_sales_current_behavior(): void
    {
        $team = $this->makeTeamWithManager();
        $commercial = $this->makeCommercialForTeam($team);
        $this->actingAs($commercial->user);

        $parent = Product::create([
            'name' => 'Water 1L',
            'price' => 2400,
            'cost_price' => 1600,
            'base_quantity' => 1000,
        ]);
        $child = Product::create([
            'name' => 'Water 500ml',
            'price' => 1300,
            'cost_price' => 800,
            'parent_id' => $parent->id,
            'base_quantity' => 20,
        ]);

        $carLoad = $this->makeActiveCarLoadForTeam($team);
        // minimal inventory existence so the service can access $carLoad->inventory
        /** @var CarLoadInventory $inventory */
        $inventory = $carLoad->inventory()->create([
            'name' => 'Tmp Inventory',
            'user_id' => User::factory()->create()->id,
        ]);
        $inventory->items()->create([
            'product_id' => $parent->id,
            'total_loaded' => 10,
            'total_returned' => 2,
            'total_sold' => 0, // current behavior: service starts from persisted parent total_sold
        ]);

        $customer = $this->makeCustomer();
        // 3 sold of parent, 6 of child within period
        Vente::create([
            'product_id' => $parent->id,
            'customer_id' => $customer->id,
            'commercial_id' => $commercial->id,
            'quantity' => 3,
            'price' => 2400,
            'type' => 'SINGLE',
            'paid' => true,
        ]);
        Vente::create([
            'product_id' => $child->id,
            'customer_id' => $customer->id,
            'commercial_id' => $commercial->id,
            'quantity' => 6,
            'price' => 1300,
            'type' => 'SINGLE',
            'paid' => true,
        ]);

        $service = new CarLoadService();
        $total = $service->determineTotalSoldOfAParentProductFromChildren($carLoad, $parent);

        // Current implementation ignores parent direct ventes and only converts children then adds persisted inventory parent total
        // child base_quantity 6 vs parent 12 -> ratio 12/6 = 2, so 6 child -> 3 parent-equivalent
        $this->assertEquals(0.12, (float)$total, 'Current behavior: parent direct sales are not added by the service');
    }
}
