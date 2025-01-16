<?php

namespace App\Http\Controllers;

use App\Models\Ligne;
use App\Models\Customer;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LigneController extends Controller
{
    public function index()
    {
        return Inertia::render('Lignes/Index', [
            'lignes' => Ligne::with(['zone', 'livreur', 'customers'])->latest()->get(),
            'unassignedCustomers' => Customer::whereNull('ligne_id')->get()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'zone_id' => 'required|exists:zones,id',
            'livreur_id' => 'nullable|exists:livreurs,id',
        ]);

        Ligne::create($validated);

        return redirect()->back()->with('success', 'Ligne créée avec succès');
    }

    public function update(Request $request, Ligne $ligne)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'zone_id' => 'required|exists:zones,id',
            'livreur_id' => 'nullable|exists:livreurs,id',
        ]);

        $ligne->update($validated);

        return redirect()->back()->with('success', 'Ligne mise à jour avec succès');
    }

    public function destroy(Ligne $ligne)
    {
        try {
            if ($ligne->customers()->exists()) {
                return back()->with('error', 'Impossible de supprimer cette ligne car elle contient des clients');
            }

            $ligne->delete();
            return back()->with('success', 'Ligne supprimée avec succès');
        } catch (\Exception $e) {
            return back()->with('error', 'Erreur lors de la suppression de la ligne');
        }
    }

    public function show(Ligne $ligne)
    {
        return Inertia::render('Lignes/Show', [
            'ligne' => $ligne->load(['zone', 'livreur', 'customers.ventes']),
            'unassignedCustomers' => Customer::whereNull('ligne_id')->get()
        ]);
    }

    public function customers(Ligne $ligne)
    {
        return response()->json($ligne->customers()->with(['ventes'])->get());
    }

    public function assignCustomer(Request $request, Ligne $ligne)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id'
        ]);

        $customer = Customer::findOrFail($validated['customer_id']);
        
        if ($customer->ligne_id !== null) {
            return back()->with('error', 'Ce client est déjà assigné à une ligne');
        }

        $customer->update(['ligne_id' => $ligne->id]);

        return back()->with('success', 'Client assigné avec succès');
    }

    public function getUnassignedCustomers()
    {
        return response()->json(Customer::whereNull('ligne_id')->get());
    }

    public function assignCustomers(Request $request, Ligne $ligne)
    {
        $validated = $request->validate([
            'customer_ids' => 'required|array',
            'customer_ids.*' => 'exists:customers,id'
        ]);

        Customer::whereIn('id', $validated['customer_ids'])
            ->whereNull('ligne_id')
            ->update(['ligne_id' => $ligne->id]);

        return back()->with('success', 'Clients assignés avec succès');
    }
} 