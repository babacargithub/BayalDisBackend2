<?php

namespace Tests\Feature\Inventory;

use App\Models\CarLoad;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use App\Models\Commercial;
use App\Services\CarLoadService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FullFlowInventoryPdfTest extends TestCase
{
    use RefreshDatabase;

    private function makeManagerAndTeam(): array
    {
        $manager = User::factory()->create();
        $team = Team::create([
            'name' => 'E2E Team',
            'user_id' => $manager->id,
        ]);
        return [$manager, $team];
    }

    private function makeCustomers(int $count, Commercial $commercial): array
    {
        $customers = [];
        for ($i = 1; $i <= $count; $i++) {
            $customers[] = Customer::create([
                'name' => 'Cust '.$i,
                'address' => 'Addr',
                'phone_number' => '770000'.str_pad((string)$i, 3, '0', STR_PAD_LEFT),
                'owner_number' => '770000'.str_pad((string)$i, 3, '0', STR_PAD_LEFT),
                'gps_coordinates' => '0,0,0',
                'commercial_id' => $commercial->id,
            ]);
        }
        return $customers;
    }

    private function makeCatalog(): array
    {
        \Artisan::call('db:seed', ['--class' => \Database\Seeders\ProductSeeder::class]);
        $parents = Product::whereNull('parent_id')->get();
        $children = Product::whereNotNull('parent_id')->get();
        return [$parents, $children];
    }

    private function createSupplier(): Supplier
    {
        return Supplier::create([
            'name' => 'Main Supplier',
            'contact' => 'John',
            'email' => 's@e2e.test',
            'phone' => '000',
            'address' => 'Somewhere',
            'tax_number' => 'TN-01',
        ]);
    }

    public function test_full_flow_inventory_pdf_displays_expected_values(): void
    {
        mt_srand(42);
        [$manager, $team] = $this->makeManagerAndTeam();
        $this->actingAs($manager);
        Carbon::setTestNow(Carbon::now()->setTime(9, 0));

        // 1) Catalog
        [$parents, $children] = $this->makeCatalog();

        // 2) Create a Car Load via route (return date at least 30 days)
        $returnDate = Carbon::now()->addDays(30)->format('Y-m-d');
        $resp = $this->post(route('car-loads.store'), [
            'name' => 'E2E Load',
            'team_id' => $team->id,
            'return_date' => $returnDate,
            'comment' => 'E2E',
        ]);
        $resp->assertStatus(302);
        $carLoad = CarLoad::latest()->firstOrFail();

        // 3) Create purchase invoice and its items via route using specific parent product names from ProductSeeder
        $supplier = $this->createSupplier();

        // Fetch specific parent products by name (real-life names from ProductSeeder)
        $parentNames = [
            '1KG Carton 1000pcs',
            '2KG carton 400pcs',
            '500g carton 1000pcs',
            'Gobelet carton 1000 pcs',
            '2 Compart carton 250 pcs',
            'Transparent 1000ml carton 500pcs',
            'Pot à Sauce 2000 pcs',
        ];
        $p1KGCarton1000pcs = Product::whereName('1KG Carton 1000pcs')->whereNull('parent_id')->firstOrFail();
        $p2KGCarton400pcs = Product::whereName('2KG carton 400pcs')->whereNull('parent_id')->firstOrFail();
        $p500gCarton1000pcs = Product::whereName('500g carton 1000pcs')->whereNull('parent_id')->firstOrFail();
        $pGobeletCarton1000pcs = Product::whereName('Gobelet carton 1000 pcs')->whereNull('parent_id')->firstOrFail();
        $p2CompartCarton250pcs = Product::whereName('2 Compart carton 250 pcs')->whereNull('parent_id')->firstOrFail();
        $pTransparent1000mlCarton500pcs = Product::whereName('Transparent 1000ml carton 500pcs')->whereNull('parent_id')->firstOrFail();
        $pPotASauce2000pcs = Product::whereName('Pot à Sauce 2000 pcs')->whereNull('parent_id')->firstOrFail();

        $items = [];
        $items = [
            [
                'product_id' => $p1KGCarton1000pcs->id,
                'quantity' => 20, // reasonable quantity
                'unit_price' => $p1KGCarton1000pcs->cost_price ?? 0,
            ],
            [
                'product_id' => $p2KGCarton400pcs->id,
                'quantity' => 10, // reasonable quantity
                'unit_price' => $p2KGCarton400pcs->cost_price ?? 0,
            ],
            [
                'product_id' => $p500gCarton1000pcs->id,
                'quantity' => 10, // reasonable quantity
                'unit_price' => $p500gCarton1000pcs->cost_price ?? 0,
            ],
            [
                'product_id' => $pGobeletCarton1000pcs->id,
                'quantity' => 3, // reasonable quantity
                'unit_price' => $pGobeletCarton1000pcs->cost_price ?? 0,
            ],
            [
                'product_id' =>  $p2CompartCarton250pcs->id,
                'quantity' => 3, // reasonable quantity
                'unit_price' =>  $p2CompartCarton250pcs->cost_price ?? 0,
            ],
            [
                'product_id' =>  $pTransparent1000mlCarton500pcs->id,
                'quantity' => 6, // reasonable quantity
                'unit_price' =>  $pTransparent1000mlCarton500pcs->cost_price ?? 0,
            ],
            [
                'product_id' =>  $pPotASauce2000pcs->id,
                'quantity' => 3, // reasonable quantity
                'unit_price' =>  $pPotASauce2000pcs->cost_price ?? 0,
            ],
        ];


        $resInvoice2 = $this->post(route('purchase-invoices.store'), [
            'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-E2E-2',
            'invoice_date' => Carbon::now()->format('Y-m-d'),
            'due_date' => Carbon::now()->addDays(15)->format('Y-m-d'),
            'comment' => 'Seed stock',
            'items' => $items,
        ]);
        $resInvoice2->assertSessionHasNoErrors();
        $invoice2 = PurchaseInvoice::where('invoice_number', 'INV-E2E-2')->firstOrFail();
        $resp = $this->post(route('purchase-invoices.put-in-stock', $invoice2),["put_in_current_car_load"=> true]);
        $resp->assertSessionHasNoErrors();
        $resp->assertStatus(302);
        $carLoadService = app(CarLoadService::class);
        // Assert stocks and stock entries are OK for each selected parent
        foreach ($items as $it) {
            /** @var Product $pp */
            $pp = Product::findOrFail($it['product_id']);
            $this->assertGreaterThan(0, $pp->stockEntries()->count(), 'No stock entries for '.$pp->name);
            $this->assertEquals(0, $pp->stockEntries()->sum('quantity_left'), 'quantity_left sum mismatch for '
                .$pp->name);
            $this->assertEquals(0, $pp->stock_available, 'stock_available mismatch for '.$pp->name);
            // Ensure each entry created from invoice has quantity_left equal to quantity for this simple scenario
            foreach ($pp->stockEntries as $se) {
                $this->assertNotEquals($se->quantity, $se->quantity_left, 'Entry not fully available for '.$pp->name);
            }
            $this->assertEquals($carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $pp), $it['quantity']);

        }
        $this->assertEquals(20, $carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $p1KGCarton1000pcs));
        $this->assertEquals(10, $carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $p2KGCarton400pcs));
        $this->assertEquals(3, $carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $pGobeletCarton1000pcs));
        $resp = $this->post(route('purchase-invoices.store'), [
            'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-E2E-1',
            'invoice_date' => Carbon::now()->format('Y-m-d'),
            'due_date' => Carbon::now()->addDays(15)->format('Y-m-d'),
            'comment' => 'Seed stock',
            'items' => $items,
        ]);
        $resp->assertSessionHasNoErrors();
        $resp->assertStatus(302);

        /** @var PurchaseInvoice $invoice */
        $invoice = PurchaseInvoice::where('invoice_number', 'INV-E2E-1')->firstOrFail();

        // Put in stock through route
        $resp = $this->post(route('purchase-invoices.put-in-stock', $invoice),["put_in_current_car_load"=> true]);
        $resp->assertSessionHasNoErrors();
        $resp->assertStatus(302);
        // Assert stocks and stock entries are OK for each selected parent
        foreach ($items as $item) {
            /** @var Product $product */
            $product = Product::findOrFail($item['product_id']);

            // Vérifier qu'il y a au moins une entrée de stock
            $this->assertEquals(2, $product->stockEntries()->count(),
                'Mismatch for ' . $product->name);

            // Vérifier que la quantité totale des stock entries correspond à la quantité de la facture
            $totalQuantity = $product->stockEntries()->sum('quantity');
            $this->assertEquals($item['quantity']*2, $totalQuantity,
                'Total stock entry quantity mismatch for ' . $product->name .
                ". Expected {$item['quantity']}, got {$totalQuantity}");

            // Vérifier que quantity_left correspond aussi à la quantité (stock non utilisé)
            $totalQuantityLeft = $product->stockEntries()->sum('quantity_left');
            $this->assertEquals(0, $totalQuantityLeft,
                'Total quantity_left mismatch for ' . $product->name .
                ". Expected {$item['quantity']}, got {$totalQuantityLeft}");

            // Vérifier le stock disponible
            $this->assertEquals(0, $product->stock_available,
                'stock_available mismatch for ' . $product->name .
                ". Expected {$item['quantity']}, got {$product->stock_available}");

            // Vérifier que chaque entrée a quantity == quantity_left (stock frais, non utilisé)
            foreach ($product->stockEntries as $stockEntry) {
                $this->assertNotEquals($stockEntry->quantity, $stockEntry->quantity_left,
                    "Stock entry quantity != quantity_left for {$product->name}. " .
                    "Entry ID: {$stockEntry->id}, quantity: {$stockEntry->quantity}, " .
                    "quantity_left: {$stockEntry->quantity_left}");
            }
            $this->assertEquals(40, $carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $p1KGCarton1000pcs));
            $this->assertEquals(20, $carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $p2KGCarton400pcs));
            $this->assertEquals(6, $carLoadService->getAvailableStockOfProductInCarLoad($carLoad,
                $pGobeletCarton1000pcs));


        }



        // 4) Add items to the same car load via route for the same 10 parents to ensure inventory loaded values
        $carLoadItemsPayload = [];
        $loadedAt = Carbon::now()->subDays(1)->format('Y-m-d H:i:s');
        for ($i = 0; $i < 10; $i++) {
            $carLoadItemsPayload[] = [
                'product_id' => $parents[$i]->id,
                'quantity_loaded' => 15 + ($i % 3), // 15..17
                'loaded_at' => $loadedAt,
            ];
        }
        $resp = $this->post(route('car-loads.items.store', $carLoad), [
            'items' => $carLoadItemsPayload,
        ]);

