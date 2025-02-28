<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Vente;
use App\Models\Payment;
use App\Models\Commercial;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        try {
            $stats = [
                'dailyStats' => $this->getDailyStats(),
                'weeklyStats' => $this->getWeeklyStats(),
                'monthlyStats' => $this->getMonthlyStats(),
                'overallStats' => $this->getOverallStats(),
            ];

            return Inertia::render('Dashboard', $stats);
        } catch (\Exception $e) {
            Log::error('Error fetching dashboard stats: ' . $e->getMessage());
            return Inertia::render('Dashboard', [
                'dailyStats' => [],
                'weeklyStats' => [],
                'monthlyStats' => [],
                'overallStats' => [],
            ]);
        }
    }

    private function getDailyStats()
    {
        try {
            $today = Carbon::today();
            
            $total_amount_paid_single = Vente::whereDate('created_at', $today)
                ->where('paid', true)
                ->where("type",Vente::TYPE_SINGLE)
                ->sum(DB::raw('price * quantity'));

            $total_paid_invoices = 0;

            $total_amount_paid_single = $total_amount_paid_single + $total_paid_invoices;
            
            $total_amount_unpaid = Vente::whereDate('created_at', $today)
                ->where('paid', false)
                ->where('type',Vente::TYPE_SINGLE)
                ->sum(DB::raw('price * quantity'))
                +
                SalesInvoice::whereDate("created_at",now()->toDateString())
                    ->get()->sum("total_remaining");


            $total_profit = Vente::whereDate('created_at', $today)
                ->sum('profit');
            $total_net_profit =Vente::whereDate('created_at', $today)
                ->where('paid', true)
                ->where('type',Vente::TYPE_SINGLE)
                ->sum('profit')
                + SalesInvoice::whereDate("created_at",$today->toDateString())
                    ->get()->sum("totalProfitPaid");

            $total_payments = Payment::whereDate('created_at', $today)
                ->sum('amount');
            
            return [
                'total_customers' => Customer::whereDate('created_at', $today)->count(),
                'total_prospects' => Customer::whereDate('created_at', $today)->prospects()->count(),
                'total_confirmed_customers' => Customer::whereDate('created_at', $today)->nonProspects()->count(),
                'total_ventes' => Vente::whereDate('created_at', $today)->count(),
                'total_ventes_paid' => Vente::whereDate('created_at', $today)->where('paid', true)->count(),
                'total_ventes_unpaid' => Vente::whereDate('created_at', $today)->where('paid', false)->count(),
                'total_amount_paid' => $total_amount_paid_single,
                'total_amount_unpaid' => $total_amount_unpaid,
                'total_amount_gross' => $total_amount_paid_single + $total_amount_unpaid,
                'total_profit' => $total_profit,
                'total_net_profit' => $total_net_profit,
                'total_payments' => $total_payments,
            ];
        } catch (\Exception $e) {
            Log::error('Error getting daily stats: ' . $e->getMessage());
            return [];
        }
    }

    private function getWeeklyStats()
    {
        try {
            $startOfWeek = Carbon::now()->startOfWeek();
            
            $total_amount_paid = Vente::where('created_at', '>=', $startOfWeek)
                ->where('paid', true)
                ->sum(DB::raw('price * quantity'));
            
            $total_amount_unpaid = Vente::where('created_at', '>=', $startOfWeek)
                ->where('paid', false)
                ->sum(DB::raw('price * quantity'));

            $total_profit = Vente::where('created_at', '>=', $startOfWeek)
                ->sum('profit');

            $total_payments = Payment::where('created_at', '>=', $startOfWeek)
                ->sum('amount');
            
            return [
                'total_customers' => Customer::where('created_at', '>=', $startOfWeek)->count(),
                'total_prospects' => Customer::where('created_at', '>=', $startOfWeek)->prospects()->count(),
                'total_confirmed_customers' => Customer::where('created_at', '>=', $startOfWeek)->nonProspects()->count(),
                'total_ventes' => Vente::where('created_at', '>=', $startOfWeek)->count(),
                'total_ventes_paid' => Vente::where('created_at', '>=', $startOfWeek)->where('paid', true)->count(),
                'total_ventes_unpaid' => Vente::where('created_at', '>=', $startOfWeek)->where('paid', false)->count(),
                'total_amount_paid' => $total_amount_paid,
                'total_amount_unpaid' => $total_amount_unpaid,
                'total_amount_gross' => $total_amount_paid + $total_amount_unpaid,
                'total_profit' => $total_profit,
                'total_payments' => $total_payments,
            ];
        } catch (\Exception $e) {
            Log::error('Error getting weekly stats: ' . $e->getMessage());
            return [];
        }
    }

    private function getMonthlyStats()
    {
        try {
            $startOfMonth = Carbon::now()->startOfMonth();
            
            $total_amount_paid = Vente::where('created_at', '>=', $startOfMonth)
                ->where('paid', true)
                ->sum(DB::raw('price * quantity'));
            
            $total_amount_unpaid = Vente::where('created_at', '>=', $startOfMonth)
                ->where('paid', false)
                ->sum(DB::raw('price * quantity'));

            $total_profit = Vente::where('created_at', '>=', $startOfMonth)
                ->sum('profit');

            $total_payments = Payment::where('created_at', '>=', $startOfMonth)
                ->sum('amount');
            
            return [
                'total_customers' => Customer::where('created_at', '>=', $startOfMonth)->count(),
                'total_prospects' => Customer::where('created_at', '>=', $startOfMonth)->prospects()->count(),
                'total_confirmed_customers' => Customer::where('created_at', '>=', $startOfMonth)->nonProspects()->count(),
                'total_ventes' => Vente::where('created_at', '>=', $startOfMonth)->count(),
                'total_ventes_paid' => Vente::where('created_at', '>=', $startOfMonth)->where('paid', true)->count(),
                'total_ventes_unpaid' => Vente::where('created_at', '>=', $startOfMonth)->where('paid', false)->count(),
                'total_amount_paid' => $total_amount_paid,
                'total_amount_unpaid' => $total_amount_unpaid,
                'total_amount_gross' => $total_amount_paid + $total_amount_unpaid,
                'total_profit' => $total_profit,
                'total_payments' => $total_payments,
            ];
        } catch (\Exception $e) {
            Log::error('Error getting monthly stats: ' . $e->getMessage());
            return [];
        }
    }

    private function getOverallStats()
    {
        try {
            $total_amount_paid = Vente::where('paid', true)
                ->sum(DB::raw('price * quantity'));
            
            $total_amount_unpaid = Vente::where('paid', false)
                ->sum(DB::raw('price * quantity'));

            $total_profit = Vente::sum('profit');

            $total_payments = Payment::sum('amount');
            
            return [
                'total_customers' => Customer::count(),
                'total_prospects' => Customer::prospects()->count(),
                'total_confirmed_customers' => Customer::nonProspects()->count(),
                'total_ventes' => Vente::count(),
                'total_ventes_paid' => Vente::where('paid', true)->count(),
                'total_ventes_unpaid' => Vente::where('paid', false)->count(),
                'total_amount_paid' => $total_amount_paid,
                'total_amount_unpaid' => $total_amount_unpaid,
                'total_amount_gross' => $total_amount_paid + $total_amount_unpaid,
                'total_profit' => $total_profit,
                'total_commerciaux' => Commercial::count(),
                'total_payments' => $total_payments,
            ];
        } catch (\Exception $e) {
            Log::error('Error getting overall stats: ' . $e->getMessage());
            return [];
        }
    }
} 