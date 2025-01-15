<?php

namespace App\Http\Controllers;

use App\Models\Zone;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ZoneController extends Controller
{
    public function index()
    {
        return Inertia::render('Zones/Index', [
            'zones' => Zone::latest()->get()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'ville' => 'required|string|max:255',
            'quartiers' => 'required|string',
            'gps_coordinates' => 'required|string',
        ]);

        Zone::create($validated);

        return redirect()->back()->with('success', 'Zone créée avec succès');
    }

    public function update(Request $request, Zone $zone)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'ville' => 'required|string|max:255',
            'quartiers' => 'required|string',
            'gps_coordinates' => 'required|string',
        ]);

        $zone->update($validated);

        return redirect()->back()->with('success', 'Zone mise à jour avec succès');
    }

    public function destroy(Zone $zone)
    {
        try {
            if ($zone->lignes()->exists()) {
                return back()->with('error', 'Impossible de supprimer cette zone car elle contient des lignes');
            }

            $zone->delete();
            return back()->with('success', 'Zone supprimée avec succès');
        } catch (\Exception $e) {
            return back()->with('error', 'Erreur lors de la suppression de la zone');
        }
    }

    public function lignes(Zone $zone)
    {
        return response()->json($zone->lignes()->with('customers')->get());
    }
} 