<?php

namespace Tests\Feature\Inventory;

use App\Data\CarLoadInventory\CarLoadInventoryResultItemDTO;
use App\Models\CarLoad;
use App\Models\CarLoadInventory;
use     \App\Models\CarLoadItem;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use App\Models\Commercial;
use App\Models\Vente;
use App\Services\CarLoadService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use function Symfony\Component\Translation\t;

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
    //   private Product $c1KGPaquet20pcs; // child of 1KG Carton 1000pcs
    static int $defaultQuantity1KGCarton1000pcs             = 500;   // parent of 1KGPaquet20pcs
    static int $defaultQuantity2KGCarton400pcs              = 200;   // parent of 2KGPaquet10pcs
    static int $defaultQuantity500gCarton1000pcs            = 220;   // parent of 500g20pcs
    static int $defaultQuantityGobeletCarton1000pcs         = 100;   // parent of GobeletPaquet50pcs
    static int $defaultQuantity2CompartCarton250pcs         = 100;   // parent of 2CompartGM5pcs
    static int $defaultQuantityTransparent1000mlCarton500pcs = 400;  // parent of Transparent1000ml10pcs
    static int $defaultQuantityPotASauce2000pcs             = 300;   // parent of PotASauce100pcs
    //
    static int $defaultQuantity1KGPaquet20pcs         = 5000;
    static int $defaultQuantity2KGPaquet10pcs         = 2000;
    static int $defaultQuantity500g20pcs              = 2200;
    static int $defaultQuantityGobeletPaquet50pcs     = 1000;
    static int $defaultQuantity2CompartGM5pcs         = 1000;
    static int $defaultQuantityTransparent1000ml10pcs = 4000;
    static int $defaultQuantityPotASauce100pcs        = 3000;

    static int $sold1KGCarton1000pcs             = 99;   // parent of 1KGPaquet20pcs
    static int $sold2KGCarton400pcs              = 85;   // parent of 2KGPaquet10pcs
    static int $sold500gCarton1000pcs            = 155;   // parent of 500g20pcs
    static int $soldGobeletCarton1000pcs         = 71;   // parent of GobeletPaquet50pcs
    static int $sold2CompartCarton250pcs         = 54;   // parent of 2CompartGM5pcs
    static int $soldTransparent1000mlCarton500pcs= 225;  // parent of Transparent1000ml10pcs
    static int $soldPotASauce2000pcs             = 100;   // parent of PotASauce100pcs
    //          sold
    static int $sold1KGPaquet20pcs         = 2340;
    static int $sold2KGPaquet10pcs         = 1345;
    static int $sold500g20pcs              = 2000;
    static int $soldGobeletPaquet50pcs     = 500;
    static int $sold2CompartGM5pcs         = 875;
    static int $soldTransparent1000ml10pcs = 2345;
    static int $soldPotASauce100pcs        = 1234;

    // Default quantities for transformations (reasonable portions of parent quantities)
    static int $defaultTransform1KGCarton1000pcs             = 163;   // ~12.6% of 500 default quantity
    static int $defaultTransform2KGCarton400pcs              = 85;   // ~12.5% of 200 default quantity
    static int $defaultTransform500gCarton1000pcs            = 95;   // ~12.7% of 220 default quantity
    static int $defaultTransformGobeletCarton1000pcs         = 115;   // 15% of 100 default quantity
    static int $defaultTransform2CompartCarton250pcs         = 132;   // 12% of 100 default quantity
    static int $defaultTransformTransparent1000mlCarton500pcs = 148;   // 12% of 400 default quantity
    static int $defaultTransformPotASauce2000pcs             = 86;   // 12% of 300 default quantity
   // returned quantities for inventory
    static int $defaultReturned1KGCarton1000pcs              = 86;   // 12% of 300 default quantity
