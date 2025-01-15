<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Commercial;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CustomerController extends Controller
{
    public function index()
    {
        return Inertia::render('Clients/Index', [
            'clients' => Customer::with(['commercial', 'ventes'])->latest()->get(),
            'commerciaux' => Commercial::all()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => 'required|string',
            'owner_number' => 'required|string',
            'gps_coordinates' => 'required|string',
            'commercial_id' => 'required|exists:commercials,id',
        ]);

        Customer::create($validated);

        return redirect()->back()->with('success', 'Client ajouté avec succès');
    }

    public function update(Request $request, $id)
    {
        $client = Customer::findOrFail($id);

        // Debug incoming request data
        \Log::info('Update Client Request:', [
            'request_data' => $request->all(),
            'client_id' => $client->id
        ]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => 'required|string',
            'owner_number' => 'required|string',
            'gps_coordinates' => 'required|string',
            'commercial_id' => 'required|exists:commercials,id',
        ]);

        try {
            // Debug validated data
            \Log::info('Validated data:', $validated);

            // Check if client exists before update
            \Log::info('Client before update:', $client->toArray());

            $client->update($validated);

            // Verify the update
            $client->refresh();
            \Log::info('Client after update:', $client->toArray());

            return redirect()->back()->with('success', 'Client mis à jour avec succès');
        } catch (\Exception $e) {
            \Log::error('Update failed:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()->back()->with('error', 'Erreur lors de la mise à jour du client: ' . $e->getMessage());
        }
    }

    public function destroy(Customer $client)
    {
        try {
            // Log the delete attempt
            \Log::info('Attempting to delete client:', [
                'client_id' => $client->id,
                'client_name' => $client->name
            ]);

            // Check if client has related ventes
            if ($client->ventes()->exists()) {
                \Log::warning('Cannot delete client - has related ventes:', [
                    'client_id' => $client->id,
                    'ventes_count' => $client->ventes()->count()
                ]);
                return back()->with('error', 'Impossible de supprimer ce client car il a des ventes associées');
            }

            $client->delete();
            \Log::info('Client deleted successfully:', [
                'client_id' => $client->id
            ]);
            
            return back()->with('success', 'Client supprimé avec succès');
        } catch (\Exception $e) {
            \Log::error('Error deleting client:', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return back()->with('error', 'Erreur lors de la suppression du client: ' . $e->getMessage());
        }
    }
} 