<?php

use App\Http\Controllers\Api\ApiCarLoadController;
use App\Http\Controllers\Api\ApiCarLoadExpenseController;
use App\Http\Controllers\Api\ApiCommissionController;
use App\Http\Controllers\Api\ApiCustomerController;
use App\Http\Controllers\Api\ApiOrderController;
use App\Http\Controllers\Api\ApiSalesInvoiceController;
use App\Http\Controllers\Api\ApiVersementController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerVisitController as ApiCustomerVisitController;
use App\Http\Controllers\CustomerVisitController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    // Get commercials list
    Route::get('/commercials', [ApiSalesInvoiceController::class, 'getCommercials']);

    // Salesperson routes
    Route::prefix('salesperson/')->group(function () {
        Route::get('commercials', [ApiSalesInvoiceController::class, 'getCommercials']);

        // Ventes
        Route::get('ventes', [ApiSalesInvoiceController::class, 'getSalesAndPaymentsOfATeamInAPeriod']);

        // Sales Invoices
        //        Route::get('sales-invoices', [ApiSalesInvoiceController::class, 'getSalesInvoicesForTeam']);
        Route::get('sales-invoices/debts', [ApiCustomerController::class, 'getDebts']);
        Route::post('sales-invoices', [ApiSalesInvoiceController::class, 'createSalesInvoice'])->name('sales_person.sales-invoices.create');
        Route::post('invoices/{invoice}/pay', [ApiSalesInvoiceController::class, 'paySalesInvoice']);

        // Orders
        Route::get('orders', [ApiOrderController::class, 'getOrders']);
        Route::post('orders', [ApiOrderController::class, 'createOrder']);
        Route::post('orders/{order}/cancel', [ApiOrderController::class, 'cancelOrder']);
        Route::post('orders/{order}/deliver', [ApiOrderController::class, 'deliverOrder']);
        Route::post('orders/{order}/update-items', [ApiOrderController::class, 'updateOrderItems']);

        // Customers
        Route::get('customers', [ApiCustomerController::class, 'getCustomers']);
        Route::get('customers-with-visits', [ApiCustomerController::class, 'getCustomersWithVisits']);
        Route::get('customers/today-count', [ApiCustomerController::class, 'getTodayCustomersCount']);
        Route::post('customers', [ApiCustomerController::class, 'createCustomer']);
        Route::put('customers/{customer}', [ApiCustomerController::class, 'updateCustomer']);

        // Products
        Route::get('products', [ProductController::class, 'index']);
        Route::get('products/{produit}', [ProductController::class, 'show']);
        Route::delete('products/{produit}', [ProductController::class, 'destroy']);
        Route::get('customers_and_products', [ApiSalesInvoiceController::class, 'getCustomersAndProducts']);

        // Customer history
        Route::get('customers/{customer}/ventes', [ApiCustomerController::class, 'getCustomerVentes']);
        Route::get('customers/{customer}/invoices', [ApiCustomerController::class, 'getCustomerInvoices']);

        // Vente payment
        Route::post('ventes/{vente}/pay', [ApiSalesInvoiceController::class, 'paySalesInvoice']);
        Route::get('activity_report', [ApiSalesInvoiceController::class, 'getActivityReport']);

        // Customer Visits
        Route::get('visits', [ApiCustomerVisitController::class, 'getVisitBatches']);
        Route::get('visits/today', [ApiCustomerVisitController::class, 'getTodayVisits']);
        Route::get('visits/{visitBatch}/details', [ApiCustomerVisitController::class, 'getVisitBatchDetails']);
        Route::post('visits/{customerVisit}/complete', [ApiCustomerVisitController::class, 'completeVisit']);
        Route::post('visits/{customerVisit}/cancel', [ApiCustomerVisitController::class, 'cancelVisit']);
        Route::put('visits/{customerVisit}', [ApiCustomerVisitController::class, 'updateVisit']);
        Route::post('visits/complete-from-mobile', [CustomerVisitController::class, 'completeFromMobile']);

        // Misc
        Route::get('customer-categories', [ApiCustomerController::class, 'getCustomerCategories']);
        Route::post('orders/{order}/payments', [PaymentController::class, 'store'])->name('orders.payments.store');

        // Car loads
        Route::get('car-loads/current-items', [ApiCarLoadController::class, 'getCurrentItems']);
        Route::get('products/{product}/variants', [ApiCarLoadController::class, 'getProductVariants']);
        Route::post('car-loads/{product}/transform', [ApiCarLoadController::class, 'transformToVariants'])->name('car-loads.transform_product_to_variants');

        // Car load expenses
        Route::get('car-load-expenses', [ApiCarLoadExpenseController::class, 'index'])->name('salesperson.car-load-expenses.index');
        Route::post('car-load-expenses', [ApiCarLoadExpenseController::class, 'store'])->name('salesperson.car-load-expenses.store');
        Route::delete('car-load-expenses/{expenseId}', [ApiCarLoadExpenseController::class, 'destroy'])->name('salesperson.car-load-expenses.destroy');

        Route::get('weekly-debts', [ApiCustomerController::class, 'getWeeklyDebts']);

        // Commission
        Route::get('daily-commission', [ApiCommissionController::class, 'getDailyCommission'])->name('salesperson.daily-commission');
        Route::get('weekly-commissions', [ApiCommissionController::class, 'getWeeklyCommissions'])->name('salesperson.weekly-commissions');
        Route::get('monthly-commissions', [ApiCommissionController::class, 'getMonthlyCommissions'])->name('salesperson.monthly-commissions');
        Route::get('commission-overview', [ApiCommissionController::class, 'getCommissionOverview'])->name('salesperson.commission-overview');
        Route::get('commission-detail', [ApiCommissionController::class, 'getCommissionDetail'])->name('salesperson.commission-detail');
        Route::get('commission-structure', [ApiCommissionController::class, 'getCommissionStructure'])->name('salesperson.commission-structure');

        // Versement (commercial sweeps caisse to main caisse + commissions credited to account)
        Route::get('caisse', [ApiVersementController::class, 'balance'])->name('salesperson.caisse.balance');
        Route::post('versement', [ApiVersementController::class, 'store'])->name('salesperson.versement.store');
        Route::get('versements', [ApiVersementController::class, 'index'])->name('salesperson.versements.index');
    });
});
