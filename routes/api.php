<?php

use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ApiCarLoadController;
use App\Http\Controllers\Api\SalespersonController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\CustomerVisitController;
use App\Http\Controllers\Api\ProductController as ApiProductController;

// Public routes
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // Get commercials list
    Route::get('/commercials', [SalespersonController::class, 'getCommercials']);
    
    // Salesperson routes
    Route::prefix('salesperson/')->group(function () {
        // Get commercials list
        Route::get('commercials', [SalespersonController::class, 'getCommercials']);
        
        // Ventes
        Route::get('ventes', [SalespersonController::class, 'getVentes']);
        Route::post('ventes', [SalespersonController::class, 'createVente']);
        
        // Sales Invoices
        Route::get('sales-invoices', [SalespersonController::class, 'getSalesInvoices']);
        Route::get('sales-invoices/debts', [SalespersonController::class, 'getDebts']);
        Route::post('sales-invoices', [SalespersonController::class, 'createSalesInvoice'])->name('sales_person.sales-invoices.create');
        Route::post('invoices/{invoice}/pay', [SalespersonController::class, 'paySalesInvoice']);
        
        // Orders
        Route::get('orders', [SalespersonController::class, 'getOrders']);
        Route::post('orders', [SalespersonController::class, 'createOrder']);
        Route::post('orders/{order}/cancel', [SalespersonController::class, 'cancelOrder']);
        Route::post('orders/{order}/deliver', [SalespersonController::class, 'deliverOrder']);
        Route::post('orders/{order}/update-items', [SalespersonController::class, 'updateOrderItems']);
        
        // Customers
        Route::get('customers', [SalespersonController::class, 'getCustomers']);
        Route::get('customers-with-visits', [SalespersonController::class, 'getCustomersWithVisits']);
        Route::get('customers/today-count', [SalespersonController::class, 'getTodayCustomersCount']);
        Route::post('customers', [SalespersonController::class, 'createCustomer']);
        Route::put('customers/{customer}', [SalespersonController::class, 'updateCustomer']);
        
        // Products
        Route::get('products', [ProductController::class, 'index']);
        Route::get('products/{produit}', [ProductController::class, 'show']);
        Route::delete('products/{produit}', [ProductController::class, 'destroy']);
        Route::get('customers_and_products', [SalespersonController::class, 'getCustomersAndProducts']);
        
        // Customer ventes history
        Route::get('customers/{customer}/ventes', [SalespersonController::class, 'getCustomerVentes']);
        Route::get('customers/{customer}/invoices', [SalespersonController::class, 'getCustomerInvoices']);
        
        // Vente payment
        Route::post('ventes/{vente}/pay', [SalespersonController::class, 'payVente']);
        Route::get('activity_report', [SalespersonController::class, 'getActivityReport']);

        // Customer Visits
        Route::get('visits', [SalespersonController::class, 'getVisitBatches']);
        Route::get('visits/{visitBatch}/details', [SalespersonController::class, 'getVisitBatchDetails']);
        Route::get('visits/today', [SalespersonController::class, 'getTodayVisits']);
        Route::post('visits/{customerVisit}/complete', [SalespersonController::class, 'completeVisit']);
        Route::post('visits/{customerVisit}/cancel', [SalespersonController::class, 'cancelVisit']);
        Route::put('visits/{customerVisit}', [SalespersonController::class, 'updateVisit']);
        Route::post('visits/complete-from-mobile', [CustomerVisitController::class, 'completeFromMobile']);

        Route::get('customer-categories', [SalespersonController::class, 'getCustomerCategories']);
        Route::post('orders/{order}/payments', [PaymentController::class, 'store'])->name('orders.payments.store');

        Route::get('car-loads/current-items', [ApiCarLoadController::class, 'getCurrentItems']);
        Route::get('products/{product}/variants', [ApiCarLoadController::class, 'getProductVariants']);
        Route::post('car-loads/{product}/transform', [ApiCarLoadController::class, 'transformToVariants'])->name('car-loads.transform_product_to_variants');;;

        Route::get('weekly-debts', [SalespersonController::class, 'getWeeklyDebts']);
    });

    // Add this route with the other API routes

});