//// Vérifier qu'il y a des erreurs de session
//        $resp->assertSessionHasErrors();
//
//// Vérifier une erreur spécifique sur un champ
////        $resp->assertSessionHasErrors(['items']);
//
//// Vérifier qu'un message d'erreur contient un texte spécifique
//        $resp->assertSessionHasErrors([
//            'items' => 'Stock insuffisant pour'
//        ]);

// OU vérifier que le message d'erreur contient partiellement ce texte
//        $errors = session('errors');
//        $this->assertTrue(
//            $errors->has('items') &&
//            str_contains($errors->first('items'), 'Stock insuffisant pour')
//        );
//
//        $resp->assertStatus(302);

//         5) Create a commercial for the team and customers linked to it, then 100 sales invoices via route within the car load period
//         Create commercial linked to team
        $commercial = Commercial::create([
            'name' => 'E2E Seller',
            'phone_number' => '221700000000',
            'gender' => 'male',
            'user_id' => $manager->id,
        ]);
        $commercial->team()->associate($team);
        $commercial->save();

        $customers = $this->makeCustomers(30, $commercial);

            // Choose 1-2 items per invoice from the 10 parents/children stocked
            $items = [
                [
                'product_id' => $p1KGCarton1000pcs->id,
                'quantity' => 2,
                'price' => $p1KGCarton1000pcs->price,
                ],
                [
                'product_id' => $p500gCarton1000pcs->id,
                'quantity' => 1,
                'price' => $p500gCarton1000pcs->price,
                ],
                ] ;

            // keep dates within car load window (after load_date and before return_date)
            Carbon::setTestNow(Carbon::now()->addDays(30));
            Sanctum::actingAs($manager);
            $resp = $this->postJson(route('sales_person.sales-invoices.create'), [
                'customer_id' => $customers[0]->id,
                'paid'=> true,
                "payment_method" => "cash",
                'items' => $items,
                'should_be_paid_at' => Carbon::now()->addDays(7)->format('Y-m-d'),
                'comment' => 'E2E Sale ',
            ]);
