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
        $customer = Customer::findOrFail($id);

        // Debug incoming request data
        \Log::info('Update Customer Request:', [
            'request_data' => $request->all(),
            'customer_id' => $customer->id
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

            // Check if customer exists before update
            \Log::info('Customer before update:', $customer->toArray());

            $customer->update($validated);

            // Verify the update
            $customer->refresh();
            \Log::info('Customer after update:', $customer->toArray());

            return redirect()->back()->with('success', 'Client mis à jour avec succès');
        } catch (\Exception $e) {
            \Log::error('Update failed:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()->back()->with('error', 'Erreur lors de la mise à jour du client: ' . $e->getMessage());
        }
    }

    public function destroy(Customer $customer)
    {
        try {
            // Log the delete attempt
            \Log::info('Attempting to delete customer:', [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name
            ]);

            // Check if customer has related ventes
            if ($customer->ventes()->exists()) {
                \Log::warning('Cannot delete customer - has related ventes:', [
                    'customer_id' => $customer->id,
                    'ventes_count' => $customer->ventes()->count()
                ]);
                return redirect()->back()->with('error', 'Impossible de supprimer ce client car il a des ventes associées');
            }

            $customer->delete();
            \Log::info('Customer deleted successfully:', [
                'customer_id' => $customer->id
            ]);
            
            return redirect()->back()->with('success', 'Client supprimé avec succès');
        } catch (\Exception $e) {
            \Log::error('Error deleting customer:', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()->back()->with('error', 'Erreur lors de la suppression du client: ' . $e->getMessage());
        }
    }
} 