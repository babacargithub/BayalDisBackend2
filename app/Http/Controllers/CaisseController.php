<?php

namespace App\Http\Controllers;

use App\Models\Caisse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;

class CaisseController extends Controller
{
    public function index()
    {
        return Inertia::render('Caisse/Index', [
            'caisses' => Caisse::all()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'balance' => 'required|integer|min:0',
            'closed' => 'boolean'
        ]);

        Caisse::create($validated);

        return redirect()->route('caisses.index')->with('success', 'Caisse créée avec succès');
    }

    public function update(Request $request, Caisse $caisse)
    {
        try {
            Log::info('Updating caisse', [
                'caisse_id' => $caisse->id,
                'request_data' => $request->all()
            ]);

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'balance' => 'required|integer|min:0',
                'closed' => 'boolean'
            ]);

            $caisse->update($validated);

            Log::info('Caisse updated successfully', [
                'caisse_id' => $caisse->id,
                'updated_data' => $validated
            ]);

            return redirect()->route('caisses.index')->with('success', 'Caisse mise à jour avec succès');
        } catch (\Exception $e) {
            Log::error('Error updating caisse', [
                'caisse_id' => $caisse->id,
                'error' => $e->getMessage()
            ]);

            return redirect()->back()
                ->withErrors(['error' => 'Erreur lors de la mise à jour de la caisse: ' . $e->getMessage()])
                ->withInput();
        }
    }

    public function destroy(Caisse $caisse)
    {
        try {
            $caisse->delete();
            return redirect()->route('caisses.index')->with('success', 'Caisse supprimée avec succès');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Erreur lors de la suppression de la caisse']);
        }
    }
} 