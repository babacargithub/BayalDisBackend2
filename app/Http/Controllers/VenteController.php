<?php

namespace App\Http\Controllers;

use App\Models\Vente;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Commercial;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;

class VenteController extends Controller
{
    public function index(Request $request)
    {
        $query = Vente::with(['product', 'customer', 'commercial']);

        // Filter by payment status
        if ($request->has('paid')) {
            $paid = $request->boolean('paid');
            $query->where('paid', $paid);
        }

        // Filter by date range
        if ($request->filled('date_debut')) {
            $query->whereDate('created_at', '>=', $request->date_debut);
        }
        if ($request->filled('date_fin')) {
            $query->whereDate('created_at', '<=', $request->date_fin);
        }

        // Filter by commercial
        if ($request->filled('commercial_id')) {
            $query->where('commercial_id', $request->commercial_id);
        }

        $ventes = $query->latest()->get();

        // Calculate statistics
        $statistics = [
            'total_ventes' => $ventes->count(),
            'total_amount' => $ventes->sum(function ($vente) {
                return $vente->price * $vente->quantity;
            }),
            'paid_count' => $ventes->where('paid', true)->count(),
            'paid_amount' => $ventes->where('paid', true)->sum(function ($vente) {
                return $vente->price * $vente->quantity;
            }),
            'unpaid_count' => $ventes->where('paid', false)->count(),
            'unpaid_amount' => $ventes->where('paid', false)->sum(function ($vente) {
                return $vente->price * $vente->quantity;
            }),
        ];

        return Inertia::render('Ventes/Index', [
            'ventes' => $ventes,
            'produits' => Product::all(),
            'clients' => Customer::all(),
            'commerciaux' => Commercial::all(),
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
            'should_be_paid_at' => 'required|date',
        ]);

        Vente::create($validated);

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
            $vente->delete();
            return redirect()->back()->with('success', 'Vente supprimée avec succès');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Erreur lors de la suppression de la vente');
        }
    }
} 