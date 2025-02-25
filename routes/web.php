<?php

use App\Http\Controllers\CaisseController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\CommercialController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\VenteController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ZoneController;
use App\Http\Controllers\LigneController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\DeliveryBatchController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SalesInvoiceController;
use App\Http\Controllers\VisitBatchController;
use App\Http\Controllers\CustomerVisitController;
use App\Http\Controllers\InvestmentController;
use App\Http\Controllers\DepenseController;
use App\Http\Controllers\CustomerCategoryController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\PurchaseInvoiceController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\SectorController;
use App\Http\Controllers\CarLoadController;
use App\Http\Controllers\TeamController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/


Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');
Route::get('/', [DashboardController::class, 'index']);

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('commerciaux/{commercial}/activity', [CommercialController::class, 'activity'])->name('commerciaux.activity');
    Route::resource('commerciaux', CommercialController::class);
    Route::get('/commercials', [CommercialController::class, 'getCommercials'])->name('commercials.list');
    Route::resource('customers', CustomerController::class);
    Route::resource('produits', ProductController::class);
    Route::resource('ventes', VenteController::class);
    Route::resource('zones', ZoneController::class);
    Route::get('zones/{zone}/lignes', [ZoneController::class, 'lignes'])->name('zones.lignes');
    Route::get('lignes/unassigned-customers', [LigneController::class, 'getUnassignedCustomers'])->name('lignes.unassigned-customers');
    Route::resource('lignes', LigneController::class)->only(['index', 'store', 'update', 'destroy', 'show']);
    Route::get('lignes/{ligne}/customers', [LigneController::class, 'customers'])->name('lignes.customers');
    Route::post('lignes/{ligne}/assign-customer', [LigneController::class, 'assignCustomer'])->name('lignes.assign-customer');
    Route::post('/lignes/{ligne}/assign-customers', [LigneController::class, 'assignCustomers'])->name('lignes.assign-customers');
    Route::resource('orders', OrderController::class);
    Route::post('/orders/{order}/items', [OrderController::class, 'addItem'])->name('orders.items.store');
    Route::delete('/orders/{order}/items/{item}', [OrderController::class, 'removeItem'])->name('orders.items.destroy');
    Route::post('/orders/{order}/create-invoice', [OrderController::class, 'createInvoice'])
    ->name('orders.create-invoice');
    
    Route::get('/delivery-batches', [DeliveryBatchController::class, 'index'])->name('delivery-batches.index');
    Route::post('/delivery-batches', [DeliveryBatchController::class, 'store'])->name('delivery-batches.store');
    Route::put('/delivery-batches/{deliveryBatch}', [DeliveryBatchController::class, 'update'])->name('delivery-batches.update');
    Route::delete('/delivery-batches/{deliveryBatch}', [DeliveryBatchController::class, 'destroy'])->name('delivery-batches.destroy');
    Route::post('/delivery-batches/{deliveryBatch}/orders', [DeliveryBatchController::class, 'addOrders'])->name('delivery-batches.add-orders');
    Route::delete('/delivery-batches/{deliveryBatch}/orders/{order}', [DeliveryBatchController::class, 'removeOrder'])->name('delivery-batches.remove-order');
    Route::post('/delivery-batches/{deliveryBatch}/assign-livreur', [DeliveryBatchController::class, 'assignLivreur'])->name('delivery-batches.assign-livreur');
    Route::get('/delivery-batches/available-orders', [DeliveryBatchController::class, 'getAvailableOrders'])->name('delivery-batches.available-orders');
    Route::get('/delivery-batches/{deliveryBatch}/export-pdf', [DeliveryBatchController::class, 'exportPdf'])
    ->name('delivery-batches.export-pdf');
    
    Route::get('/clients/{client}/history', [CustomerController::class, 'history'])->name('clients.history');
    Route::post('/orders/{order}/payments', [PaymentController::class, 'store'])->name('orders.payments.store');
    Route::get('/orders/{order}/payments', [PaymentController::class, 'index'])->name('orders.payments.index');
    
    Route::resource('sales-invoices', SalesInvoiceController::class);
    Route::get('/sales-invoices/{salesInvoice}/pdf', [SalesInvoiceController::class, 'exportPdf'])->name('sales-invoices.pdf');
    Route::get('/sales-invoices-unpaid/pdf', [SalesInvoiceController::class, 'exportUnpaidPdf'])->name('sales-invoices.unpaid-pdf');
    Route::post('/sales-invoices/{salesInvoice}/items', [SalesInvoiceController::class, 'addItem'])->name('sales-invoices.items.store');
    Route::put('/sales-invoices/{salesInvoice}/items/{item}', [SalesInvoiceController::class, 'updateItem'])->name('sales-invoices.items.update');
    Route::delete('/sales-invoices/{salesInvoice}/items/{item}', [SalesInvoiceController::class, 'removeItem'])->name('sales-invoices.items.destroy');
    Route::post('/sales-invoices/{salesInvoice}/payments', [SalesInvoiceController::class, 'addPayment'])->name('sales-invoices.payments.store');
    Route::put('/sales-invoices/{salesInvoice}/payments/{payment}', [SalesInvoiceController::class, 'updatePayment'])->name('sales-invoices.payments.update');
    Route::delete('/sales-invoices/{salesInvoice}/payments/{payment}', [SalesInvoiceController::class, 'removePayment'])->name('sales-invoices.payments.destroy');
    
    // Visit Management Routes
    Route::prefix('visits')->name('visits.')->group(function () {
        // Visit Batches
        Route::get('/', [VisitBatchController::class, 'index'])->name('index');
        Route::get('/create', [VisitBatchController::class, 'create'])->name('create');
        Route::post('/', [VisitBatchController::class, 'store'])->name('store');
        Route::get('/{visitBatch}', [VisitBatchController::class, 'show'])->name('show');
        Route::get('/{visitBatch}/edit', [VisitBatchController::class, 'edit'])->name('edit');
        Route::put('/{visitBatch}', [VisitBatchController::class, 'update'])->name('update');
        Route::delete('/{visitBatch}', [VisitBatchController::class, 'destroy'])->name('destroy');
        Route::post('/{visitBatch}/add-customers', [VisitBatchController::class, 'addCustomers'])->name('add-customers');
        
        // Customer Visits
        Route::post('/customer-visits', [CustomerVisitController::class, 'store'])->name('customer-visits.store');
        Route::get('/customer-visits/{customerVisit}', [CustomerVisitController::class, 'show'])->name('customer-visits.show');
        Route::post('/customer-visits/{customerVisit}/complete', [CustomerVisitController::class, 'complete'])->name('customer-visits.complete');
        Route::post('/customer-visits/{customerVisit}/cancel', [CustomerVisitController::class, 'cancel'])->name('customer-visits.cancel');
        Route::delete('/customer-visits/{customerVisit}', [CustomerVisitController::class, 'destroy'])->name('customer-visits.destroy');
    });
    
    // Investment Management Routes
    Route::prefix('investments')->name('investments.')->group(function () {
        Route::get('/', [InvestmentController::class, 'index'])->name('index');
        Route::post('/', [InvestmentController::class, 'store'])->name('store');
        Route::put('/{investment}', [InvestmentController::class, 'update'])->name('update');
        Route::delete('/{investment}', [InvestmentController::class, 'destroy'])->name('destroy');
    });
    
    // Expense Management Routes
    Route::prefix('depenses')->name('depenses.')->group(function () {
        Route::get('/', [DepenseController::class, 'index'])->name('index');
        Route::post('/', [DepenseController::class, 'store'])->name('store');
        Route::delete('/{depense}', [DepenseController::class, 'destroy'])->name('destroy');
        Route::put('/{depense}', [DepenseController::class, 'update'])->name('update');
        
        // Type Depense Routes
        Route::post('/types', [DepenseController::class, 'storeType'])->name('types.store');
        Route::put('/types/{typeDepense}', [DepenseController::class, 'updateType'])->name('types.update');
        Route::delete('/types/{typeDepense}', [DepenseController::class, 'destroyType'])->name('types.destroy');
    });
    
    // Customer Management Routes
    Route::prefix('clients')->name('clients.')->group(function () {
        Route::get('/', [CustomerController::class, 'index'])->name('index');
        Route::get('/map', [CustomerController::class, 'map'])->name('map');
        Route::post('/', [CustomerController::class, 'store'])->name('store');
        Route::get('/{client}', [CustomerController::class, 'show'])->name('show');
        Route::put('/{client}', [CustomerController::class, 'update'])->name('update');
        Route::delete('/{client}', [CustomerController::class, 'destroy'])->name('destroy');
    });
    Route::resource('clients', CustomerController::class);
    
    Route::resource('customer-categories', CustomerCategoryController::class);
    Route::post('customer-categories/{customerCategory}/add-customers', [CustomerCategoryController::class, 'addCustomers'])->name('customer-categories.add-customers');
    
    // Supplier Management Routes
    Route::resource('suppliers', SupplierController::class);
    
    // Purchase Invoice Management Routes
    Route::resource('purchase-invoices', PurchaseInvoiceController::class);
    Route::post('purchase-invoices/{purchaseInvoice}/put-in-stock', [PurchaseInvoiceController::class, 'putItemsToStock'])
    ->name('purchase-invoices.put-in-stock');
    
    Route::put('products/{product}/update-stock-entries', [ProductController::class, 'updateStockEntries'])->name('products.update-stock-entries');
    Route::post('products/{product}/transform', [ProductController::class, 'transformToVariants'])->name('products.transform');
    
    Route::get('caisses/{caisse}/transactions', [CaisseController::class, 'transactions'])->name('caisses.transactions');
    Route::post('caisses/{caisse}/transactions', [CaisseController::class, 'storeTransaction'])->name('caisses.transactions.store');
    Route::delete('caisses/{caisse}/transactions/{transaction}', [CaisseController::class, 'destroyTransaction'])->name('caisses.transactions.destroy');
    Route::post('caisses/transfer', [CaisseController::class, 'transfer'])->name('caisses.transfer');
    Route::resource('caisses', CaisseController::class)->parameters(['caisses' => 'caisse']);
    
    // Sector routes
    Route::post('/sectors', [SectorController::class, 'store'])->name('sectors.store');
    Route::put('/sectors/{sector}', [SectorController::class, 'update'])->name('sectors.update');
    Route::delete('/sectors/{sector}', [SectorController::class, 'destroy'])->name('sectors.destroy');
    Route::post('/sectors/{sector}/customers', [SectorController::class, 'addCustomers'])->name('sectors.add-customers');
    Route::delete('/sectors/{sector}/customers/{customer}', [SectorController::class, 'removeCustomer'])->name('sectors.remove-customer');
    Route::get('/sectors/{sector}/visit-batches', [SectorController::class, 'getVisitBatches'])->name('sectors.visit-batches');
    Route::post('/sectors/{sector}/visit-batches', [SectorController::class, 'createVisitBatch'])->name('sectors.create-visit-batch');
    Route::get('/sectors/{sector}/map-customers', [SectorController::class, 'getCustomersForMap'])->name('sectors.map-customers');
    Route::get('/sectors/{sector}/map', [SectorController::class, 'map'])->name('sectors.map');

    // Car Loads
    Route::get('/car-loads', [CarLoadController::class, 'index'])->name('car-loads.index');
    Route::get('/car-loads/{carLoad}', [CarLoadController::class, 'show'])->name('car-loads.show');
    Route::post('/car-loads', [CarLoadController::class, 'store'])->name('car-loads.store');
    Route::put('/car-loads/{carLoad}', [CarLoadController::class, 'update'])->name('car-loads.update');
    Route::delete('/car-loads/{carLoad}', [CarLoadController::class, 'destroy'])->name('car-loads.destroy');
    
    // Car Load Items
    Route::post('/car-loads/{carLoad}/items', [CarLoadController::class, 'addItems'])->name('car-loads.items.store');
    Route::put('/car-loads/{carLoad}/items/{item}', [CarLoadController::class, 'updateItem'])->name('car-loads.items.update');
    Route::delete('/car-loads/{carLoad}/items/{item}', [CarLoadController::class, 'deleteItem'])->name('car-loads.items.destroy');
    
    // Car Load Actions
    Route::post('/car-loads/{carLoad}/activate', [CarLoadController::class, 'activate'])->name('car-loads.activate');
    Route::post('/car-loads/{carLoad}/unload', [CarLoadController::class, 'unload'])->name('car-loads.unload');
    Route::post('/car-loads/{carLoad}/create-from-previous', [CarLoadController::class, 'createFromPrevious'])->name('car-loads.create-from-previous');

    // Car Load Inventory Routes
    Route::post('/car-loads/{carLoad}/inventories', [CarLoadController::class, 'createInventory'])
        ->name('car-loads.inventories.store');
    Route::get('/car-loads/{carLoad}/inventories/{inventory}/export-pdf', [CarLoadController::class, 'exportInventoryPdf'])
        ->name('car-loads.inventories.export-pdf');
    Route::post('/car-loads/create-from-inventory/{inventory}', [CarLoadController::class, 'createFromInventory'])
        ->name('car-loads.create-from-inventory');
    Route::post('/car-loads/{carLoad}/inventories/{inventory}/items', [CarLoadController::class, 'addInventoryItems'])
        ->name('car-loads.inventories.items.store');
    Route::put('/car-loads/{carLoad}/inventories/{inventory}/items/{item}', [CarLoadController::class, 'updateInventoryItem'])
        ->name('car-loads.inventories.items.update');
    Route::delete('/car-loads/{carLoad}/inventories/{inventory}/items/{item}', [CarLoadController::class, 'deleteInventoryItem'])
        ->name('car-loads.inventories.items.destroy');
    Route::put('/car-loads/{carLoad}/inventories/{inventory}/close', [CarLoadController::class, 'closeInventory'])
        ->name('car-loads.inventories.close');
    Route::get('/car-loads/{carLoad}/items/export-pdf', [CarLoadController::class, 'exportItemsPdf'])
        ->name('car-loads.items.export-pdf');
});
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/admin/rapport', [AdminController::class, 'rapport'])->name('admin.rapport');
    Route::get('/admin/users', [AdminController::class, 'rapport'])->name('users.index');
    Route::resource('teams', TeamController::class);
    Route::post('teams/{team}/add-commercial', [TeamController::class, 'addCommercial'])->name('teams.add-commercial');
    Route::post('teams/{team}/remove-commercial', [TeamController::class, 'removeCommercial'])->name('teams.remove-commercial');
});

require __DIR__.'/auth.php';
