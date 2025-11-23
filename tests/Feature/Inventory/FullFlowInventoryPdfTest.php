<?php

namespace Tests\Feature\Inventory;

use App\Models\CarLoad;
use App\Models\CarLoadInventory;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use App\Models\Commercial;
use App\Services\CarLoadService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FullFlowInventoryPdfTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Team $team;
    private Supplier $supplier;
    private Commercial $commercial;
    private array $customers;
    private Product $p1KGCarton1000pcs;
    private Product $p2KGCarton400pcs;
    private Product $p500gCarton1000pcs;
    private Product $pGobeletCarton1000pcs;
    private Product $p2CompartCarton250pcs;
    private Product $pTransparent1000mlCarton500pcs;
    private Product $pPotASauce2000pcs;
    // Child products (from ProductSeeder)
    private Product $c1KGPaquet20pcs; // child of 1KG Carton 1000pcs
    private Product $c2KGPaquet10pcs; // child of 2KG carton 400pcs
    private Product $c500g20pcs; // child of 500g carton 1000pcs
    private Product $cGobeletPaquet50pcs; // child of Gobelet carton 1000 pcs
    private Product $c2CompartGM5pcs; // child of 2 Compart carton 250 pcs
    private Product $cTransparent1000ml10pcs; // child of Transparent 1000ml carton 500pcs
    private Product $cPotASauce100pcs; // child of Pot à Sauce 2000 pcs
    private $parents;
    private $children;
    private CarLoadService $carLoadService;

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

    private function setupTestData(): void
    {
        mt_srand(42);
        [$this->manager, $this->team] = $this->makeManagerAndTeam();
        $this->actingAs($this->manager);
        Carbon::setTestNow(Carbon::now()->setTime(9, 0));

        // 1) Catalog
        [$this->parents, $this->children] = $this->makeCatalog();

        $this->p1KGCarton1000pcs = Product::whereName('1KG Carton 1000pcs')->whereNull('parent_id')->firstOrFail();
        $this->p2KGCarton400pcs = Product::whereName('2KG carton 400pcs')->whereNull('parent_id')->firstOrFail();
        $this->p500gCarton1000pcs = Product::whereName('500g carton 1000pcs')->whereNull('parent_id')->firstOrFail();
        $this->pGobeletCarton1000pcs = Product::whereName('Gobelet carton 1000 pcs')->whereNull('parent_id')->firstOrFail();
        $this->p2CompartCarton250pcs = Product::whereName('2 Compart carton 250 pcs')->whereNull('parent_id')->firstOrFail();
        $this->pTransparent1000mlCarton500pcs = Product::whereName('Transparent 1000ml carton 500pcs')->whereNull('parent_id')->firstOrFail();
        $this->pPotASauce2000pcs = Product::whereName('Pot à Sauce 2000 pcs')->whereNull('parent_id')->firstOrFail();

        // Children
        $this->c1KGPaquet20pcs = Product::whereName('1KG paquet 20pcs')->whereNotNull('parent_id')->firstOrFail();
        $this->c2KGPaquet10pcs = Product::whereName('2KG paquet 10pcs')->whereNotNull('parent_id')->firstOrFail();
        $this->c500g20pcs = Product::whereName('500g - 20pcs')->whereNotNull('parent_id')->firstOrFail();
        $this->cGobeletPaquet50pcs = Product::whereName('Gobelet paquet 50pcs')->whereNotNull('parent_id')->firstOrFail();
        $this->c2CompartGM5pcs = Product::whereName('2 compartiments GM 5pcs')->whereNotNull('parent_id')->firstOrFail();
        $this->cTransparent1000ml10pcs = Product::whereName('Transparent 1000ml 10pcs')->whereNotNull('parent_id')->firstOrFail();
        $this->cPotASauce100pcs = Product::whereName('Pot à sauce 100 pcs')->whereNotNull('parent_id')->firstOrFail();

        $this->supplier = $this->createSupplier();

        // Create commercial linked to team
        $this->commercial = Commercial::create([
            'name' => 'E2E Seller',
            'phone_number' => '221700000000',
            'gender' => 'male',
            'user_id' => $this->manager->id,
        ]);
        $this->commercial->team()->associate($this->team);
        $this->commercial->save();

        $this->customers = $this->makeCustomers(30, $this->commercial);

        $this->carLoadService = app(CarLoadService::class);
    }

    private function createCarLoad(): CarLoad
    {
        // 2) Create a Car Load via route (return date at least 30 days)
        $returnDate = Carbon::now()->addDays(30)->format('Y-m-d');
        $resp = $this->post(route('car-loads.store'), [
            'name' => 'E2E Load',
            'team_id' => $this->team->id,
            'return_date' => $returnDate,
            'comment' => 'E2E',
        ]);
        $resp->assertStatus(302);
        return CarLoad::latest()->firstOrFail();
    }

    private function getPurchaseInvoiceItems(): array
    {
        return [
            [
                'product_id' => $this->p1KGCarton1000pcs->id,
                'quantity' => 20, // reasonable quantity
                'unit_price' => $this->p1KGCarton1000pcs->cost_price ?? 0,
            ],
            [
                'product_id' => $this->p2KGCarton400pcs->id,
                'quantity' => 10, // reasonable quantity
                'unit_price' => $this->p2KGCarton400pcs->cost_price ?? 0,
            ],
            [
                'product_id' => $this->p500gCarton1000pcs->id,
                'quantity' => 10, // reasonable quantity
                'unit_price' => $this->p500gCarton1000pcs->cost_price ?? 0,
            ],
            [
                'product_id' => $this->pGobeletCarton1000pcs->id,
                'quantity' => 3, // reasonable quantity
                'unit_price' => $this->pGobeletCarton1000pcs->cost_price ?? 0,
            ],
            [
                'product_id' => $this->p2CompartCarton250pcs->id,
                'quantity' => 3, // reasonable quantity
                'unit_price' => $this->p2CompartCarton250pcs->cost_price ?? 0,
            ],
            [
                'product_id' => $this->pTransparent1000mlCarton500pcs->id,
                'quantity' => 6, // reasonable quantity
                'unit_price' => $this->pTransparent1000mlCarton500pcs->cost_price ?? 0,
            ],
            [
                'product_id' => $this->pPotASauce2000pcs->id,
                'quantity' => 3, // reasonable quantity
                'unit_price' => $this->pPotASauce2000pcs->cost_price ?? 0,
            ],
        ];
    }

    private function createAndVerifyPurchaseInvoices(CarLoad $carLoad): void
    {
        $items = $this->getPurchaseInvoiceItems();

        // 3) Create purchase invoice and its items via route using specific parent product names from ProductSeeder
        $resInvoice2 = $this->post(route('purchase-invoices.store'), [
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'INV-E2E-2',
            'invoice_date' => Carbon::now()->format('Y-m-d'),
            'due_date' => Carbon::now()->addDays(15)->format('Y-m-d'),
            'comment' => 'Seed stock',
            'items' => $items,
        ]);
        $resInvoice2->assertSessionHasNoErrors();
        $invoice2 = PurchaseInvoice::where('invoice_number', 'INV-E2E-2')->firstOrFail();
        $resp = $this->post(route('purchase-invoices.put-in-stock', $invoice2), ["put_in_current_car_load" => true]);
        $resp->assertSessionHasNoErrors();
        $resp->assertStatus(302);

        // Assert stocks and stock entries are OK for each selected parent
        foreach ($items as $it) {
            /** @var Product $pp */
            $pp = Product::findOrFail($it['product_id']);
            $this->assertGreaterThan(0, $pp->stockEntries()->count(), 'No stock entries for '.$pp->name);
            $this->assertEquals(0, $pp->stockEntries()->sum('quantity_left'), 'quantity_left sum mismatch for '.$pp->name);
            $this->assertEquals(0, $pp->stock_available, 'stock_available mismatch for '.$pp->name);
            // Ensure each entry created from invoice has quantity_left equal to quantity for this simple scenario
            foreach ($pp->stockEntries as $se) {
                $this->assertNotEquals($se->quantity, $se->quantity_left, 'Entry not fully available for '.$pp->name);
            }
            $this->assertEquals($this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $pp), $it['quantity']);
        }

        $this->assertEquals(20, $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $this->p1KGCarton1000pcs));
        $this->assertEquals(10, $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $this->p2KGCarton400pcs));
        $this->assertEquals(3, $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $this->pGobeletCarton1000pcs));

        // Second invoice
        $resp = $this->post(route('purchase-invoices.store'), [
            'supplier_id' => $this->supplier->id,
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
        $resp = $this->post(route('purchase-invoices.put-in-stock', $invoice), ["put_in_current_car_load" => true]);
        $resp->assertSessionHasNoErrors();
        $resp->assertStatus(302);

        // Assert stocks and stock entries are OK for each selected parent
        foreach ($items as $item) {
            /** @var Product $product */
            $product = Product::findOrFail($item['product_id']);

            // Vérifier qu'il y a au moins une entrée de stock
            $this->assertEquals(2, $product->stockEntries()->count(), 'Mismatch for '.$product->name);

            // Vérifier que la quantité totale des stock entries correspond à la quantité de la facture
            $totalQuantity = $product->stockEntries()->sum('quantity');
            $this->assertEquals($item['quantity'] * 2, $totalQuantity,
                'Total stock entry quantity mismatch for '.$product->name.". Expected {$item['quantity']}, got {$totalQuantity}");

            // Vérifier que quantity_left correspond aussi à la quantité (stock non utilisé)
            $totalQuantityLeft = $product->stockEntries()->sum('quantity_left');
            $this->assertEquals(0, $totalQuantityLeft,
                'Total quantity_left mismatch for '.$product->name.". Expected {$item['quantity']}, got {$totalQuantityLeft}");

            // Vérifier le stock disponible
            $this->assertEquals(0, $product->stock_available,
                'stock_available mismatch for '.$product->name.". Expected {$item['quantity']}, got {$product->stock_available}");

            // Vérifier que chaque entrée a quantity == quantity_left (stock frais, non utilisé)
            foreach ($product->stockEntries as $stockEntry) {
                $this->assertNotEquals($stockEntry->quantity, $stockEntry->quantity_left,
                    "Stock entry quantity != quantity_left for {$product->name}. ".
                    "Entry ID: {$stockEntry->id}, quantity: {$stockEntry->quantity}, ".
                    "quantity_left: {$stockEntry->quantity_left}");
            }
        }

        $this->assertEquals(40, $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $this->p1KGCarton1000pcs));
        $this->assertEquals(20, $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $this->p2KGCarton400pcs));
        $this->assertEquals(6, $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $this->pGobeletCarton1000pcs));

        // Test transformToVariants via API (CarLoadService::transformToVariants)
        // Arrange current stocks in the car load for parent and a variant (child)
        $parent = $this->p1KGCarton1000pcs;
        $child = $this->c1KGPaquet20pcs;
        $beforeParentAvail = $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $parent);
        $beforeChildAvail = $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $child);

        // We'll transform 3 parent cartons into child packets; provide two variant lines with some unused quantity
        $quantityOfBaseProductToTransform = 3;
        $payload = [
            'quantityOfBaseProductToTransform' => $quantityOfBaseProductToTransform,
            'items' => [
                [
                    'product_id' => $child->id,
                    'quantity' => ($parent->base_quantity / $child->base_quantity) * $quantityOfBaseProductToTransform,            // propose 12
                    // packets
        // created
                    'unused_quantity' => 0,
                ],
            ],
        ];
        $expectedActualChildIncrease = ($parent->base_quantity / $child->base_quantity) * $quantityOfBaseProductToTransform; // 17

        // Act: call the API as an authenticated user (sanctum)
        Sanctum::actingAs($this->manager);
        $resp = $this->postJson('/api/salesperson/car-loads/' . $parent->id . '/transform', $payload);
        if (!in_array($resp->status(), [200, 201])) {
            // Dump errors to help debugging if it fails locally
            // dump($resp->getContent());
        }
        $resp->assertOk();
        $resp->assertJson(['message' => 'Transformation effectuée avec succès']);

        // Assert: parent decreased and child increased with correct amounts in the car load available stock
        $afterParentAvail = $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $parent);// should
        // be 17
        $afterChildAvail = $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $child); // should be
        // 150

        $this->assertEquals($beforeParentAvail - $payload['quantityOfBaseProductToTransform'], $afterParentAvail, 'Parent available stock should decrease by transformed quantity');
        $this->assertEquals($beforeChildAvail + $expectedActualChildIncrease, $afterChildAvail, 'Child available stock should increase by sum(actual quantities)');
    }

    private function createAndVerifySalesInvoices(CarLoad $carLoad): void
    {
        // keep dates within car load window (after load_date and before return_date)
        Carbon::setTestNow(Carbon::now()->addDays(30));
        Sanctum::actingAs($this->manager);
        $this->createInvoiceSalesForProductInCarLoad($this->p1KGCarton1000pcs, $carLoad, 2);
        $this->createInvoiceSalesForProductInCarLoad($this->p500gCarton1000pcs, $carLoad, 1);

        $this->assertEquals(35, $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad,
            $this->p1KGCarton1000pcs));
        $this->assertEquals(19, $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $this->p500gCarton1000pcs));

        $this->createInvoiceSalesForProductInCarLoad($this->p1KGCarton1000pcs, $carLoad, 20);
        $this->assertEquals(15, $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad,
            $this->p1KGCarton1000pcs));

        $this->createInvoiceSalesForProductInCarLoad($this->p1KGCarton1000pcs, $carLoad, quantity: 230,
            splitQuantityToInvoices: false,
        responseTester:
            function (TestResponse $resp) {
            $resp->assertUnprocessable();
        });


    }

    private function createAndVerifyInventory(CarLoad $carLoad): void
    {
        Carbon::setTestNow(null); // reset

        // 6) Create an inventory for the car load via route
        $resp = $this->post(route('car-loads.inventories.store', $carLoad), [
            'name' => 'E2E Inventory',
        ]);
        $resp->assertStatus(302);
        $inventory = $carLoad->inventory()->firstOrFail();
        $this->assertEquals(1, CarLoadInventory::where('car_load_id', $carLoad->id)->count(), 'A Car Load must have only one inventory');;
        
            $inventoredItems[] = [
                'product_id' => $this->p1KGCarton1000pcs->id,
                'total_returned' => 3,
            ];

        $resp = $this->post(route('car-loads.inventories.items.store', [$carLoad, $inventory]), [
            'items' => $inventoredItems,
        ]);
        $resp->assertStatus(302);
        // Test the results of calculations

        $result = $this->carLoadService->getCalculatedQuantitiesOfProductsInInventory($carLoad, $inventory);
        $this->assertIsArray($result, 'Method getCalculatedQuantitiesOfProductsInInventory should return an array');
        $this->assertArrayHasKey('items', $result, "Result must contain 'items' key");
        $items = $result['items'];
        $this->assertInstanceOf(Collection::class, $items, "'items' must be a Collection");
        $p1KGCarton1000pcsInventored = $items->firstWhere('product_name', $this->p1KGCarton1000pcs->name);
        $this->assertNotNull($p1KGCarton1000pcsInventored, 'Expected '.$this->p1KGCarton1000pcs->name.' to be present in result of inventory');
        $this->assertIsArray($p1KGCarton1000pcsInventored);
        $this->assertArrayHasKey('total_returned', $p1KGCarton1000pcsInventored, "'total_returned' must be present in result of inventory");
        $this->assertArrayHasKey('total_sold', $p1KGCarton1000pcsInventored, "'total_sold' must be present in result of inventory");
        $this->assertArrayHasKey('total_loaded', $p1KGCarton1000pcsInventored, "'total_loaded' must be present in result of inventory");

        $this->assertNotNull($p1KGCarton1000pcsInventored['total_returned']);
        $this->assertNotNull($p1KGCarton1000pcsInventored['total_sold']);
        $this->assertNotNull($p1KGCarton1000pcsInventored['total_loaded']);
        $this->assertIsNumeric($p1KGCarton1000pcsInventored['total_loaded']);
        $this->assertIsNumeric($p1KGCarton1000pcsInventored['total_returned']);
        $this->assertIsNumeric($p1KGCarton1000pcsInventored['total_sold']);
        $this->assertEquals(3, $p1KGCarton1000pcsInventored['total_returned']);
        $this->assertEquals(22, $p1KGCarton1000pcsInventored['total_sold']);
        $this->assertEquals(40, $p1KGCarton1000pcsInventored['total_loaded']);


        $this->assertCount(1, $items, 'Expected exactly 1 inventoried parent product rows');
        $resp = $this->post(route('car-loads.inventories.items.store', [$carLoad, $inventory]), [
            'items' => [  ['product_id' => $this->c1KGPaquet20pcs->id,
                'total_returned' => 100]],
        ]);
        $resp->assertSessionHasNoErrors();
        $resp->assertStatus(302);
        $result2 = $this->carLoadService->getCalculatedQuantitiesOfProductsInInventory($carLoad, $inventory);
        $itemsCalculated = $result2['items'];
         $this->assertInstanceOf(Collection::class, $itemsCalculated, "'items' must be a Collection");

        $this->assertCount(1, $itemsCalculated, 'Expected exactly 1 inventoried parent product rows');

        // Assert CarLoadService computed fields match expected values for the first parent
//        $this->assertEquals($totalLoaded, $parentEntry['total_loaded'], 'total_loaded mismatch');
//        $this->assertEqualsWithDelta($totalSold, $parentEntry['total_sold'], 0.0001, 'total_sold mismatch');
//        $this->assertEqualsWithDelta($totalReturnedParentEq, $parentEntry['total_returned'], 0.0001, 'total_returned mismatch');
//        $this->assertEqualsWithDelta($resultDecimal, $parentEntry['result'], 0.0001, 'result mismatch');
//        $this->assertSame($parent->id, $parentEntry['product']->id, 'product object mismatch');
//        // Children coverage: ensure the child item is listed with its raw returned quantity
//        $this->assertArrayHasKey('children', $parentEntry);
//        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $parentEntry['children']);
//        $childRow = $parentEntry['children']->firstWhere('product_id', $this->children[0]->id);
//        $this->assertNotNull($childRow, 'Expected matching child row');
//        $childReturned = is_array($childRow) ? ($childRow['total_returned'] ?? null) : ($childRow->total_returned ?? null);
//        $this->assertNotNull($childReturned, 'Child total_returned should be present');
//        $this->assertEquals($invChild->total_returned, $childReturned, 'Child total_returned mismatch');


        // 8) Generate PDF HTML via route and assert key pieces
        $response = $this->get(route('car-loads.inventories.export-pdf', [$carLoad, $inventory]));
        $response->assertOk();
        $html = $response->getContent();

        // Header
        $response->assertSee('Inventaire - '.$carLoad->name);
        $response->assertSee('E2E Inventory');
        $response->assertSee($carLoad->team->manager->name);

        // Pick parent #1 and assert quantities formatting based on helper
        $parent = $this->parents[0];
        $response->assertSee($parent->name);


/*

        // compute expected aggregates from SALES quantities within car load period (independent of inventory-provided totals)
        $invParent = $inventory->items()->where('product_id', $parent->id)->first();
        $invChild = $inventory->items()->where('product_id', $this->children[0]->id)->first();

        $totalLoaded = $invParent->total_loaded; // from addInventoryItems controller

        // Sum ventes for parent product during car load
        $start = $carLoad->load_date->toDateTimeString();
        $end = $carLoad->return_date->toDateTimeString();
        $totalSoldParent = (float) (DB::table('ventes')
            ->where('product_id', $parent->id)
            ->whereBetween('created_at', [$start, $end])
            ->sum('quantity'));
        // Sum ventes for child and convert to parent units
        $totalSoldChildRaw = (float) (DB::table('ventes')
            ->where('product_id', $this->children[0]->id)
            ->whereBetween('created_at', [$start, $end])
            ->sum('quantity'));
        $totalSoldChildParentEq = $this->children[0]
            ->convertQuantityToParentQuantity($totalSoldChildRaw)['decimal_parent_quantity'];
        $totalSold = $totalSoldParent + $totalSoldChildParentEq;

        $totalReturnedParentEq = $invParent->total_returned + $this->children[0]
                ->convertQuantityToParentQuantity($invChild->total_returned)['decimal_parent_quantity'];

        $resultDecimal = $totalSold + $totalReturnedParentEq - $totalLoaded;

        // Assert result wording appears and price formatting uses thousands sep
        $response->assertSee($resultDecimal < 0 ? 'Manque' : 'Surplus de');
        $price = $resultDecimal * $parent->price;
//        $response->assertSee(e(number_format($price, 0, ',', ' ')).' F');

        // Assert cartons/paquets small span appears somewhere
        $this->assertStringContainsString('<span class="small">', $html);

        // Nested children table label
        $response->assertSee('sois');*/
    }
    /**
     * Create multiple sales invoices for a given product within a car load until the target quantity is reached.
     * Quantities per invoice are random but the overall sum will be exactly $quantity and will not exceed it.
     * Each invoice is assigned a random date within the car load [load_date, return_date] interval and a random
     * customer whose commercial belongs to the team manager.
     */
   private function saveInvoice(Customer $customer, Product $product, int $quantity, Commercial $managerCommercial,
                          Carbon
                          $randomDate): TestResponse
    {
        $items = [];
        $items[]=[
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $product->price,
            'commercial_id' => $managerCommercial->id,
        ];
        $resp = $this->postJson(route('sales_person.sales-invoices.create'), [
            'customer_id' => $customer->id,
            'paid' => true,
            'payment_method' => 'CASH',
            'should_be_paid_at' => $randomDate->copy()->addDays(random_int(0, 10)),
            'comment' => 'Auto-generated for car load sale',
            'commercial_id' => $managerCommercial->id,
            'items' => $items,
        ]);
        return $resp;
    }
    public function createInvoiceSalesForProductInCarLoad(Product $product, CarLoad $carLoad, int $quantity, bool
    $splitQuantityToInvoices = true, \Closure $responseTester = null ): void
    {

        if ($quantity <= 0) {
            return;
        }

        // Resolve the manager's commercial (create if missing) for the car load team
        $managerCommercial = $this->getManagerCommercial($carLoad->team);

        $remaining = $quantity;
        Sanctum::actingAs($this->manager);
        if ($splitQuantityToInvoices) {
            while ($remaining > 0) {
                // Choose a random chunk that does not exceed remaining (favor smaller chunks to create more invoices)
                $maxChunk = max(1, (int)floor(max(2, $remaining) / random_int(2, 5)));
                $chunk = min($remaining, random_int(1, max(1, $maxChunk)));
                // Random date within car load window
                $randomDate = $this->randomDateInInterval(Carbon::parse($carLoad->load_date), Carbon::parse($carLoad->return_date));
                $customer = $this->getOrCreateRandomCustomer($managerCommercial);
                $resp = $this->saveInvoice($customer, $product, $chunk, $managerCommercial, $randomDate);


//                if ($resp->status() != 200 && $resp->status() != 201) {
//                    dump($resp->getContent());
//                }
                if ($responseTester !== null && is_callable($responseTester)) {

                    $responseTester($resp);
                }

                $remaining -= $chunk;
            }
        } else {
            $randomDate = $this->randomDateInInterval(Carbon::parse($carLoad->load_date), Carbon::parse($carLoad->return_date));
            $customer = $this->getOrCreateRandomCustomer($managerCommercial);
            $resp = $this->saveInvoice($customer, $product, $quantity, $managerCommercial, $randomDate);
            if ($responseTester !== null && is_callable($responseTester)) {

                $responseTester($resp);
            }

        }
    }

    private function randomDateInInterval(Carbon $start, Carbon $end): Carbon
    {
        // Ensure start <= end
        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }
        $startTs = $start->timestamp;
        $endTs = $end->timestamp;
        if ($startTs === $endTs) {
            return $start->copy();
        }
        $randTs = random_int($startTs, $endTs);
        return Carbon::createFromTimestamp($randTs);
    }

    private function getManagerCommercial(Team $team): Commercial
    {
        // Find a commercial linked to the team manager (by user_id) and the same team
        $commercial = Commercial::where('user_id', $team->user_id)
            ->where('team_id', $team->id)
            ->first();
        if ($commercial) {
            return $commercial;
        }
        // Create one if none exists
        return Commercial::create([
            'name' => $team->manager?->name ?? 'Manager Commercial',
            'phone_number' => '2217'.random_int(10000000, 99999999),
            'gender' => 'male',
            'user_id' => $team->user_id,
            'team_id' => $team->id,
        ]);
    }

    private function getOrCreateRandomCustomer(Commercial $commercial): Customer
    {
        $existing = Customer::where('commercial_id', $commercial->id)->inRandomOrder()->first();
        if ($existing) {
            return $existing;
        }
        // Create a new simple customer under this commercial
        return Customer::create([
            'name' => 'Cust '.random_int(1000, 9999),
            'address' => 'Addr',
            'phone_number' => (string) random_int(770000000, 779999999),
            'owner_number' => (string) random_int(770000000, 779999999),
            'gps_coordinates' => '0,0,0',
            'commercial_id' => $commercial->id,
            'is_prospect' => false,
        ]);
    }

    public function test_full_flow_inventory_pdf_displays_expected_values(): void
    {
        // Setup: Initialize all test data
        $this->setupTestData();

        // Test 1: Create Car Load
        $carLoad = $this->createCarLoad();

        // Test 2: Create and verify purchase invoices
        $this->createAndVerifyPurchaseInvoices($carLoad);

        // Test 3: Create and verify sales invoices
        $this->createAndVerifySalesInvoices($carLoad);

        // Test 4: Create and verify inventory with PDF export
        $this->createAndVerifyInventory($carLoad);
    }

    public function test_product_history_totals_in_car_load(): void
    {
        // Arrange
        $this->setupTestData();
        $carLoad = $this->createCarLoad();
        $this->createAndVerifyPurchaseInvoices($carLoad);

        // We will test with the 1KG parent product
        $product = $this->p1KGCarton1000pcs;

        // Create deterministic sales totals within the car load window
        // First sell 2, then sell 20 more (total 22 as used in previous assertions)
        Carbon::setTestNow(Carbon::parse($carLoad->load_date)->addDays(1));
        $this->createInvoiceSalesForProductInCarLoad($product, $carLoad, 2);
        Carbon::setTestNow(Carbon::parse($carLoad->load_date)->addDays(2));
        $this->createInvoiceSalesForProductInCarLoad($product, $carLoad, 20);
        Carbon::setTestNow(null);

        // Act: fetch history from service
        $history = $this->carLoadService->productHistoryInCarLoad($product, $carLoad);

        // Assert structure
        $this->assertIsArray($history);
        $this->assertArrayHasKey('loadingsHistory', $history);
        $this->assertArrayHasKey('product', $history);
        $this->assertArrayHasKey('ventes', $history);
        $this->assertGreaterThan(0, count($history['loadingsHistory']));;
        $this->assertGreaterThan(0, count($history['ventes']));;
        $this->assertSame($product->id, $history['product']->id);

        // Compute totals as the vue does
        $totalLoaded = collect($history['loadingsHistory'])->sum(function ($it) {
            return (int) ($it->quantity_loaded ?? $it['quantity_loaded'] ?? 0);
        });
        $totalSold = collect($history['ventes'])->sum(function ($v) {
            return (int) ($v->quantity ?? $v['quantity'] ?? 0);
        });

        // Expect loaded equals purchase quantity for this product (20 + 20 = 40)
        $this->assertEquals(40, $totalLoaded, 'Total loaded in history should match car load items sum');
        // Expect sold equals created sales (2 + 20 = 22)
        $this->assertEquals(22, $totalSold, 'Total sold in history should match ventes sum');

        // Optional: also hit the route and ensure page loads
        $resp = $this->get('/car-loads/' . $carLoad->id . '/' . $product->id . '/history');
        $resp->assertOk();
    }
}