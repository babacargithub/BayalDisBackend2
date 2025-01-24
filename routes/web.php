<?php

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

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('commerciaux/{commercial}/activity', [CommercialController::class, 'activity'])->name('commerciaux.activity');
    Route::resource('commerciaux', CommercialController::class);
    Route::resource('clients', CustomerController::class);
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
    Route::post('/sales-invoices/{salesInvoice}/items', [SalesInvoiceController::class, 'addItem'])->name('sales-invoices.items.store');
    Route::put('/sales-invoices/{salesInvoice}/items/{item}', [SalesInvoiceController::class, 'updateItem'])->name('sales-invoices.items.update');
    Route::delete('/sales-invoices/{salesInvoice}/items/{item}', [SalesInvoiceController::class, 'removeItem'])->name('sales-invoices.items.destroy');
    Route::post('/sales-invoices/{salesInvoice}/payments', [SalesInvoiceController::class, 'addPayment'])->name('sales-invoices.payments.store');
    Route::put('/sales-invoices/{salesInvoice}/payments/{payment}', [SalesInvoiceController::class, 'updatePayment'])->name('sales-invoices.payments.update');
    Route::delete('/sales-invoices/{salesInvoice}/payments/{payment}', [SalesInvoiceController::class, 'removePayment'])->name('sales-invoices.payments.destroy');
});

require __DIR__.'/auth.php';