// returned child quantities for inventory
    static int $defaultReturned1KG20pcs              = 186;   // 12% of 300 default quantity

    private Collection $parents;
    private Collection $children;
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
    protected function setUp(): void
    {
        parent::setUp();

        // Disable throttling middleware in tests
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
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
                'quantity'   => self::$defaultQuantity1KGCarton1000pcs,
                'unit_price' => $this->p1KGCarton1000pcs->cost_price ?? 0,
            ],
            [
                'product_id' => $this->p2KGCarton400pcs->id,
                'quantity'   => self::$defaultQuantity2KGCarton400pcs,
                'unit_price' => $this->p2KGCarton400pcs->cost_price ?? 0,
            ],
            [
                'product_id' => $this->p500gCarton1000pcs->id,
                'quantity'   => self::$defaultQuantity500gCarton1000pcs,
                'unit_price' => $this->p500gCarton1000pcs->cost_price ?? 0,
            ],
            [
                'product_id' => $this->pGobeletCarton1000pcs->id,
                'quantity'   => self::$defaultQuantityGobeletCarton1000pcs,
                'unit_price' => $this->pGobeletCarton1000pcs->cost_price ?? 0,
            ],
            [
                'product_id' => $this->p2CompartCarton250pcs->id,
                'quantity'   => self::$defaultQuantity2CompartCarton250pcs,
                'unit_price' => $this->p2CompartCarton250pcs->cost_price ?? 0,
            ],
            [
                'product_id' => $this->pTransparent1000mlCarton500pcs->id,
                'quantity'   => self::$defaultQuantityTransparent1000mlCarton500pcs,
                'unit_price' => $this->pTransparent1000mlCarton500pcs->cost_price ?? 0,
            ],
            [
                'product_id' => $this->pPotASauce2000pcs->id,
                'quantity'   => self::$defaultQuantityPotASauce2000pcs,
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

        $this->assertEquals(self::$defaultQuantity1KGCarton1000pcs, $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $this->p1KGCarton1000pcs));

        $this->assertEquals(self::$defaultQuantity500gCarton1000pcs, $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $this->p500gCarton1000pcs));

        $this->assertEquals(self::$defaultQuantity2KGCarton400pcs, $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $this->p2KGCarton400pcs));

        $this->assertEquals(self::$defaultQuantityGobeletCarton1000pcs, $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $this->pGobeletCarton1000pcs));

        $this->assertEquals(self::$defaultQuantity2CompartCarton250pcs, $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $this->p2CompartCarton250pcs));

        $this->assertEquals(self::$defaultQuantityTransparent1000mlCarton500pcs, $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $this->pTransparent1000mlCarton500pcs));

        $this->assertEquals(self::$defaultQuantityPotASauce2000pcs, $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $this->pPotASauce2000pcs)
        );


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

        $this->assertEquals(self::$defaultQuantity1KGCarton1000pcs * 2,
            $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad,
            $this->p1KGCarton1000pcs));
        $this->assertEquals(self::$defaultQuantity2KGCarton400pcs * 2, $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad,
            $this->p2KGCarton400pcs));
        $this->assertEquals(self::$defaultQuantityGobeletCarton1000pcs * 2, $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad,
            $this->pGobeletCarton1000pcs));

        // Test transformToVariants via API (CarLoadService::transformToVariants)
        // Arrange current stocks in the car load for parent and a variant (child)
        $parent = $this->p1KGCarton1000pcs;
        $child = $this->c1KGPaquet20pcs;
        $beforeParentAvail = $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $parent);
        $beforeChildAvail = $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $child);

        // We'll transform 3 parent cartons into child packets; provide two variant lines with some unused quantity
        $quantityOfBaseProductToTransform = self::$defaultTransform1KGCarton1000pcs;
        $childQuantity = ($parent->base_quantity / $child->base_quantity) * $quantityOfBaseProductToTransform;

        // Act: call the API as an authenticated user (sanctum)
        Sanctum::actingAs($this->manager);
        $responseTester = function (TestResponse $resp) {
            $resp->assertOk();
            $resp->assertJson(['message' => 'Transformation effectuée avec succès']);
        };

