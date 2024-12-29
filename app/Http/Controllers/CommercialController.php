<?php

namespace App\Http\Controllers;

use App\Models\Commercial;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;

class CommercialController extends Controller
{
    public function index()
    {
        $commerciaux = Commercial::with('clients')
            ->withCount('ventes')
            ->withSum('ventes', DB::raw('price * quantity'))
            ->withCount(['ventes as ventes_impayees_count' => function ($query) {
                $query->where('paid', false);
            }])
            ->get();

        return Inertia::render('Commercials/Index', [
            'commerciaux' => $commerciaux,
            'statistics' => [
                'total_commerciaux' => $commerciaux->count(),
                'total_clients' => $commerciaux->sum(fn($c) => $c->clients->count()),
                'moyenne_ventes' => $commerciaux->avg('ventes_count'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => 'required|string|unique:commercials',
            'gender' => 'required|in:male,female',
        ]);

        Commercial::create($validated);

        return redirect()->back()->with('success', 'Commercial ajouté avec succès');
    }

    public function update(Request $request, Commercial $commercial)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => 'required|string|unique:commercials,phone_number,' . $commercial->id,
            'gender' => 'required|in:male,female',
        ]);

        $commercial->update($validated);

        return redirect()->back()->with('success', 'Commercial mis à jour avec succès');
    }

    public function destroy(Commercial $commercial)
    {
        $commercial->delete();
        return redirect()->back()->with('success', 'Commercial supprimé avec succès');
    }
} 