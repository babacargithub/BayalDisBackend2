<?php

namespace App\Http\Controllers;

use App\Models\CarLoad;
use App\Models\CarLoadFuelEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CarLoadFuelEntryController extends Controller
{
    public function store(Request $request, CarLoad $carLoad): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => 'required|integer|min:1',
            'liters' => 'nullable|numeric|min:0',
            'filled_at' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $carLoad->fuelEntries()->create($validated);

        return redirect()->back()
            ->with('success', 'Plein de carburant ajouté avec succès');
    }

    public function destroy(CarLoad $carLoad, CarLoadFuelEntry $fuelEntry): RedirectResponse
    {
        $fuelEntry->delete();

        return redirect()->back()
            ->with('success', 'Plein de carburant supprimé avec succès');
    }
}
