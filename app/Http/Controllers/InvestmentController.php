<?php

namespace App\Http\Controllers;

use App\Models\Investment;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvestmentController extends Controller
{
    public function index(): Response
    {
        $investments = Investment::latest()
            ->get()
            ->map(function ($investment) {
                return [
                    'id' => $investment->id,
                    'title' => $investment->title,
                    'comment' => $investment->comment,
                    'amount' => $investment->amount,
                    'created_at' => $investment->created_at,
                ];
            });

        $totalInvestment = $investments->sum('amount');

        return Inertia::render('Investments/Index', [
            'investments' => $investments,
            'totalInvestment' => $totalInvestment,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'comment' => ['nullable', 'string'],
            'amount' => ['required', 'integer', 'min:0'],
        ], [
            'title.required' => 'Le titre est obligatoire',
            'title.max' => 'Le titre ne doit pas dépasser 255 caractères',
            'amount.required' => 'Le montant est obligatoire',
            'amount.integer' => 'Le montant doit être un nombre entier',
            'amount.min' => 'Le montant doit être positif',
        ]);

        Investment::create($validated);

        return back()->with('success', 'Investissement ajouté avec succès');
    }

    public function update(Request $request, Investment $investment)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'comment' => ['nullable', 'string'],
            'amount' => ['required', 'integer', 'min:0'],
        ], [
            'title.required' => 'Le titre est obligatoire',
            'title.max' => 'Le titre ne doit pas dépasser 255 caractères',
            'amount.required' => 'Le montant est obligatoire',
            'amount.integer' => 'Le montant doit être un nombre entier',
            'amount.min' => 'Le montant doit être positif',
        ]);

        $investment->update($validated);

        return back()->with('success', 'Investissement mis à jour avec succès');
    }

    public function destroy(Investment $investment)
    {
        $investment->delete();

        return back()->with('success', 'Investissement supprimé avec succès');
    }
} 