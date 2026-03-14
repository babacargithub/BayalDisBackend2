<?php

namespace Tests\Feature\CarLoad;

use App\Enums\CarLoadStatus;
use App\Models\CarLoad;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for CarLoadController::show().
 *
 * Verifies that the CarLoads/Show Inertia page receives the correct props so
 * that the Vue component can render and compute groupings accurately.
 *
 * Contract verified here:
 *  - carLoad.team is always loaded (team name shown in info card)
 *  - carLoad.items[].product is always loaded (product name in articles tab)
 *  - carLoad.inventory.items[].product is loaded when an inventory exists
 *  - products[] always includes parent_id (required for the parent-filter toggle)
 *  - missingInventoryProducts is empty when no inventory exists
 *  - missingInventoryProducts lists products loaded onto the car but not yet inventoried
 *  - Both raw items are passed when the same product was loaded in multiple batches
 *    (the Vue computed groupedCarLoadItems sums them client-side)
 */
class CarLoadShowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->team = Team::create(['name' => 'Équipe Test', 'user_id' => $this->user->id]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeActiveCarLoad(): CarLoad
    {
        return CarLoad::create([
            'name' => 'Chargement Mbacké',
            'team_id' => $this->team->id,
            'status' => CarLoadStatus::Selling,
            'load_date' => Carbon::now()->subDay()->toDateString(),
            'return_date' => Carbon::now()->addWeek()->toDateString(),
            'returned' => false,
        ]);
    }

    private function makeProduct(string $name, ?int $parentId = null): Product
    {
        return Product::create([
            'name' => $name,
            'price' => 1000,
            'cost_price' => 700,
            'base_quantity' => 1,
            'parent_id' => $parentId,
        ]);
    }

    private function addCarLoadItem(CarLoad $carLoad, Product $product, int $quantityLoaded, int $quantityLeft): void
    {
        $carLoad->items()->create([
            'product_id' => $product->id,
            'quantity_loaded' => $quantityLoaded,
            'quantity_left' => $quantityLeft,
            'loaded_at' => Carbon::now()->toDateString(),
        ]);
    }

    private function makeInventory(CarLoad $carLoad): \App\Models\CarLoadInventory
    {
        return $carLoad->inventory()->create([
            'name' => 'Inventaire Test',
            'user_id' => $this->user->id,
        ]);
    }

    private function showCarLoad(CarLoad $carLoad)
    {
        return $this->actingAs($this->user)->get(route('car-loads.show', $carLoad));
    }

    // ─── Rendering ───────────────────────────────────────────────────────────

    public function test_show_renders_the_carloads_show_inertia_component(): void
    {
        $this->showCarLoad($this->makeActiveCarLoad())
            ->assertInertia(fn ($page) => $page->component('CarLoads/Show'));
    }

    public function test_show_requires_authentication(): void
    {
        $carLoad = $this->makeActiveCarLoad();
        $this->get(route('car-loads.show', $carLoad))->assertRedirect(route('login'));
    }

    // ─── carLoad prop ─────────────────────────────────────────────────────────

    public function test_show_passes_carload_basic_fields(): void
    {
        $carLoad = $this->makeActiveCarLoad();

        $this->showCarLoad($carLoad)
            ->assertInertia(fn ($page) => $page
                ->has('carLoad')
                ->where('carLoad.id', $carLoad->id)
                ->where('carLoad.name', 'Chargement Mbacké')
                ->where('carLoad.status', 'SELLING')
            );
    }

    public function test_show_passes_carload_with_team_relation_so_team_name_is_available(): void
    {
        $carLoad = $this->makeActiveCarLoad();

        $this->showCarLoad($carLoad)
            ->assertInertia(fn ($page) => $page
                ->where('carLoad.team.id', $this->team->id)
                ->where('carLoad.team.name', 'Équipe Test')
            );
    }

    // ─── products prop ────────────────────────────────────────────────────────

    public function test_show_passes_products_sorted_alphabetically_by_name(): void
    {
        $this->makeProduct('Zeste Orange');
        $this->makeProduct('Ananas Jus');

        $this->showCarLoad($this->makeActiveCarLoad())
            ->assertInertia(fn ($page) => $page
                ->has('products', 2)
                ->where('products.0.name', 'Ananas Jus')
                ->where('products.1.name', 'Zeste Orange')
            );
    }

    public function test_show_passes_parent_id_on_every_product_so_the_filter_toggle_works(): void
    {
        $parentProduct = $this->makeProduct('Carton Lait 12x1L');
        $this->makeProduct('Lait 1L', $parentProduct->id);

        $this->showCarLoad($this->makeActiveCarLoad())
            ->assertInertia(fn ($page) => $page
                ->has('products', 2)
                ->where('products.0.name', 'Carton Lait 12x1L')
                ->where('products.0.parent_id', null)
                ->where('products.1.name', 'Lait 1L')
                ->where('products.1.parent_id', $parentProduct->id)
            );
    }

    // ─── carLoad.items prop ───────────────────────────────────────────────────

    public function test_show_passes_empty_items_when_car_load_has_no_items(): void
    {
        $this->showCarLoad($this->makeActiveCarLoad())
            ->assertInertia(fn ($page) => $page->has('carLoad.items', 0));
    }

    public function test_show_passes_items_with_product_relation_so_product_name_is_available(): void
    {
        $product = $this->makeProduct('Eau Minérale 1.5L');
        $carLoad = $this->makeActiveCarLoad();
        $this->addCarLoadItem($carLoad, $product, 50, 30);

        $this->showCarLoad($carLoad)
            ->assertInertia(fn ($page) => $page
                ->has('carLoad.items', 1)
                ->where('carLoad.items.0.quantity_loaded', 50)
                ->where('carLoad.items.0.quantity_left', 30)
                ->where('carLoad.items.0.product.name', 'Eau Minérale 1.5L')
            );
    }

    public function test_show_passes_both_raw_items_when_same_product_has_multiple_load_batches(): void
    {
        $product = $this->makeProduct('Eau Plate 500ml');
        $carLoad = $this->makeActiveCarLoad();
        $this->addCarLoadItem($carLoad, $product, 30, 20);
        $this->addCarLoadItem($carLoad, $product, 20, 15);

        // Both raw items must arrive so Vue's groupedCarLoadItems can sum them.
        // The Vue computed expects: total_quantity_loaded = 50, total_quantity_left = 35.
        $this->showCarLoad($carLoad)
            ->assertInertia(fn ($page) => $page
                ->has('carLoad.items', 2)
                ->where('carLoad.items.0.product_id', $product->id)
                ->where('carLoad.items.0.quantity_loaded', 30)
                ->where('carLoad.items.0.quantity_left', 20)
                ->where('carLoad.items.1.product_id', $product->id)
                ->where('carLoad.items.1.quantity_loaded', 20)
                ->where('carLoad.items.1.quantity_left', 15)
            );
    }

    // ─── inventory prop ───────────────────────────────────────────────────────

    public function test_show_passes_null_inventory_and_empty_missing_products_when_no_inventory(): void
    {
        $this->showCarLoad($this->makeActiveCarLoad())
            ->assertInertia(fn ($page) => $page
                ->where('carLoad.inventory', null)
                ->has('missingInventoryProducts', 0)
            );
    }

    public function test_show_passes_inventory_with_closed_flag(): void
    {
        $carLoad = $this->makeActiveCarLoad();
        $inventory = $this->makeInventory($carLoad);

        $this->showCarLoad($carLoad)
            ->assertInertia(fn ($page) => $page
                ->where('carLoad.inventory.id', $inventory->id)
                ->where('carLoad.inventory.closed', false)
            );
    }

    public function test_show_passes_inventory_items_with_product_relation(): void
    {
        $product = $this->makeProduct('Jus de Fruits 1L');
        $carLoad = $this->makeActiveCarLoad();
        $this->addCarLoadItem($carLoad, $product, 24, 10);

        $inventory = $this->makeInventory($carLoad);
        $inventory->items()->create([
            'product_id' => $product->id,
            'total_loaded' => 24,
            'total_sold' => 14,
            'total_returned' => 10,
        ]);

        $this->showCarLoad($carLoad)
            ->assertInertia(fn ($page) => $page
                ->has('carLoad.inventory.items', 1)
                ->where('carLoad.inventory.items.0.total_loaded', 24)
                ->where('carLoad.inventory.items.0.total_sold', 14)
                ->where('carLoad.inventory.items.0.total_returned', 10)
                ->where('carLoad.inventory.items.0.product.name', 'Jus de Fruits 1L')
            );
    }

    // ─── missingInventoryProducts prop ────────────────────────────────────────

    public function test_show_passes_missing_products_for_items_not_yet_in_inventory(): void
    {
        $inventoriedProduct = $this->makeProduct('Produit A');
        $missingProduct = $this->makeProduct('Produit B');
        $carLoad = $this->makeActiveCarLoad();
        $this->addCarLoadItem($carLoad, $inventoriedProduct, 10, 5);
        $this->addCarLoadItem($carLoad, $missingProduct, 20, 15);

        $inventory = $this->makeInventory($carLoad);
        // Only inventory Produit A — Produit B is missing
        $inventory->items()->create([
            'product_id' => $inventoriedProduct->id,
            'total_loaded' => 10,
            'total_sold' => 5,
            'total_returned' => 5,
        ]);

        $this->showCarLoad($carLoad)
            ->assertInertia(fn ($page) => $page
                ->has('missingInventoryProducts', 1)
                ->where('missingInventoryProducts.0.id', $missingProduct->id)
                ->where('missingInventoryProducts.0.name', 'Produit B')
            );
    }

    public function test_show_passes_empty_missing_products_when_all_items_are_inventoried(): void
    {
        $product = $this->makeProduct('Produit C');
        $carLoad = $this->makeActiveCarLoad();
        $this->addCarLoadItem($carLoad, $product, 12, 6);

        $inventory = $this->makeInventory($carLoad);
        $inventory->items()->create([
            'product_id' => $product->id,
            'total_loaded' => 12,
            'total_sold' => 6,
            'total_returned' => 6,
        ]);

        $this->showCarLoad($carLoad)
            ->assertInertia(fn ($page) => $page->has('missingInventoryProducts', 0));
    }

    public function test_show_passes_multiple_missing_products_when_several_are_not_inventoried(): void
    {
        $productA = $this->makeProduct('Alfa');
        $productB = $this->makeProduct('Beta');
        $productC = $this->makeProduct('Gamma');
        $carLoad = $this->makeActiveCarLoad();
        $this->addCarLoadItem($carLoad, $productA, 10, 5);
        $this->addCarLoadItem($carLoad, $productB, 20, 10);
        $this->addCarLoadItem($carLoad, $productC, 30, 15);

        // Only inventory Alfa; Beta and Gamma are missing
        $inventory = $this->makeInventory($carLoad);
        $inventory->items()->create([
            'product_id' => $productA->id,
            'total_loaded' => 10,
            'total_sold' => 5,
            'total_returned' => 5,
        ]);

        $this->showCarLoad($carLoad)
            ->assertInertia(fn ($page) => $page->has('missingInventoryProducts', 2));
    }
}
