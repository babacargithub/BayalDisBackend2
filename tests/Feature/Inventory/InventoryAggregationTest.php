<?php

namespace Tests\Feature\Inventory;

use App\Enums\CarLoadItemSource;
use App\Models\CarLoad;
use App\Models\CarLoadInventory;
use App\Models\CarLoadItem;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\User;
use App\Models\Vente;
use App\Services\CarLoadService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryAggregationTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeamWithManager(): Team
    {
        $manager = User::factory()->create();

        return Team::create([
            'name' => 'Team Inventory '.rand(1000, 10000),
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
            'status' => 'SELLING',
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

        // Record sales linked to this car load: 4 parent and 6 variant.
        // Ventes must be linked through a SalesInvoice with car_load_id set so
        // the inventory total_sold query (JOIN sales_invoices WHERE car_load_id = ?)
        // can find them.
        $invoice = SalesInvoice::create([
            'customer_id' => $customer->id,
            'commercial_id' => $commercial->id,
            'car_load_id' => $carLoad->id,
            'paid' => false,
        ]);
        Vente::create([
            'product_id' => $parent->id,
            'sales_invoice_id' => $invoice->id,
            'quantity' => 4,
            'price' => 3000,
            'type' => 'INVOICE_ITEM',
            'paid' => false,
        ]);
        Vente::create([
            'product_id' => $variant->id,
            'sales_invoice_id' => $invoice->id,
            'quantity' => 6,
            'price' => 1700,
            'type' => 'INVOICE_ITEM',
            'paid' => false,
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
        $this->assertSame(15, (int) $parentItem->total_loaded);
        $this->assertSame(8, (int) $variantItem->total_loaded);

        // total_sold is computed by joining ventes → sales_invoices WHERE car_load_id = carLoad.id
        $this->assertSame(4, (int) $parentItem->total_sold, 'Parent direct sales counted');
        $this->assertSame(6, (int) $variantItem->total_sold, 'Variant direct sales counted');
    }

    /**
     * Regression test for the bug introduced in commit 9c2f2954.
     *
     * After the refactoring from the boolean `from_previous_car_load` column to the
     * `source` enum, the children's loaded filter was accidentally changed to include
     * BOTH `Warehouse` AND `FromPreviousCarLoad` child items. Only `FromPreviousCarLoad`
     * children should ever add to the parent's total_loaded — `Warehouse`-sourced children
     * were loaded as individual units and are tracked entirely on their own inventory item;
     * including them again under the parent double-counts their stock.
     *
     * Setup:
     *   parent:  base_quantity=50, price=10 000 F
     *   child:   base_quantity=25  → 1 child unit = 0.5 parent carton
     *
     *   CarLoadItems for child:
     *     10 units  source=Warehouse         → must NOT add to parent total_loaded
     *      6 units  source=FromPreviousCarLoad → MUST add: 6 × 0.5 = 3.0 parent cartons
     *
     *   Inventory item for parent: total_loaded=5, total_sold=5, total_returned=0
     *
     * Expected (correct) total_loaded = 5 + 3.0 = 8.0
     * Expected result                 = 5 + 0 − 8.0 = −3.0
     *
     * Buggy code includes Warehouse → total_loaded = 5 + (10+6)×0.5 = 13.0 → result = −8.0  ← WRONG
     */
    public function test_warehouse_child_items_are_not_counted_in_parent_total_loaded_only_from_previous_car_load_children_count(): void
    {
        $team = $this->makeTeamWithManager();
        $commercial = $this->makeCommercialForTeam($team);
        $this->actingAs($commercial->user);

        $parent = Product::create([
            'name' => 'Carton Parent 50u',
            'price' => 10000,
            'cost_price' => 8000,
            'base_quantity' => 50,
        ]);
        $child = Product::create([
            'name' => 'Paquet Enfant 25pcs',
            'price' => 5500,
            'cost_price' => 4000,
            'parent_id' => $parent->id,
            'base_quantity' => 25,
        ]);

        $carLoad = $this->makeActiveCarLoadForTeam($team);

        $inventory = $carLoad->inventory()->create([
            'name' => 'Inventaire Exclusion Warehouse',
            'user_id' => $commercial->user->id,
        ]);
        $inventory->items()->create([
            'product_id' => $parent->id,
            'total_loaded' => 5,
            'total_sold' => 5,
            'total_returned' => 0,
        ]);

        // 10 child units from the warehouse — these are tracked under their own inventory item
        // and must NOT be added to the parent product's total_loaded.
        CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $child->id,
            'quantity_loaded' => 10,
            'quantity_left' => 10,
            'source' => CarLoadItemSource::Warehouse->value,
            'loaded_at' => now()->subDay(),
        ]);

        // 6 child units rolled over from the previous car load — these MUST add
        // 6 × (25/50) = 3.0 parent-equivalent cartons to total_loaded.
        CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $child->id,
            'quantity_loaded' => 6,
            'quantity_left' => 6,
            'source' => CarLoadItemSource::FromPreviousCarLoad->value,
            'loaded_at' => now()->subDay(),
        ]);

        $service = new CarLoadService;
        $computedResult = $service->getCalculatedQuantitiesOfProductsInInventory($carLoad, $inventory);

        $parentComputedItem = collect($computedResult['items'])
            ->first(fn ($item) => $item->parent->name === $parent->name);

        $this->assertNotNull($parentComputedItem, 'Parent product must appear in computed inventory items');

        // Expected total_loaded = 5 (parent inv item) + 6 × 0.5 (from_previous child) = 8.0
        // Buggy code gives 5 + (10 + 6) × 0.5 = 13.0
        $this->assertEquals(
            8.0,
            $parentComputedItem->totalLoaded,
            'total_loaded must count only FromPreviousCarLoad children — Warehouse children must be excluded to avoid double-counting'
        );

        // result = total_sold + total_returned − total_loaded = 5 + 0 − 8 = −3.0
        $this->assertEquals(
            -3.0,
            $parentComputedItem->resultOfComputation,
            'Result must reflect the correct total_loaded (8.0), not the inflated value (13.0) produced by including Warehouse children'
        );
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
        // 3 sold of parent, 6 of child — linked to this car load via SalesInvoice.car_load_id
        $invoice = SalesInvoice::create([
            'customer_id' => $customer->id,
            'commercial_id' => $commercial->id,
            'car_load_id' => $carLoad->id,
            'paid' => false,
        ]);
        Vente::create([
            'product_id' => $parent->id,
            'sales_invoice_id' => $invoice->id,
            'quantity' => 3,
            'price' => 2400,
            'type' => 'INVOICE_ITEM',
            'paid' => false,
        ]);
        Vente::create([
            'product_id' => $child->id,
            'sales_invoice_id' => $invoice->id,
            'quantity' => 6,
            'price' => 1300,
            'type' => 'INVOICE_ITEM',
            'paid' => false,
        ]);

        $service = new CarLoadService;
        $total = $service->determineTotalSoldOfAParentProductFromChildren($carLoad, $parent);

        // The current implementation ignores parent direct ventes and only converts children, then adds persisted inventory parent total
        // child base_quantity 6 vs. parent 12 -> ratio 12/6 = 2, so 6 child -> 3 parent-equivalent
        $this->assertEquals(0.12, (float) $total, 'Current behavior: parent direct sales are not added by the service');
    }
}