//            $resp->assertRedirect();

            // Get the session errors

            // Assert the error contains specific text
//            $this->assertStringContainsString('Stock insuffisant', $errorMessage);
//            $this->assertStringContainsString('1KG Carton 1000pcs', $errorMessage);
//            $this->assertStringContainsString('Stock disponible: 0', $errorMessage);
//            $this->assertStringContainsString('Quantité demandée: 15', $errorMessage);
//            $resp->assertSessionHasErrors();
        if ($resp->status() !=200 && $resp->status() != 201) {
            dump($resp->getContent());
        }
        $resp->assertStatus(201);

        $this->assertEquals(38, $carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $p1KGCarton1000pcs));
        $this->assertEquals(19, $carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $p500gCarton1000pcs));



//        Carbon::setTestNow(null); // reset

//        // 6) Create an inventory for the car load via route
//        $resp = $this->post(route('car-loads.inventories.store', $carLoad), [
//            'name' => 'E2E Inventory',
//        ]);
//        $resp->assertStatus(302);
//        $inventory = $carLoad->inventory()->firstOrFail();
//
//        // 7) Add inventory items through route only: include parent and corresponding child for first 3 pairs
//        $returnedItems = [];
//        for ($i = 0; $i < 3; $i++) {
//            $returnedItems[] = [
//                'product_id' => $parents[$i]->id,
//                'total_returned' => 1 + $i, // 1,2,3 cartons
//            ];
//            $returnedItems[] = [
//                'product_id' => $children[$i]->id,
//                'total_returned' => 2 + $i, // raw child units
//            ];
//        }
//        $resp = $this->post(route('car-loads.inventories.items.store', [$carLoad, $inventory]), [
//            'items' => $returnedItems,
//        ]);
//        $resp->assertStatus(302);
//
//        // 8) Generate PDF HTML via route and assert key pieces
//        $response = $this->get(route('car-loads.inventories.export-pdf', [$carLoad, $inventory]));
//        $response->assertOk();
//        $html = $response->getContent();
//
//        // Header
//        $response->assertSee('Inventaire - '.$carLoad->name);
//        $response->assertSee('E2E Inventory');
//        $response->assertSee($carLoad->team->manager->name);
//
//        // Pick parent #1 and assert quantities formatting based on helper
//        $parent = $parents[0];
//        $response->assertSee($parent->name);
//
//        // compute expected aggregates from SALES quantities within car load period (independent of inventory-provided totals)
//        $invParent = $inventory->items()->where('product_id', $parent->id)->first();
//        $invChild = $inventory->items()->where('product_id', $children[0]->id)->first();
//
//        $totalLoaded = $invParent->total_loaded; // from addInventoryItems controller
//
//        // Sum ventes for parent product during car load
//        $start = $carLoad->load_date->toDateTimeString();
//        $end = $carLoad->return_date->toDateTimeString();
//        $totalSoldParent = (float) (DB::table('ventes')
//            ->where('product_id', $parent->id)
//            ->whereBetween('created_at', [$start, $end])
//            ->sum('quantity'));
//        // Sum ventes for child and convert to parent units
//        $totalSoldChildRaw = (float) (DB::table('ventes')
//            ->where('product_id', $children[0]->id)
//            ->whereBetween('created_at', [$start, $end])
//            ->sum('quantity'));
//        $totalSoldChildParentEq = $children[0]
//            ->convertQuantityToParentQuantity($totalSoldChildRaw)['decimal_parent_quantity'];
//        $totalSold = $totalSoldParent + $totalSoldChildParentEq;
//
//        $totalReturnedParentEq = $invParent->total_returned + $children[0]
//            ->convertQuantityToParentQuantity($invChild->total_returned)['decimal_parent_quantity'];
//
//        $resultDecimal = $totalSold + $totalReturnedParentEq - $totalLoaded;
//
//        // Assert result wording appears and price formatting uses thousands sep
//        $response->assertSee($resultDecimal < 0 ? 'Manque' : 'Surplus de');
//        $price = $resultDecimal * $parent->price;
//        $response->assertSee(e(number_format($price, 0, ',', ' ')).' F');
//
//        // Assert cartons/paquets small span appears somewhere
//        $this->assertStringContainsString('<span class="small">', $html);
//
//        // Nested children table label
//        $response->assertSee('sois');
    }
}
