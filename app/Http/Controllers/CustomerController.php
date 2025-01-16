<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Commercial;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::with(['commercial', 'ventes']);

        // Filter by commercial_id if provided
        if ($request->filled('commercial_id')) {
            $query->where('commercial_id', $request->commercial_id);
        }

        if ($request->filled('prospect_status')) {
            if ($request->prospect_status === 'prospects') {
                $query->prospects();
            } elseif ($request->prospect_status === 'customers') {
                $query->nonProspects();
            }
        }

        return Inertia::render('Clients/Index', [
            'clients' => $query->latest()->get(),
            'commerciaux' => Commercial::all(),
            'filters' => $request->only(['prospect_status', 'commercial_id'])
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:255',
            'owner_number' => 'nullable|string|max:255',
            'gps_coordinates' => 'nullable|string|max:255',
            'commercial_id' => 'nullable|exists:commercials,id',
            'description' => 'nullable|string',
        ]);

        Customer::create($validated);

        return redirect()->back()->with('success', 'Client créé avec succès');
    }

    public function update(Request $request, $id)
    {
        $customer = Customer::findOrFail($id);

        // Debug incoming request data
        \Log::info('Update Client Request:', [
            'request_data' => $request->all(),
            'client_id' => $customer->id
        ]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:255',
            'owner_number' => 'nullable|string|max:255',
            'gps_coordinates' => 'nullable|string|max:255',
            'description' => 'nullable|string',
        ]);

        $customer->update($validated);

        return redirect()->back()->with('success', 'Client mis à jour avec succès');
    }

    public function destroy(Customer $client)
    {
        try {
            $customer = $client;
            if ($customer->ventes()->exists()) {
                return back()->with('error', 'Impossible de supprimer ce client car il a des ventes associées');
            }

            $customer->delete();
            return back()->with('success', 'Client supprimé avec succès');
        } catch (\Exception $e) {
            return back()->with('error', 'Erreur lors de la suppression du client');
        }
    }
} 