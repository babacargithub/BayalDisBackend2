<?php

namespace App\Http\Controllers;

use App\Models\Commercial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Carbon\Carbon;

class CommercialController extends Controller
{
    public function index()
    {
        $commerciaux = Commercial::with('customers')
            ->withCount('ventes')
            ->withSum('ventes', DB::raw('price * quantity'))
            ->withCount(['ventes as ventes_impayees_count' => function ($query) {
                $query->where('paid', false);
            }])
            ->get();

        return Inertia::render('Commercials/Index', [
            'commerciaux' => $commerciaux,
            'statistics' => [
                'total_commerciaux' => $commerciaux->count(),
                'total_clients' => $commerciaux->sum(fn($c) => $c->customers->count()),
                'moyenne_ventes' => $commerciaux->avg('ventes_count'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => 'required|string|unique:commercials',
            'gender' => 'required|in:male,female',
            'secret_code' => 'required|string|min:4|max:20',
        ]);

        // Hash the secret code
        $validated['secret_code'] = Hash::make($validated['secret_code']);

        Commercial::create($validated);

        return redirect()->back()->with('success', 'Commercial ajouté avec succès');
    }

    public function update(Request $request, $id)
    {
        $commercial = Commercial::findOrFail($id);

        // Debug incoming request data
        \Log::info('Update Commercial Request:', [
            'request_data' => $request->except('secret_code'), // Don't log the secret code
            'commercial_id' => $id
        ]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => 'required|string|unique:commercials,phone_number,' . $commercial->id,
            'gender' => 'required|in:male,female',
            'secret_code' => 'required|string|min:4|max:20',
        ]);

        try {
            // Hash the secret code
            $validated['secret_code'] = Hash::make($validated['secret_code']);

            // Debug validated data (excluding secret code)
            \Log::info('Validated data:', array_diff_key($validated, ['secret_code' => '']));

            $commercial->update($validated);

            // Verify the update (excluding secret code from logs)
            $commercial->refresh();
            \Log::info('Commercial after update:', array_diff_key($commercial->toArray(), ['secret_code' => '']));

            return redirect()->back()->with('success', 'Commercial mis à jour avec succès');
        } catch (\Exception $e) {
            \Log::error('Update failed:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()->back()->with('error', 'Erreur lors de la mise à jour du commercial: ' . $e->getMessage());
        }
    }

    public function destroy($id)
    {
        try {
            $commercial = Commercial::findOrFail($id);

            // Log the delete attempt
            \Log::info('Attempting to delete commercial:', [
                'commercial_id' => $id,
                'commercial_name' => $commercial->name
            ]);

            // Check if commercial has related clients
            if ($commercial->customers()->exists()) {
                \Log::warning('Cannot delete commercial - has related clients:', [
                    'commercial_id' => $id,
                    'clients_count' => $commercial->customers()->count()
                ]);
                return redirect()->back()->with('error', 'Impossible de supprimer ce commercial car il a des clients associés');
            }

            $commercial->delete();
            \Log::info('Commercial deleted successfully:', [
                'commercial_id' => $id
            ]);
            
            return redirect()->back()->with('success', 'Commercial supprimé avec succès');
        } catch (\Exception $e) {
            \Log::error('Error deleting commercial:', [
                'commercial_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()->back()->with('error', 'Erreur lors de la suppression du commercial: ' . $e->getMessage());
        }
    }

    public function activity(Commercial $commercial)
    {
        $now = now();
        $startOfDay = $now->copy()->startOfDay();
        $startOfWeek = $now->copy()->startOfWeek();
        $startOfMonth = $now->copy()->startOfMonth();

        // Helper function to get stats for a given period
        $getStats = function ($startDate = null) use ($commercial) {
            // Base queries
            $customersQuery = $commercial->customers();
            $ventesQuery = $commercial->ventes();
            
            if ($startDate) {
                $customersQuery = $customersQuery->where('created_at', '>=', $startDate);
                $ventesQuery = $ventesQuery->where('created_at', '>=', $startDate);
            }

            // Get customers stats
            $customersAll = (clone $customersQuery)->count();
            $customersConfirmed = (clone $customersQuery)->whereHas('ventes')->count();
            $customersProspects = (clone $customersQuery)->whereDoesntHave('ventes')->count();

            // Get ventes stats
            $ventesAll = (clone $ventesQuery)->count();
            $ventesPaid = (clone $ventesQuery)->where('paid', true)->count();
            $ventesUnpaid = (clone $ventesQuery)->where('paid', false)->count();

            // Get amounts
            $totalAll = (clone $ventesQuery)->sum(DB::raw('price * quantity'));
            $totalPaid = (clone $ventesQuery)->where('paid', true)->sum(DB::raw('price * quantity'));
            $totalUnpaid = (clone $ventesQuery)->where('paid', false)->sum(DB::raw('price * quantity'));
            $commission = $totalPaid * 0.1; // 10% commission
            
            return [
                'customers_count_all' => $customersAll,
                'customers_count_confirmed' => $customersConfirmed,
                'customers_count_prospects' => $customersProspects,
                'ventes_count_all' => $ventesAll,
                'ventes_count_paid' => $ventesPaid,
                'ventes_count_unpaid' => $ventesUnpaid,
                'total_ventes_all' => $totalAll,
                'total_ventes_paid' => $totalPaid,
                'total_ventes_unpaid' => $totalUnpaid,
                'commission' => $commission,
            ];
        };

        // Get stats for different periods
        $stats = [
            'daily' => $getStats($startOfDay),
            'weekly' => $getStats($startOfWeek),
            'monthly' => $getStats($startOfMonth),
            'overall' => $getStats(),
        ];

        return Inertia::render('Commercials/Activity', [
            'commercial' => $commercial,
            'stats' => $stats,
        ]);
    }

    public function getActivityReport(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
            'type' => 'required|in:daily,weekly'
        ]);

        $commercial = auth()->user();
        $startDate = $request->type === 'weekly' 
            ? Carbon::parse($request->date)->startOfWeek() 
            : Carbon::parse($request->date)->startOfDay();
        $endDate = $request->type === 'weekly'
            ? Carbon::parse($request->date)->endOfWeek()
            : Carbon::parse($request->date)->endOfDay();

        // Get customers created in period
        $customersCreated = $commercial->customers()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        // Get total customers and prospects
        $customersCount = $commercial->customers()
            ->whereNotNull('first_vente_at')
            ->count();
        $prospectsCount = $commercial->customers()
            ->whereNull('first_vente_at')
            ->count();

        // Get sales per product
        $productSales = DB::table('ventes')
            ->join('customers', 'ventes.customer_id', '=', 'customers.id')
            ->join('products', 'ventes.product_id', '=', 'products.id')
            ->where('customers.commercial_id', $commercial->id)
            ->whereBetween('ventes.created_at', [$startDate, $endDate])
            ->select(
                'products.id',
                'products.name',
                DB::raw('COUNT(*) as sales_count'),
                DB::raw('SUM(ventes.quantity) as total_quantity'),
                DB::raw('SUM(ventes.total_amount) as total_amount')
            )
            ->groupBy('products.id', 'products.name')
            ->having('sales_count', '>', 0)
            ->get();

        // Get sales per payment method
        $paymentMethodSales = DB::table('ventes')
            ->join('customers', 'ventes.customer_id', '=', 'customers.id')
            ->where('customers.commercial_id', $commercial->id)
            ->whereBetween('ventes.created_at', [$startDate, $endDate])
            ->select(
                'payment_method',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(total_amount) as total_amount')
            )
            ->groupBy('payment_method')
            ->get();

        return response()->json([
            'period' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d'),
                'type' => $request->type
            ],
            'customers_created' => $customersCreated,
            'customers_count' => $customersCount,
            'prospects_count' => $prospectsCount,
            'product_sales' => $productSales,
            'payment_method_sales' => $paymentMethodSales
        ]);
    }
} 