<?php

namespace App\Http\Controllers;

use App\Models\Caisse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Product;
use App\Models\Payment;
use App\Models\Investment;
use App\Models\Depense;
use App\Models\SalesInvoice;
use App\Models\Vente;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function rapport()
    {
        // Calculate total stock value
        $stockValue = Product::all()->sum("stock_value");

        // Calculate total debt (unpaid amount from sales)
        $totalDebt = SalesInvoice::all()->sum("total_remaining");

        // Calculate total investments
        $totalInvestments = Investment::sum('amount');

        // Calculate total expenses
        $totalDepenses = Depense::sum('amount');

        // Calculate total sales
        $totalSales = Vente::sum(DB::raw('quantity * price'));

        // Calculate total profits (sales - (investments + expenses))
        $totalProfitsOfSingleVentes = Vente::where("type","SINGLE")
            ->where("paid", true)
            ->sum("profit");
        $totalProfitsSalesInvoices  = Vente::whereHas('salesInvoice', function($query) {
            $query->where('paid', true);
        })
        ->sum('profit');

        $totalProfits = $totalProfitsOfSingleVentes + $totalProfitsSalesInvoices;
        $totalCaisses = Caisse::all()->sum('balance');

        return Inertia::render('Admin/Rapport', [
            'statistics' => [
                'stock_value' => $stockValue,
                'total_debt' => $totalDebt,
                'total_investments' => $totalInvestments,
                'total_depenses' => $totalDepenses,
                'total_sales' => $totalSales,
                'total_profits' => $totalProfits,
                "total_caisses"=>"$totalCaisses",
                "total_payments"=>Payment::all()->sum('amount'),
                "value_of_business"=>($stockValue+$totalCaisses+$totalDebt)
            ]
        ]);
    }
} 