//        $this->transformProductToVariantsInCarLoad($carLoad, $this->p1KGCarton1000pcs, $child,
//            parentQuantityToTransform: $quantityOfBaseProductToTransform, splitChunks: true,
//            responseTester: $responseTester);

        // Assert: parent decreased and child increased with correct amounts in the car load available stock

        $expectedActualChildIncrease = ($parent->base_quantity / $child->base_quantity) * self::$defaultTransform1KGCarton1000pcs; // 17

        $this->transformProductToVariantsInCarLoad($carLoad, $this->p1KGCarton1000pcs, $this->c1KGPaquet20pcs,
            parentQuantityToTransform: self::$defaultTransform1KGCarton1000pcs, splitChunks: true,
            responseTester: $responseTester);
        $availableParentQuantityAfterTransform = $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $parent);// should
        // be 17
        $availableChildQuantityAfterTransform = $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $child); // should be
        // 150
        $this->assertEquals($beforeParentAvail - self::$defaultTransform1KGCarton1000pcs, $availableParentQuantityAfterTransform, 'Parent available stock should decrease by transformed quantity');
        $this->assertEquals($beforeChildAvail + $expectedActualChildIncrease, $availableChildQuantityAfterTransform, 'Child available stock should increase by sum(actual quantities)');
        $this->assertEquals($childQuantity, $availableChildQuantityAfterTransform, 'Child available stock should be now 150');
        // for product 500g-20 pcs

        // Transform all remaining parent products to their variants
        $this->transformProductToVariantsInCarLoad($carLoad, $this->p500gCarton1000pcs, $this->c500g20pcs,
            parentQuantityToTransform: self::$defaultTransform500gCarton1000pcs, splitChunks: true,
            responseTester: $responseTester);
        $this->transformProductToVariantsInCarLoad($carLoad, $this->p2KGCarton400pcs, $this->c2KGPaquet10pcs,
            parentQuantityToTransform: self::$defaultTransform2KGCarton400pcs, splitChunks: true,
            responseTester: $responseTester);
        $this->transformProductToVariantsInCarLoad($carLoad, $this->pGobeletCarton1000pcs, $this->cGobeletPaquet50pcs,
            parentQuantityToTransform: self::$defaultTransformGobeletCarton1000pcs, splitChunks: true,
            responseTester: $responseTester);
        $this->transformProductToVariantsInCarLoad($carLoad, $this->p2CompartCarton250pcs, $this->c2CompartGM5pcs,
            parentQuantityToTransform: self::$defaultTransform2CompartCarton250pcs, splitChunks: true,
            responseTester: $responseTester);
        $this->transformProductToVariantsInCarLoad($carLoad, $this->pTransparent1000mlCarton500pcs, $this->cTransparent1000ml10pcs,
            parentQuantityToTransform: self::$defaultTransformTransparent1000mlCarton500pcs, splitChunks: true,
            responseTester: $responseTester);
        $this->transformProductToVariantsInCarLoad($carLoad, $this->pPotASauce2000pcs, $this->cPotASauce100pcs,
            parentQuantityToTransform: self::$defaultTransformPotASauce2000pcs, splitChunks: true,
            responseTester: $responseTester);

    }

    private function createAndVerifySalesInvoices(CarLoad $carLoad): void
    {
        // keep dates within car load window (after load_date and before return_date)
        Carbon::setTestNow(Carbon::now()->addDays(30));
        Sanctum::actingAs($this->manager);
        $salesTester = function (TestResponse $resp) {
            if ($resp->status() !== 201 && $resp->status() !== 200) {
                dump($resp->getContent());
            }
            $resp->assertCreated();
        };
        // Sales Of parents
//        $this->assertEquals(29, $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $this->p1KGCarton1000pcs));
        $this->createInvoiceSalesForProductInCarLoad($this->p1KGCarton1000pcs, $carLoad, self::$sold1KGCarton1000pcs,
            chunk: 30, responseTester:  clone $salesTester);
        $this->createInvoiceSalesForProductInCarLoad($this->p500gCarton1000pcs, $carLoad, self::$sold500gCarton1000pcs, true,30,clone $salesTester);
        $this->createInvoiceSalesForProductInCarLoad($this->p2KGCarton400pcs, $carLoad, self::$sold2KGCarton400pcs, true, 30,clone $salesTester);
        $this->createInvoiceSalesForProductInCarLoad($this->pGobeletCarton1000pcs, $carLoad, self::$soldGobeletCarton1000pcs, true,30, $salesTester);
        $this->createInvoiceSalesForProductInCarLoad($this->p2CompartCarton250pcs, $carLoad, self::$sold2CompartCarton250pcs, true,30, $salesTester);
        $this->createInvoiceSalesForProductInCarLoad($this->pPotASauce2000pcs, $carLoad, self::$soldPotASauce2000pcs, true,30, $salesTester);
        $this->createInvoiceSalesForProductInCarLoad($this->pTransparent1000mlCarton500pcs, $carLoad, self::$soldTransparent1000mlCarton500pcs, true,30, $salesTester);
//
//        // sales of children
        $this->createInvoiceSalesForProductInCarLoad($this->c1KGPaquet20pcs, $carLoad, self::$sold1KGPaquet20pcs, 
            true,chunk: 30, responseTester: $salesTester);
        $this->createInvoiceSalesForProductInCarLoad($this->c500g20pcs, carLoad:  $carLoad, quantity: self::$sold500g20pcs,  chunk: 30, responseTester: $salesTester);
        $this->createInvoiceSalesForProductInCarLoad($this->c2KGPaquet10pcs, carLoad:  $carLoad, quantity: self::$sold2KGPaquet10pcs, chunk: 30, responseTester: $salesTester);
        $this->createInvoiceSalesForProductInCarLoad($this->cTransparent1000ml10pcs, carLoad:  $carLoad, quantity: self::$soldTransparent1000ml10pcs, chunk: 30, responseTester: $salesTester);
        $this->createInvoiceSalesForProductInCarLoad($this->cGobeletPaquet50pcs, carLoad:  $carLoad, quantity:  self::$soldGobeletPaquet50pcs, chunk: 30, responseTester: $salesTester);
        $this->createInvoiceSalesForProductInCarLoad($this->c2CompartGM5pcs, carLoad:  $carLoad, quantity: self::$sold2CompartGM5pcs, chunk: 30, responseTester: $salesTester);
        $this->createInvoiceSalesForProductInCarLoad($this->cPotASauce100pcs, carLoad:  $carLoad, quantity: self::$soldPotASauce100pcs, chunk: 30, responseTester: $salesTester);

//
//        $this->assertEquals(19, $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $this->p500gCarton1000pcs));

//        $this->createInvoiceSalesForProductInCarLoad($this->p1KGCarton1000pcs, $carLoad, 20);
//        $this->assertEquals(9, $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad,
//            $this->p1KGCarton1000pcs));
//
//        $this->createInvoiceSalesForProductInCarLoad($this->p1KGCarton1000pcs, $carLoad, quantity: 230,
//            splitQuantityToInvoices: false,
//        responseTester:  function (TestResponse $resp) { $resp->assertUnprocessable();});
//        $this->createInvoiceSalesForProductInCarLoad($this->c1KGPaquet20pcs, $carLoad, quantity: 150,
//            splitQuantityToInvoices: false, responseTester: function (TestResponse $resp) {$resp->assertCreated();});


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

            $inventoredItems =[
                [
                'product_id' => $this->p1KGCarton1000pcs->id,
                'total_returned' => self::$defaultReturned1KGCarton1000pcs,
                ],
                [
                'product_id' => $this->c1KGPaquet20pcs->id,
                'total_returned' => self::$defaultReturned1KG20pcs,
                ],
                [
                'product_id' => $this->p500gCarton1000pcs->id,
                'total_returned' => 8,
                ], [
                'product_id' => $this->c500g20pcs->id,
                'total_returned' => 100,
                ]
            ];

        $resp = $this->post(route('car-loads.inventories.items.store', [$carLoad, $inventory]), [
            'items' => $inventoredItems,
        ]);
        $resp->assertStatus(302);
        // Test the results of calculations
        $inventory->refresh();
        $this->assertCount(count($inventoredItems), $inventory->items);

        $result = $this->carLoadService->getCalculatedQuantitiesOfProductsInInventory($carLoad, $inventory);
        $this->assertIsArray($result, 'Method getCalculatedQuantitiesOfProductsInInventory should return an array');
        $this->assertArrayHasKey('items', $result, "Result must contain 'items' key");
        $items = $result['items'];
        $this->assertCount(2, $items, 'Expected exactly 2 inventoried parent product rows');;
        $this->assertInstanceOf(Collection::class, $items, "'items' must be a Collection");
        /** @var CarLoadInventoryResultItemDTO $p1KGCarton1000pcsInventored */
        $p1KGCarton1000pcsInventored = $items->firstWhere('parent.name', $this->p1KGCarton1000pcs->name);
        $this->assertNotNull($p1KGCarton1000pcsInventored, 'Expected '.$this->p1KGCarton1000pcs->name.' to be present in result of inventory');
        $this->assertInstanceOf(CarLoadInventoryResultItemDTO::class, $p1KGCarton1000pcsInventored);
        $this->assertObjectHasProperty('totalReturned', $p1KGCarton1000pcsInventored, "'total_returned' must be present in result of inventory");
        $this->assertObjectHasProperty('totalSold', $p1KGCarton1000pcsInventored, "'total_sold' must be present in result of inventory");
        $this->assertObjectHasProperty('totalLoaded', $p1KGCarton1000pcsInventored, "'total_loaded' must be present in result of inventory");
        $this->assertNotNull($p1KGCarton1000pcsInventored->totalReturnedConverted);
        $this->assertNotNull($p1KGCarton1000pcsInventored->totalSoldConverted);
        $this->assertNotNull($p1KGCarton1000pcsInventored->totalLoadedConverted);
        $this->assertIsNumeric($p1KGCarton1000pcsInventored->totalReturnedConverted->parentQuantity);
        $this->assertIsNumeric($p1KGCarton1000pcsInventored->totalReturnedConverted->childQuantity);
        $this->assertIsString($p1KGCarton1000pcsInventored->totalReturnedConverted->childName);
        $this->assertObjectHasProperty('children', $p1KGCarton1000pcsInventored, "'Children key' must be present in result of inventory");
        $this->assertInstanceOf(Collection::class, $p1KGCarton1000pcsInventored->children);
        $this->assertCount(1, $p1KGCarton1000pcsInventored->children);
        $this->assertEquals(self::$defaultQuantity1KGCarton1000pcs * 2, $p1KGCarton1000pcsInventored->totalLoaded);
        // get totals
        $p1KGCarton1000pcsSubmitted = collect($inventoredItems)->firstWhere('product_id', $this->p1KGCarton1000pcs->id);
        $c1K20pcsSubmitted = collect($inventoredItems)->firstWhere('product_id', $this->c1KGPaquet20pcs->id);
        $totalParentSubmitted = $p1KGCarton1000pcsSubmitted['total_returned'] +
            $this->c1KGPaquet20pcs->convertQuantityToParentQuantity($c1K20pcsSubmitted['total_returned'])['decimal_parent_quantity'];
         $totalParentSold = self::$sold1KGCarton1000pcs + $this->c1KGPaquet20pcs->convertQuantityToParentQuantity(self::$sold1KGPaquet20pcs)
             ['decimal_parent_quantity'];
        // testing the totals returned by the inventory
         $this->assertEquals(self::$defaultQuantity1KGCarton1000pcs * 2, $p1KGCarton1000pcsInventored->totalLoaded);
         $this->assertEquals($totalParentSubmitted, $p1KGCarton1000pcsInventored->totalReturned);
        $this->assertEquals($totalParentSold, $p1KGCarton1000pcsInventored->totalSold);
//        $resultDecimal = $calculatedTotalSold + $calculatedTotalReturnedParent - $calculatedTotalLoaded;
          $expectedTotalSold = self::$sold1KGCarton1000pcs + $this->c1KGPaquet20pcs->convertQuantityToParentQuantity(self::$sold1KGPaquet20pcs)['decimal_parent_quantity'];
          $this->assertEquals($expectedTotalSold, $p1KGCarton1000pcsInventored->totalSold);
          $expectedTotalLoaded = self::$defaultQuantity1KGCarton1000pcs - self::$defaultTransform1KGCarton1000pcs +
              $this->c1KGPaquet20pcs->convertQuantityToParentQuantity(self::$defaultTransform1KGCarton1000pcs *
                  ($this->p1KGCarton1000pcs->base_quantity / $this->c1KGPaquet20pcs->base_quantity ) )['decimal_parent_quantity'];
          $this->assertEquals($expectedTotalLoaded * 2, $p1KGCarton1000pcsInventored->totalLoaded);
          $expectedTotalReturnedParent = self::$defaultReturned1KGCarton1000pcs + $this->c1KGPaquet20pcs->convertQuantityToParentQuantity(self::$defaultReturned1KG20pcs)['decimal_parent_quantity'];
          $this->assertEquals($expectedTotalReturnedParent, $p1KGCarton1000pcsInventored->totalReturned);
            $expectedResultOfComputation = $expectedTotalSold + $expectedTotalReturnedParent - $expectedTotalLoaded * 2;
            $this->assertEquals($expectedResultOfComputation, $p1KGCarton1000pcsInventored->resultOfComputation);
            $expectedPriceOfComputation = $expectedResultOfComputation * $this->p1KGCarton1000pcs->price;
            $this->assertEquals($expectedPriceOfComputation, $p1KGCarton1000pcsInventored->priceOfResultComputation);
            $expectedPaquetsOfC1KG20pcs = $this->p1KGCarton1000pcs->getFormattedDisplayOfCartonAndParquets
            ($this->c1KGPaquet20pcs->convertQuantityToParentQuantity(self::$sold1KGPaquet20pcs)['decimal_parent_quantity'])['paquets'];
            $this->assertEquals($expectedPaquetsOfC1KG20pcs,
                $p1KGCarton1000pcsInventored->totalSoldConverted->childQuantity);
//        $this->assertEquals($this->p1KGCarton1000pcs->price * $p1KGCarton1000pcsInventored->resultOfComputation, $price);


        $resp = $this->post(route('car-loads.inventories.items.store', [$carLoad, $inventory]), [
            'items' => [  ['product_id' => $this->c1KGPaquet20pcs->id,
                'total_returned' => 100]],
        ]);
        $resp->assertSessionHasNoErrors();
        $resp->assertStatus(302);
        $result2 = $this->carLoadService->getCalculatedQuantitiesOfProductsInInventory($carLoad, $inventory);
        $itemsCalculated = $result2['items'];

         $this->assertInstanceOf(Collection::class, $itemsCalculated, "'items' must be a Collection");
        $this->assertCount(count($result2['items']), $itemsCalculated, 'Expected exactly 1 inventoried parent product rows');

        // 8) Generate PDF HTML via route and assert key pieces
        $response = $this->get(route('car-loads.inventories.export-pdf', [$carLoad, $inventory]));
        $response->assertOk();
        $html = $response->getContent();

        // Header
        $response->assertSee('Inventaire - '.$carLoad->name);
        $response->assertSee('E2E Inventory');
        $response->assertSee($carLoad->team->manager->name);

        $response->assertSee($this->p1KGCarton1000pcs->name);


        // Assert result wording appears and price formatting uses thousands sep
//        $response->assertSee($resultDecimal < 0 ? 'Manque' : 'Surplus de');
//        $price = $resultDecimal * $parent->price;
//        $response->assertSee(e(number_format($price, 0, ',', ' ')).' F');

        // Assert cartons/paquets small span appears somewhere
        $this->assertStringContainsString('<span class="small">', $html);

        // Nested children table label
        $response->assertSee('sois');
    }
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
                                                                  $splitQuantityToInvoices = true, int $chunk = null, \Closure $responseTester = null ): void
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
                if ($chunk !== null) {
                    $currentChunk = $chunk;
                } else {
                    // Choose a random chunk that does not exceed remaining (favor smaller chunks to create more invoices)
                    $maxChunk = max(1, (int) floor(max(2, $remaining) / random_int(2, 5)));
                    $currentChunk = random_int(1, max(1, $maxChunk));
                }

                $currentChunk = min($remaining, $currentChunk);

                // Random date within car load window
                $randomDate = $this->randomDateInInterval(Carbon::parse($carLoad->load_date), Carbon::parse($carLoad->return_date));
                $customer = $this->getOrCreateRandomCustomer($managerCommercial);
                $resp = $this->saveInvoice($customer, $product, $currentChunk, $managerCommercial, $randomDate);


