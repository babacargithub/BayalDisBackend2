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
    Route::resource('produits', ProductController::class);
    Route::resource('ventes', VenteController::class);
    Route::resource('zones', ZoneController::class);
    Route::get('zones/{zone}/lignes', [ZoneController::class, 'lignes'])->name('zones.lignes');
    Route::get('lignes/unassigned-customers', [LigneController::class, 'getUnassignedCustomers'])->name('lignes.unassigned-customers');
    Route::resource('lignes', LigneController::class)->only(['index', 'store', 'update', 'destroy', 'show']);
    Route::get('lignes/{ligne}/customers', [LigneController::class, 'customers'])->name('lignes.customers');
    Route::post('lignes/{ligne}/assign-customer', [LigneController::class, 'assignCustomer'])->name('lignes.assign-customer');
    Route::resource('orders', OrderController::class);
});

require __DIR__.'/auth.php';
