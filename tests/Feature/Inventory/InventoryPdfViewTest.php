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

/**
 * Feature tests for the inventory PDF view (resources/views/pdf/inventory.blade.php).
 *
 * Each test verifies that the rendered HTML matches the values computed by
 * CarLoadService::getCalculatedQuantitiesOfProductsInInventory() and assembled
 * by CarLoadController::exportInventoryPdf().
 *
 * Key formula (per parent product in decimal parent units):
 *   result = total_sold + total_returned - total_loaded
 *
 * Key price formula (when variant exists):
 *   price = round(variant.price × result_decimal × paquets_per_carton)
 *
 * Key display formula (cartons + paquets):
 *   cartons = abs(intval(decimal_quantity))
 *   paquets = (abs(decimal_quantity) - floor(abs(decimal_quantity))) × paquets_per_carton
 */
class InventoryPdfViewTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeManagerAndTeam(): array
    {
        $manager = User::factory()->create(['name' => 'Chef Équipe Test']);
        $team = Team::create(['name' => 'Équipe PDF', 'user_id' => $manager->id]);

        return [$manager, $team];
    }

    private function makeCarLoadForTeam(Team $team): CarLoad
    {
        return CarLoad::create([
            'name' => 'Chargement PDF Test',
            'team_id' => $team->id,
            'status' => 'SELLING',
            'load_date' => Carbon::now()->subDays(3),
            'return_date' => Carbon::now()->addDays(3),
            'returned' => false,
        ]);
    }

    private function makeInventory(CarLoad $carLoad, User $user, string $name = 'Inventaire PDF Test'): CarLoadInventory
    {
        return CarLoadInventory::create([
            'car_load_id' => $carLoad->id,
            'name' => $name,
            'user_id' => $user->id,
            'closed' => false,
        ]);
    }

    private function callExportPdf(User $manager, CarLoad $carLoad, CarLoadInventory $inventory)
    {
        return $this->actingAs($manager)
            ->get(route('car-loads.inventories.export-pdf', [$carLoad, $inventory]));
    }

    // ─── Header ───────────────────────────────────────────────────────────────

    public function test_pdf_header_shows_car_load_name_inventory_name_and_manager_name(): void
    {
        [$manager, $team] = $this->makeManagerAndTeam();
        $carLoad = $this->makeCarLoadForTeam($team);
        $inventory = $this->makeInventory($carLoad, $manager, 'Inventaire Février 2025');

        // Need at least one inventoried product so the service does not return an empty list
        $product = Product::create(['name' => 'Sel 1kg', 'price' => 200, 'cost_price' => 100, 'base_quantity' => 1]);
        $inventory->items()->create(['product_id' => $product->id, 'total_loaded' => 1, 'total_sold' => 1, 'total_returned' => 0]);

        $response = $this->callExportPdf($manager, $carLoad, $inventory);

        $response->assertOk();
        $response->assertSee('Inventaire - Chargement PDF Test');
        $response->assertSee('Inventaire Février 2025');
        $response->assertSee('Chef Équipe Test');
    }

    // ─── "Décompte OK" (zero result) ─────────────────────────────────────────

    public function test_zero_result_shows_decompte_ok_for_parent_only_product(): void
    {
        // result = total_sold + total_returned - total_loaded = 6 + 2 - 8 = 0
        [$manager, $team] = $this->makeManagerAndTeam();
        $carLoad = $this->makeCarLoadForTeam($team);
        $inventory = $this->makeInventory($carLoad, $manager);

        $product = Product::create(['name' => 'Farine 50kg', 'price' => 25000, 'cost_price' => 20000, 'base_quantity' => 1]);
        $inventory->items()->create(['product_id' => $product->id, 'total_loaded' => 8, 'total_sold' => 6, 'total_returned' => 2]);

        $response = $this->callExportPdf($manager, $carLoad, $inventory);

        $response->assertOk();
        $response->assertSee('Décompte OK');
        // Price must be 0 F with no negative class
        $html = $response->getContent();
        $this->assertStringNotContainsString('class="text-right result negative"', $html);
        $this->assertStringNotContainsString('class="text-right price negative"', $html);
    }

    // ─── Negative result — "Manque" ───────────────────────────────────────────

    public function test_negative_result_shows_manque_with_correct_carton_count_for_parent_only_product(): void
    {
        // result = 6 + 0 - 10 = -4.0  →  "Manque 4 cartons"
        [$manager, $team] = $this->makeManagerAndTeam();
        $carLoad = $this->makeCarLoadForTeam($team);
        $inventory = $this->makeInventory($carLoad, $manager);

        $product = Product::create(['name' => 'Sucre 1kg Sac', 'price' => 750, 'cost_price' => 500, 'base_quantity' => 1]);
        $inventory->items()->create(['product_id' => $product->id, 'total_loaded' => 10, 'total_sold' => 6, 'total_returned' => 0]);

        $response = $this->callExportPdf($manager, $carLoad, $inventory);

        $response->assertOk();
        $response->assertSee('Manque');
        $response->assertSee('4 cartons');
        $response->assertDontSee('Décompte OK');
        $response->assertDontSee('Surplus de');
    }

    public function test_negative_price_is_formatted_with_thousands_separator_and_has_negative_css_class(): void
    {
        // result = 12 + 0 - 20 = -8.0
        // price  = parent.price × result = 3 500 × (-8) = -28 000 F  (no variant → parent price used)
        [$manager, $team] = $this->makeManagerAndTeam();
        $carLoad = $this->makeCarLoadForTeam($team);
        $inventory = $this->makeInventory($carLoad, $manager);

        $product = Product::create(['name' => 'Riz 5kg', 'price' => 3500, 'cost_price' => 2500, 'base_quantity' => 1]);
        $inventory->items()->create(['product_id' => $product->id, 'total_loaded' => 20, 'total_sold' => 12, 'total_returned' => 0]);

        $response = $this->callExportPdf($manager, $carLoad, $inventory);
        $html = $response->getContent();

        $response->assertOk();
        // PHP number_format(-28000, 0, ',', ' ') → "-28 000"
        $response->assertSee('-28 000 F');
        $this->assertStringContainsString('class="text-right result negative"', $html);
        $this->assertStringContainsString('class="text-right price negative"', $html);
    }

    // ─── Loaded / sold / returned columns ─────────────────────────────────────

    public function test_loaded_sold_returned_columns_show_correct_carton_counts_for_parent_only_product(): void
    {
        // total_loaded=15, total_sold=12, total_returned=3  (no variant → all whole cartons)
        // result = 12 + 3 - 15 = 0 → "Décompte OK" — blade emits no <span class="small"> at all
        [$manager, $team] = $this->makeManagerAndTeam();
        $carLoad = $this->makeCarLoadForTeam($team);
        $inventory = $this->makeInventory($carLoad, $manager);

        $product = Product::create(['name' => 'Huile 5L Bidon', 'price' => 4000, 'cost_price' => 3000, 'base_quantity' => 1]);
        $inventory->items()->create(['product_id' => $product->id, 'total_loaded' => 15, 'total_sold' => 12, 'total_returned' => 3]);

        $response = $this->callExportPdf($manager, $carLoad, $inventory);
        $html = $response->getContent();

        $response->assertOk();
        $response->assertSee('15 cartons');
        $response->assertSee('12 cartons');
        $response->assertSee('3 cartons');
        $response->assertSee('Décompte OK');
        // No paquets span anywhere: neither in Qté columns (isMixed=false) nor in result cell (Décompte OK branch)
        $this->assertStringNotContainsString('<span class="small">', $html);
    }

    public function test_sold_and_returned_columns_show_paquets_when_variant_quantities_produce_fractional_cartons(): void
    {
        // paquetsPerCarton = parent.base_quantity / variant.base_quantity = 50 / 25 = 2
        //
        // Parent inv  : total_loaded=3, total_sold=2, total_returned=0
        // Variant inv : total_sold=1   → 1×(25/50)=0.5 carton
        //               total_returned=1 → 0.5 carton
        //
        // calculatedTotalLoaded   = 3      → convertQuantity(3.0) → 3 cartons, 0 paquets (NOT mixed)
        // calculatedTotalSold     = 2+0.5  = 2.5 → 2 cartons, 1 paquet  (IS mixed)
        // calculatedTotalReturned = 0+0.5  = 0.5 → 0 cartons, 1 paquet  (IS mixed)
        // result = 2.5 + 0.5 - 3 = 0 → "Décompte OK"
        [$manager, $team] = $this->makeManagerAndTeam();
        $carLoad = $this->makeCarLoadForTeam($team);
        $inventory = $this->makeInventory($carLoad, $manager);

        $parent = Product::create(['name' => 'Cola 50u Carton', 'price' => 10000, 'cost_price' => 8000, 'base_quantity' => 50]);
        $variant = Product::create(['name' => 'Cola 25u Demi', 'price' => 5500, 'cost_price' => 4000, 'parent_id' => $parent->id, 'base_quantity' => 25]);

        $inventory->items()->create(['product_id' => $parent->id, 'total_loaded' => 3, 'total_sold' => 2, 'total_returned' => 0]);
        $inventory->items()->create(['product_id' => $variant->id, 'total_loaded' => 0, 'total_sold' => 1, 'total_returned' => 1]);

        $response = $this->callExportPdf($manager, $carLoad, $inventory);
        $html = $response->getContent();

        $response->assertOk();
        // Loaded column: 3 cartons, 0 paquets → isMixed=false → no paquets span in loaded cell
        $response->assertSee('3 cartons');
        // Sold column: 2 cartons + 1 paquet span
        $response->assertSee('2 cartons');
        $this->assertMatchesRegularExpression('/2 cartons.*1 paquets/s', $html);
        // Returned column: 0 cartons + 1 paquet span
        $response->assertSee('0 cartons');
        $response->assertSee('1 paquets');
        // result = 0 → Décompte OK
        $response->assertSee('Décompte OK');
    }

    // ─── Variant name and paquet count in result cell ─────────────────────────

    public function test_variant_name_and_paquet_count_appear_in_result_cell_when_result_is_fractional_carton(): void
    {
        // paquetsPerCarton = 50 / 25 = 2
        // Parent inv : total_loaded=5, total_sold=2, total_returned=0
        // Variant inv: total_sold=1 → 0.5 carton, total_returned=0
        //
        // result = (2 + 0.5 + 0) - 5 = -2.5
        // resultConverted: cartons=abs(intval(-2.5))=2, decimal=0.5, paquets=0.5×2=1
        // childName = "Cola 25u Demi"
        //
        // price = variant.price × result × paquetsPerCarton = 5 500 × (-2.5) × 2 = -27 500 F
        [$manager, $team] = $this->makeManagerAndTeam();
        $carLoad = $this->makeCarLoadForTeam($team);
        $inventory = $this->makeInventory($carLoad, $manager);

        $parent = Product::create(['name' => 'Cola 50u Carton', 'price' => 10000, 'cost_price' => 8000, 'base_quantity' => 50]);
        $variant = Product::create(['name' => 'Cola 25u Demi', 'price' => 5500, 'cost_price' => 4000, 'parent_id' => $parent->id, 'base_quantity' => 25]);

        $inventory->items()->create(['product_id' => $parent->id, 'total_loaded' => 5, 'total_sold' => 2, 'total_returned' => 0]);
        $inventory->items()->create(['product_id' => $variant->id, 'total_loaded' => 0, 'total_sold' => 1, 'total_returned' => 0]);

        $response = $this->callExportPdf($manager, $carLoad, $inventory);
        $html = $response->getContent();

        $response->assertOk();
        $response->assertSee('Manque');
        $response->assertSee('2 cartons');
        $response->assertSee('1 paquets');
        $response->assertSee('Cola 25u Demi'); // variant name shown in result cell
        $response->assertSee('-27 500 F');
        $this->assertStringContainsString('class="text-right result negative"', $html);
        $this->assertStringContainsString('class="text-right price negative"', $html);
    }

    public function test_variant_name_appears_in_result_cell_even_when_paquets_count_is_zero(): void
    {
        // paquetsPerCarton = 24 / 1 = 24
        // Parent inv : total_loaded=2, total_sold=1, total_returned=0
        // result = 1 + 0 - 2 = -1.0
        // resultConverted: cartons=1, paquets=0 (whole carton, no decimal), childName="Savon Tablette"
        // price = 600 × (-1.0) × 24 = -14 400 F
        [$manager, $team] = $this->makeManagerAndTeam();
        $carLoad = $this->makeCarLoadForTeam($team);
        $inventory = $this->makeInventory($carLoad, $manager);

        $parent = Product::create(['name' => 'Savon Carton 24u', 'price' => 12000, 'cost_price' => 9000, 'base_quantity' => 24]);
        Product::create(['name' => 'Savon Tablette', 'price' => 600, 'cost_price' => 400, 'parent_id' => $parent->id, 'base_quantity' => 1]);

        $inventory->items()->create(['product_id' => $parent->id, 'total_loaded' => 2, 'total_sold' => 1, 'total_returned' => 0]);

        $response = $this->callExportPdf($manager, $carLoad, $inventory);

        $response->assertOk();
        $response->assertSee('Manque');
        $response->assertSee('1 cartons');
        $response->assertSee('Savon Tablette'); // variant name in result cell
        $response->assertSee('-14 400 F');
    }

    // ─── RESULTAT total row ────────────────────────────────────────────────────

    public function test_total_resultat_row_shows_sum_of_all_item_prices_for_multiple_products(): void
    {
        // Product A: result=0   → price=0
        // Product B: result=-3  → price = 1 500 × (-3) = -4 500 F
        // Total = 0 + (-4 500) = -4 500 F  → "-4 500 F" in the RESULTAT row
        [$manager, $team] = $this->makeManagerAndTeam();
        $carLoad = $this->makeCarLoadForTeam($team);
        $inventory = $this->makeInventory($carLoad, $manager);

        $productA = Product::create(['name' => 'Sel 1kg', 'price' => 200, 'cost_price' => 150, 'base_quantity' => 1]);
        $productB = Product::create(['name' => 'Tomate Conserve', 'price' => 1500, 'cost_price' => 1000, 'base_quantity' => 1]);

        // Product A: loaded=5, sold=5, returned=0 → result=0
        $inventory->items()->create(['product_id' => $productA->id, 'total_loaded' => 5, 'total_sold' => 5, 'total_returned' => 0]);
        // Product B: loaded=10, sold=7, returned=0 → result=-3 → price=-4 500
        $inventory->items()->create(['product_id' => $productB->id, 'total_loaded' => 10, 'total_sold' => 7, 'total_returned' => 0]);

        $response = $this->callExportPdf($manager, $carLoad, $inventory);

        $response->assertOk();
        $response->assertSee('RESULTAT');
        $response->assertSee('-4 500 F'); // total price in the RESULTAT row
    }

    public function test_total_resultat_row_has_negative_css_class_when_sum_is_negative(): void
    {
        // result=-3, price=-4 500 → totalPrice=-4 500 < 0 → negative class on total row
        [$manager, $team] = $this->makeManagerAndTeam();
        $carLoad = $this->makeCarLoadForTeam($team);
        $inventory = $this->makeInventory($carLoad, $manager);

        $product = Product::create(['name' => 'Tomate Conserve', 'price' => 1500, 'cost_price' => 1000, 'base_quantity' => 1]);
        $inventory->items()->create(['product_id' => $product->id, 'total_loaded' => 10, 'total_sold' => 7, 'total_returned' => 0]);

        $response = $this->callExportPdf($manager, $carLoad, $inventory);
        $html = $response->getContent();

        $response->assertOk();
        $this->assertStringContainsString('class="text-right price negative"', $html);
    }

    public function test_total_resultat_row_shows_positive_value_when_items_prices_sum_to_positive(): void
    {
        // Two products: A loses -1 500 F, B gains +3 000 F → total +1 500 F
        // (Positive result means more sold/returned than loaded — possible via inventory corrections)
        [$manager, $team] = $this->makeManagerAndTeam();
        $carLoad = $this->makeCarLoadForTeam($team);
        $inventory = $this->makeInventory($carLoad, $manager);

        // Product A: result = 4 + 0 - 5 = -1 → price = 1 500 × (-1) = -1 500
        $productA = Product::create(['name' => 'Poivre 100g', 'price' => 1500, 'cost_price' => 1000, 'base_quantity' => 1]);
        $inventory->items()->create(['product_id' => $productA->id, 'total_loaded' => 5, 'total_sold' => 4, 'total_returned' => 0]);

        // Product B: result = 6 + 0 - 4 = +2 → price = 1 500 × 2 = +3 000
        $productB = Product::create(['name' => 'Gingembre 100g', 'price' => 1500, 'cost_price' => 1000, 'base_quantity' => 1]);
        $inventory->items()->create(['product_id' => $productB->id, 'total_loaded' => 4, 'total_sold' => 6, 'total_returned' => 0]);

        $response = $this->callExportPdf($manager, $carLoad, $inventory);
        $html = $response->getContent();

        $response->assertOk();
        // total = -1 500 + 3 000 = 1 500 F (no negative class on total row)
        $response->assertSee('1 500 F');
        $this->assertStringNotContainsString(
            '<td colspan="4" style="text-align: center"><strong>RESULTAT</strong></td>'.PHP_EOL.
            '        <td colspan="2" style="text-align: center"'.PHP_EOL.
            '            class="text-right price negative"',
            $html
        );
    }

    // ─── Footer — "Inventaire clôturé" ────────────────────────────────────────

    public function test_closed_inventory_shows_inventaire_cloture_footer(): void
    {
        [$manager, $team] = $this->makeManagerAndTeam();
        $carLoad = $this->makeCarLoadForTeam($team);
        $inventory = $this->makeInventory($carLoad, $manager);

        $product = Product::create(['name' => 'Café Paquet', 'price' => 2000, 'cost_price' => 1500, 'base_quantity' => 1]);
        $inventory->items()->create(['product_id' => $product->id, 'total_loaded' => 3, 'total_sold' => 3, 'total_returned' => 0]);

        // Close the inventory
        $inventory->update(['closed' => true]);

        $response = $this->callExportPdf($manager, $carLoad, $inventory);

        $response->assertOk();
        $response->assertSee('Inventaire clôturé');
    }

    public function test_open_inventory_does_not_show_inventaire_cloture_footer(): void
    {
        [$manager, $team] = $this->makeManagerAndTeam();
        $carLoad = $this->makeCarLoadForTeam($team);
        $inventory = $this->makeInventory($carLoad, $manager); // closed=false by default

        $product = Product::create(['name' => 'Café Paquet', 'price' => 2000, 'cost_price' => 1500, 'base_quantity' => 1]);
        $inventory->items()->create(['product_id' => $product->id, 'total_loaded' => 3, 'total_sold' => 3, 'total_returned' => 0]);

        $response = $this->callExportPdf($manager, $carLoad, $inventory);

        $response->assertOk();
        $response->assertDontSee('Inventaire clôturé');
    }

    // ─── from_previous_car_load items contribution to loaded column ───────────

    public function test_from_previous_car_load_variant_items_are_included_in_loaded_total_and_affect_result(): void
    {
        // paquetsPerCarton = 50 / 25 = 2
        // Parent inv : total_loaded=3 (from inventory item — does NOT add from_previous_car_load automatically)
        // from_previous CarLoadItem for variant: quantity_loaded=1 → 1×(25/50)=0.5 carton added to total_loaded
        //
        // calculatedTotalLoaded = 3 + 0.5 = 3.5
        // Variant inv: total_sold=0, total_returned=0  (no variant inv items)
        // calculatedTotalSold = 3 (parent only, set in inventory item)
        // calculatedTotalReturned = 0
        //
        // result = 3 + 0 - 3.5 = -0.5
        // resultConverted: cartons=abs(intval(-0.5))=0, decimal=0.5, paquets=0.5×2=1
        // price = variant.price × (-0.5) × 2 = 5 500 × (-0.5) × 2 = -5 500 F
        //
        // Loaded column: convertQuantity(3.5) → 3 cartons + 1 paquet (isMixed=true)
        [$manager, $team] = $this->makeManagerAndTeam();
        $carLoad = $this->makeCarLoadForTeam($team);
        $inventory = $this->makeInventory($carLoad, $manager);

        $parent = Product::create(['name' => 'Eau 1L Carton', 'price' => 10000, 'cost_price' => 8000, 'base_quantity' => 50]);
        $variant = Product::create(['name' => 'Eau 1L Demi', 'price' => 5500, 'cost_price' => 4000, 'parent_id' => $parent->id, 'base_quantity' => 25]);

        // Inventory item for parent: total_loaded=3 (the "direct" loaded count)
        $inventory->items()->create(['product_id' => $parent->id, 'total_loaded' => 3, 'total_sold' => 3, 'total_returned' => 0]);

        // CarLoadItem from a previous car load for the variant (contributes to total_loaded)
        CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $variant->id,
            'quantity_loaded' => 1, // → 1 × (25/50) = 0.5 parent carton added to total_loaded
            'quantity_left' => 1,
            'from_previous_car_load' => true,
            'loaded_at' => now()->subDay(),
        ]);

        $response = $this->callExportPdf($manager, $carLoad, $inventory);
        $html = $response->getContent();

        $response->assertOk();
        // Loaded column must show 3 cartons + 1 paquet (from from_previous item: 0.5 carton = 1 paquet)
        $response->assertSee('3 cartons');
        $this->assertMatchesRegularExpression('/3 cartons.*1 paquets/s', $html);
        // Result column: Manque 0 cartons 1 paquet de Eau 1L Demi
        $response->assertSee('Manque');
        $response->assertSee('0 cartons');
        $response->assertSee('1 paquets');
        $response->assertSee('Eau 1L Demi');
        $response->assertSee('-5 500 F');
    }

    // ─── Product name ─────────────────────────────────────────────────────────

    public function test_parent_product_name_appears_in_the_product_column(): void
    {
        [$manager, $team] = $this->makeManagerAndTeam();
        $carLoad = $this->makeCarLoadForTeam($team);
        $inventory = $this->makeInventory($carLoad, $manager);

        $product = Product::create(['name' => 'Lait Concentré Carton', 'price' => 8000, 'cost_price' => 6000, 'base_quantity' => 1]);
        $inventory->items()->create(['product_id' => $product->id, 'total_loaded' => 4, 'total_sold' => 4, 'total_returned' => 0]);

        $response = $this->callExportPdf($manager, $carLoad, $inventory);

        $response->assertOk();
        $response->assertSee('Lait Concentré Carton');
    }

    // ─── Products not in inventory are skipped ────────────────────────────────

    public function test_parent_products_not_in_inventory_are_not_shown_in_the_pdf(): void
    {
        [$manager, $team] = $this->makeManagerAndTeam();
        $carLoad = $this->makeCarLoadForTeam($team);
        $inventory = $this->makeInventory($carLoad, $manager);

        $inventoriedProduct = Product::create(['name' => 'Produit Inventorié', 'price' => 500, 'cost_price' => 300, 'base_quantity' => 1]);
        Product::create(['name' => 'Produit Non Inventorié', 'price' => 500, 'cost_price' => 300, 'base_quantity' => 1]);

        $inventory->items()->create(['product_id' => $inventoriedProduct->id, 'total_loaded' => 2, 'total_sold' => 2, 'total_returned' => 0]);
        // $notInventoriedProduct has no inventory item — service must skip it

        $response = $this->callExportPdf($manager, $carLoad, $inventory);

        $response->assertOk();
        $response->assertSee('Produit Inventorié');
        $response->assertDontSee('Produit Non Inventorié');
    }
}
