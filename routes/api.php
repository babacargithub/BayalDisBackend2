<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SalespersonController;
use App\Http\Controllers\Api\ProductController;

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
        
        // Customers
        Route::get('/customers', [SalespersonController::class, 'getCustomers']);
        Route::get('/customers/today-count', [SalespersonController::class, 'getTodayCustomersCount']);
        Route::post('/customers', [SalespersonController::class, 'createCustomer']);
        Route::put('/customers/{customer}', [SalespersonController::class, 'updateCustomer']);
        
        // Products
        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/products/{product}', [ProductController::class, 'show']);
        Route::get('/customers_and_products', [ProductController::class, 'getCustomersAndProducts']);
        
        // Customer ventes history
        Route::get('customers/{customer}/ventes', [SalespersonController::class, 'getCustomerVentes']);
        
        // Vente payment
        Route::post('ventes/{vente}/pay', [SalespersonController::class, 'payVente']);
    });
});
