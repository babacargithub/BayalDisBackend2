<?php

namespace App\Http\Controllers;

use App\Models\Sector;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SectorController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'boundaries' => 'nullable|string',
            'ligne_id' => 'required|exists:lignes,id',
            'description' => 'nullable|string'
        ]);

        Sector::create($validated);

        return redirect()->back()->with('success', 'Secteur créé avec succès');
    }

    public function update(Request $request, Sector $sector)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'boundaries' => 'nullable|string',
            'ligne_id' => 'required|exists:lignes,id',
            'description' => 'nullable|string'
        ]);

        $sector->update($validated);

        return redirect()->back()->with('success', 'Secteur mis à jour avec succès');
    }

    public function destroy(Sector $sector)
    {
        $sector->delete();
        return redirect()->back()->with('success', 'Secteur supprimé avec succès');
    }

    public function addCustomers(Request $request, Sector $sector)
    {
        $validated = $request->validate([
            'customer_ids' => 'required|array',
            'customer_ids.*' => 'exists:customers,id'
        ]);

        try {
            DB::beginTransaction();
            
            $sector->customers()->syncWithoutDetaching($validated['customer_ids']);
            
            DB::commit();
            return redirect()->back()->with('success', 'Clients ajoutés au secteur avec succès');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Erreur lors de l\'ajout des clients au secteur');
        }
    }

    public function removeCustomer(Sector $sector, Customer $customer)
    {
        $sector->customers()->detach($customer->id);
        return redirect()->back()->with('success', 'Client retiré du secteur avec succès');
    }
} 