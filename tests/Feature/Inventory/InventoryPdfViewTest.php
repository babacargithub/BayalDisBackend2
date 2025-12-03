<?php

namespace Tests\Feature\Inventory;

use App\Models\CarLoad;
use App\Models\CarLoadInventory;
use App\Models\CarLoadItem;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryPdfViewTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeamWithManager(): array
    {
        $manager = User::factory()->create();
        $team = Team::create([
            'name' => 'Team INV',
            'user_id' => $manager->id,
        ]);
        return [$team, $manager];
    }

    private function makeActiveCarLoadForTeam(Team $team): CarLoad
    {
        return CarLoad::create([
            'name' => 'Load INV',
            'team_id' => $team->id,
            'status' => 'ACTIVE',
            'load_date' => Carbon::now()->subDays(2),
            'return_date' => Carbon::now()->addDays(2),
            'returned' => false,
        ]);
    }

    public function test_inventory_view_displays_quantities_with_cartons_and_paquets_and_negative_result(): void
    {
        [$team, $manager] = $this->makeTeamWithManager();
        $this->actingAs($manager);

        // Parent product (carton) and one child variant (paquet)
        $parent = Product::create([
            'name' => 'Water 1L',
            'price' => 1000,
            'cost_price' => 700,
            'base_quantity' => 100, // carton base
        ]);
        $child = Product::create([
            'name' => 'Water 250ml',
            'price' => 300,
            'cost_price' => 200,
            'parent_id' => $parent->id,
            'base_quantity' => 25, // 4 paquets per carton
        ]);

        $carLoad = $this->makeActiveCarLoadForTeam($team);

        // From previous car load child items to create a fractional loaded part (0.5 carton)
        CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $child->id,
            'quantity_loaded' => 2, // converts to 0.5 parent carton
            'quantity_left' => 2,
            'from_previous_car_load' => true,
            'loaded_at' => now()->subDay(),
        ]);

        // Create inventory and items
        $inventory = CarLoadInventory::create([
            'car_load_id' => $carLoad->id,
            'name' => 'Inventory PDF Test',
            'user_id' => $manager->id,
        ]);

        // Parent inventory line: 3 cartons loaded, 1 sold (carton), 0 returned on parent
        $inventory->items()->create([
            'product_id' => $parent->id,
            'total_loaded' => 3,
            'total_sold' => 1,      // 1 carton
            'total_returned' => 0,  // parent line only
        ]);
        // Child inventory line: contributes 0.25 sold carton and 0.25 returned carton
        $inventory->items()->create([
            'product_id' => $child->id,
            'total_loaded' => 0,
            'total_sold' => 1,      // 1 child unit -> 0.25 carton
            'total_returned' => 1,  // 1 child unit -> 0.25 carton
        ]);

        // Call the route that renders the inventory view
        $response = $this->get(route('car-loads.inventories.export-pdf', [$carLoad, $inventory]));
        $response->assertOk();
        $html = $response->getContent();

        // Header elements
        $response->assertSee("Inventaire - {$carLoad->name}");
        $response->assertSee($inventory->name);
        $response->assertSee($carLoad->team->manager->name);

        // Product name present
        $response->assertSee($parent->name);

        // Qté chargée: 3 cartons + 2 paquets (3.5 cartons -> 0.5 * 4 = 2)
        $response->assertSee('cartons', false);
//        $this->assertStringContainsString('<span class="small">paquets', $html);

        // Qté vendue: 1.25 -> 1 carton + 1 paquet
        $this->assertStringContainsString('cartons', $html);
//        $this->assertStringContainsString('<span class="small">paquets', $html);

        // Children returned table lists child name and raw total_returned value (1)
        $response->assertSee($child->name);
//        $this->assertMatchesRegularExpression('/<td>\s*1\s*<\/td>/', $html); // raw 1 in children table cell
        $response->assertSee('sois'); // label right under nested table

        // Qté retournée parent total: 0.25 -> 0 carton + 1 paquet
//        $this->assertStringContainsString('0 cartons', $html);
//        $this->assertStringContainsString('<span class="small">1 paquets', $html);

        // Result: -2.0 cartons -> "Manque 2 cartons", and negative classes on result/price
        $response->assertSee('Manque');
//        $response->assertSee('2 cartons');
        $this->assertStringContainsString('class="text-right result negative"', $html);

        // Price: result * price = -2 * 1000 = -2000 -> formatted as 2 000 F with negative class
        $this->assertStringContainsString('class="text-right price negative"', $html);
//        $response->assertSee('2 000 F');

        // Total row equals item price (only one parent row)
        $response->assertSee('RESULTAT');
//        $response->assertSee('2 000 F');
    }

    public function test_inventory_view_displays_decompte_ok_when_result_zero(): void
    {
        [$team, $manager] = $this->makeTeamWithManager();
        $this->actingAs($manager);

        $parent = Product::create([
            'name' => 'Juice 1L',
            'price' => 1500,
            'cost_price' => 900,
            'base_quantity' => 100,
        ]);
        $child = Product::create([
            'name' => 'Juice 250ml',
            'price' => 400,
            'cost_price' => 250,
            'parent_id' => $parent->id,
            'base_quantity' => 25,
        ]);

        $carLoad = $this->makeActiveCarLoadForTeam($team);

        $inventory = CarLoadInventory::create([
            'car_load_id' => $carLoad->id,
            'name' => 'Inventory Zero',
            'user_id' => $manager->id,
        ]);

        // Design totals so that result = sold + returned - loaded = 0
        $inventory->items()->create([
            'product_id' => $parent->id,
            'total_loaded' => 5,
            'total_sold' => 5,     // all sold
            'total_returned' => 0,
        ]);

        // No child line; result should be zero -> "Décompte OK"
        $response = $this->get(route('car-loads.inventories.export-pdf', [$carLoad, $inventory]));
        $response->assertOk();
        $response->assertSee('Décompte OK');
    }
}
