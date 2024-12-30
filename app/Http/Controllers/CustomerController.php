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

    public function update(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => 'required|string',
            'owner_number' => 'required|string',
            'gps_coordinates' => 'required|string',
            'commercial_id' => 'required|exists:commercials,id',
        ]);

        $customer->update($validated);

        return redirect()->back()->with('success', 'Client mis à jour avec succès');
    }

    public function destroy(Customer $customer)
    {
        $customer->delete();
        return redirect()->back()->with('success', 'Client supprimé avec succès');
    }
} 