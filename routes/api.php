<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SalespersonController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CommercialController;
use App\Http\Controllers\Api\PaymentController;

// Public routes
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // Salesperson routes
    Route::prefix('salesperson')->group(function () {
        // Ventes
        Route::get('/ventes', [SalespersonController::class, 'getVentes']);
        Route::post('/ventes', [SalespersonController::class, 'createVente']);
        
        // Orders
        Route::get('/orders', [SalespersonController::class, 'getOrders']);
        Route::post('/orders', [SalespersonController::class, 'createOrder']);
        Route::post('/orders/{order}/cancel', [SalespersonController::class, 'cancelOrder']);
        Route::post('/orders/{order}/deliver', [SalespersonController::class, 'deliverOrder']);
        
        // Customers
        Route::get('/customers', [SalespersonController::class, 'getCustomers']);
        Route::get('/customers/today-count', [SalespersonController::class, 'getTodayCustomersCount']);
        Route::post('/customers', [SalespersonController::class, 'createCustomer']);
        Route::put('/customers/{customer}', [SalespersonController::class, 'updateCustomer']);
        
        // Products
        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/products/{produit}', [ProductController::class, 'show']);
        Route::delete('/products/{produit}', [ProductController::class, 'destroy']);
        Route::get('/customers_and_products', [SalespersonController::class, 'getCustomersAndProducts']);
        
        // Customer ventes history
        Route::get('customers/{customer}/ventes', [SalespersonController::class, 'getCustomerVentes']);
        
        // Vente payment
        Route::post('ventes/{vente}/pay', [SalespersonController::class, 'payVente']);
        Route::get('/activity_report', [SalespersonController::class, 'getActivityReport']);
    });

    // Add this route with the other API routes
    Route::post('/orders/{order}/payments', [PaymentController::class, 'store'])->name('orders.payments.store');
});
