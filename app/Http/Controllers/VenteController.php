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
        $query = Vente::with(['produit', 'client', 'commercial']);

        if ($request->date_debut) {
            $query->whereDate('created_at', '>=', $request->date_debut);
        }

        if ($request->date_fin) {
            $query->whereDate('created_at', '<=', $request->date_fin);
        }

        if ($request->has('paid')) {
            $query->where('paid', $request->paid);
        }

        if ($request->commercial_id) {
            $query->where('commercial_id', $request->commercial_id);
        }

        return Inertia::render('Ventes/Index', [
            'ventes' => $query->latest()->get(),
            'produits' => Product::all(),
            'clients' => Customer::all(),
            'commerciaux' => Commercial::all(),
            'filters' => $request->all(),
            'statistics' => [
                'total_ventes' => $query->count(),
                'montant_total' => $query->sum(DB::raw('price * quantity')),
                'montant_impaye' => $query->where('paid', false)->sum(DB::raw('price * quantity')),
                'ventes_par_produit' => Product::withCount('ventes')->get(),
            ],
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
} 