//                if ($resp->status() != 200 && $resp->status() != 201) {
//                    dump($resp->getContent());
//                }
                if ($responseTester !== null && is_callable($responseTester)) {

                    $responseTester($resp);
                }

                $remaining -= $currentChunk;
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
        $this->assertEquals(self::$defaultQuantity1KGCarton1000pcs * 2, $totalLoaded, 'Total loaded in history should match car load items sum');
        // Expect sold equals created sales (2 + 20 = 22)
        $this->assertEquals(22, $totalSold, 'Total sold in history should match ventes sum');

        // Optional: also hit the route and ensure page loads
        $resp = $this->get('/car-loads/' . $carLoad->id . '/' . $product->id . '/history');
        $resp->assertOk();
    }
    public function test_transform_to_variants_throws_when_no_current_car_load(): void
    {
        // Arrange: seed data and auth, but do NOT create a car load
        $this->setupTestData();
        Sanctum::actingAs($this->manager);

        $parent = $this->p1KGCarton1000pcs; // any base product
        $child = $this->c1KGPaquet20pcs;   // a valid child of the parent from seeder

        $payload = [
            'quantityOfBaseProductToTransform' => 1,
            'items' => [
                [
                    'product_id' => $child->id,
                    'quantity' => 1,
                    'unused_quantity' => 0,
                ],
            ],
        ];

        // Act
        $resp = $this->postJson('/api/salesperson/car-loads/' . $parent->id . '/transform', $payload);

        // Assert
        $resp->assertStatus(422);
        $this->assertStringContainsString(
            'Aucun chargement actif trouvé pour votre équipe',
            $resp->json('message') ?? ''
        );
    }

    public function test_transform_to_variants_throws_when_product_not_in_car_load(): void
    {
        // Arrange: create an active car load but do NOT load the product
        $this->setupTestData();
        $carLoad = $this->createCarLoad();
        Sanctum::actingAs($this->manager);

        $parent = $this->p1KGCarton1000pcs; // not put in this car load
        $child = $this->c1KGPaquet20pcs;

        $payload = [
            'quantityOfBaseProductToTransform' => 1,
            'items' => [
                [
                    'product_id' => $child->id,
                    'quantity' => 1,
                    'unused_quantity' => 0,
                ],
            ],
        ];

        // Act
        $resp = $this->postJson('/api/salesperson/car-loads/' . $parent->id . '/transform', $payload);

        // Assert
        $resp->assertStatus(422);
        $this->assertStringContainsString(
            "Ce produit n'est pas dans votre chargement",
            $resp->json('message') ?? ''
        );
    }

    public function test_transform_to_variants_throws_when_insufficient_stock(): void
    {
        // Arrange: create active car load and load some stock, then request too much
        $this->setupTestData();
        $carLoad = $this->createCarLoad();
        $this->createAndVerifyPurchaseInvoices($carLoad); // loads parent products into current car load
        Sanctum::actingAs($this->manager);

        $parent = $this->p1KGCarton1000pcs;
        $child = $this->c1KGPaquet20pcs;

        $available = $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $parent);
        $this->assertGreaterThan(0, $available);

        // Request more than available to trigger insufficient stock
        $payload = [
            'quantityOfBaseProductToTransform' => $available + 1,
            'items' => [
                [
                    'product_id' => $child->id,
                    'quantity' => 1,
                    'unused_quantity' => 0,
                ],
            ],
        ];

        // Act
        $resp = $this->postJson('/api/salesperson/car-loads/' . $parent->id . '/transform', $payload);

        // Assert
        $resp->assertStatus(422);
        $this->assertStringContainsString(
            'Stock insuffisant dans le chargement. Stock disponible',
            $resp->json('message') ?? ''
        );
    }
    protected function transformProductToVariantsInCarLoad(CarLoad $carLoad, Product $parent, Product $child,
                                                                   int $parentQuantityToTransform,
                                                                   bool $splitChunks = false,
                                                                   int $chunk = null, \Closure $responseTester = null): void
    {
        while ($parentQuantityToTransform  > 0) {
        $expectedActualChildIncrease = ($parent->base_quantity / $child->base_quantity) ;

            $items = [
                [
                    'product_id' => $child->id,
                    'quantity' => $expectedActualChildIncrease,
                    'unused_quantity' => 0,
                ],
            ];

            $payload = [
                'quantityOfBaseProductToTransform' => 1,
                'items' => $items,
            ];// Act
            Sanctum::actingAs($this->manager);
            $resp = $this->postJson(route('car-loads.transform_product_to_variants', ['product' => $parent->id]), $payload);
            $parentQuantityToTransform--;


        if ($responseTester){
            $responseTester($resp);
        }
        }
    }
    public function test_transform_to_variants_creates_multiple_entries(){
       $this->setupTestData();
       $carLoad = $this->createCarLoad();
       $parentQuantityLoaded = 1000;
       $carLoad->items()->create([
           'product_id' => $this->p1KGCarton1000pcs->id,
           "quantity_loaded" => $parentQuantityLoaded,
           "quantity_left" => $parentQuantityLoaded,
           'loaded_at' => Carbon::now(),

       ]);

       $parentQuantityToTransform = 100;
       $this->transformProductToVariantsInCarLoad($carLoad, parent:  $this->p1KGCarton1000pcs,
           child: $this->c1KGPaquet20pcs, parentQuantityToTransform: $parentQuantityToTransform, splitChunks: true, responseTester: function
           (TestResponse $resp){
           $resp->assertOk();
       });
       $this->assertCount($parentQuantityToTransform, $carLoad->items()->whereProductId($this->c1KGPaquet20pcs->id)->get());
       $this->assertEquals($parentQuantityLoaded - $parentQuantityToTransform, $carLoad->items()->whereProductId($this->p1KGCarton1000pcs->id)
           ->first()
           ->quantity_left);
       $this->assertEquals($parentQuantityLoaded - $parentQuantityToTransform, $this->carLoadService->getAvailableStockOfProductInCarLoad($carLoad, $this->p1KGCarton1000pcs));
       $this->assertEquals(($this->p1KGCarton1000pcs->base_quantity / $this->c1KGPaquet20pcs->base_quantity) * $parentQuantityToTransform,
           $this->carLoadService->getAvailableStockOfProductInCarLoad
       ($carLoad,
           $this->c1KGPaquet20pcs));
    }


}