<?php

namespace App\Http\Controllers;

use App\Models\Vente;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Commercial;
use Exception;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;

class VenteController extends Controller
{
    public function index(Request $request)
    {
        // Base query with optimized eager loading and join to ensure products exist
        $query = Vente::query()
            ->select(
                'ventes.id',
                'ventes.product_id',
                'ventes.customer_id',
                'ventes.commercial_id',
                'ventes.quantity',
                'ventes.price',
                'ventes.paid',
                'ventes.created_at',
                'ventes.should_be_paid_at'
            )
            ->join('products', 'ventes.product_id', '=', 'products.id')
            ->with([
                'product:id,name',
                'customer:id,name',
                'commercial:id,name',
            ])
            ->where('ventes.type', 'SINGLE'); // Only show single ventes, not invoice items

        // Filter by payment status
        if ($request->has('paid')) {
            $query->where('ventes.paid', $request->boolean('paid'));
        }

        // Filter by date range
        if ($request->filled('date_debut')) {
            $query->whereDate('ventes.created_at', '>=', $request->date_debut);
        }
        if ($request->filled('date_fin')) {
            $query->whereDate('ventes.created_at', '<=', $request->date_fin);
        }

        // Filter by commercial
        if ($request->filled('commercial_id')) {
            $query->where('ventes.commercial_id', $request->commercial_id);
        }

        // Calculate statistics using database queries
        $statistics = [
            'total_ventes' => $query->count(),
            'total_amount' => $query->sum(DB::raw('ventes.price * ventes.quantity')),
            'paid_count' => (clone $query)->where('ventes.paid', true)->count(),
            'paid_amount' => (clone $query)->where('ventes.paid', true)->sum(DB::raw('ventes.price * ventes.quantity')),
            'unpaid_count' => (clone $query)->where('ventes.paid', false)->count(),
            'unpaid_amount' => (clone $query)->where('ventes.paid', false)->sum(DB::raw('ventes.price * ventes.quantity')),
        ];

        // Get paginated results with proper eager loading
        $ventes = $query->latest('ventes.created_at')
            ->paginate(25)
            ->through(function ($vente) {
                // Ensure computed properties are properly set
                $vente->subtotal = $vente->price * $vente->quantity;
                return $vente;
            });

        return Inertia::render('Ventes/Index', [
            'ventes' => $ventes,
            'produits' => Product::select(['id', 'name', 'price'])->orderBy('name')->get(),
            'clients' => Customer::select(['id', 'name'])->orderBy('name')->get(),
            'commerciaux' => Commercial::select(['id', 'name'])->orderBy('name')->get(),
            'filters' => $request->only(['date_debut', 'date_fin', 'paid', 'commercial_id']),
            'statistics' => $statistics
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'customer_id' => 'required|exists:customers,id',
            'commercial_id' => 'required|exists:commercials,id',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'paid' => 'required|boolean',
            'should_be_paid_at' => 'required_if:paid,false|nullable|date',
        ]);

        // If paid is true, set should_be_paid_at to null
        if ($validated['paid']) {
            $validated['should_be_paid_at'] = null;
        }

        // Add type for single vente
        $validated['type'] = 'SINGLE';

        DB::transaction(function () use ($validated) {
            // Create the vente
            Vente::create($validated);
            // check if the customer is a prospect
            /** @var Customer $customer */
            $customer = Customer::findOrFail($validated['customer_id']);
            if ($customer->is_prospect) {
                $customer->is_prospect = false;
                $customer->save();
            }

        });

        return redirect()->back()->with('success', 'Vente enregistrée avec succès');
    }

    public function update(Request $request, Vente $vente)
    {
        $validated = $request->validate([
            'paid' => 'boolean',
            'should_be_paid_at' => 'date',
        ]);

        $vente->update($validated);

        return redirect()->back()->with('success', 'Vente mise à jour avec succès');
    }

    public function destroy(Vente $vente)
    {
        try {
            if ($vente->type !== 'SINGLE') {
                return redirect()->back()->with('error', 'Cannot delete invoice items directly');
            }
            $vente->delete();
            return redirect()->back()->with('success', 'Vente supprimée avec succès');
        } catch (Exception $e) {
            return redirect()->back()->with('error', 'Erreur lors de la suppression de la vente : ' . $e->getMessage());
        }
    }
} 