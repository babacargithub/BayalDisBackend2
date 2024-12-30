<?php

namespace App\Http\Controllers;

use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Vente;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $statistics = [
            'customers_count' => Customer::count(),
            'commercials_count' => Commercial::count(),
            'total_sales' => Vente::sum(DB::raw('price * quantity')),
            'total_ventes' => Vente::count(),
            'unpaid_ventes' => Vente::where('paid', false)->count(),
            'total_unpaid_amount' => Vente::where('paid', false)->sum(DB::raw('price * quantity')),
        ];

        return Inertia::render('Dashboard', [
            'statistics' => $statistics
        ]);
    }
} 