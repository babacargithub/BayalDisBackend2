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
        $query = Vente::with(['product', 'customer', 'commercial'])
            ->when($request->date_debut, function ($q) use ($request) {
                return $q->whereDate('created_at', '>=', $request->date_debut);
            })
            ->when($request->date_fin, function ($q) use ($request) {
                return $q->whereDate('created_at', '<=', $request->date_fin);
            })
            ->when(isset($request->paid), function ($q) use ($request) {
                return $q->where('paid', $request->paid);
            })
            ->when($request->commercial_id, function ($q) use ($request) {
                return $q->where('commercial_id', $request->commercial_id);
            });

        $ventes = $query->latest()->get();

        // Calculate statistics
        $statistics = [
            'total_ventes' => $ventes->count(),
            'total_amount' => $ventes->sum(fn($v) => $v->price * $v->quantity),
            'unpaid_amount' => $ventes->where('paid', false)->sum(fn($v) => $v->price * $v->quantity),
            'paid_amount' => $ventes->where('paid', true)->sum(fn($v) => $v->price * $v->quantity),
            'unpaid_count' => $ventes->where('paid', false)->count(),
            'paid_count' => $ventes->where('paid', true)->count(),
        ];

        return Inertia::render('Ventes/Index', [
            'ventes' => $ventes,
            'produits' => Product::all(),
            'clients' => Customer::all(),
            'commerciaux' => Commercial::all(),
            'filters' => $request->only(['date_debut', 'date_fin', 'paid', 'commercial_id']),
            'statistics' => $statistics,
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