<?php

namespace App\Http\Controllers;

use App\Models\PricingPolicy;
use App\Services\PricingPolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PricingPolicyController extends Controller
{
    public function __construct(private PricingPolicyService $pricingPolicyService) {}

    public function index(): Response
    {
        $pricingPolicies = PricingPolicy::query()
            ->orderByDesc('active')
            ->orderBy('name')
            ->get();

        return Inertia::render('PricingPolicies/Index', [
            'pricingPolicies' => $pricingPolicies,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'surcharge_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'grace_days' => ['required', 'integer', 'min:0'],
            'apply_to_deferred_only' => ['required', 'boolean'],
            'apply_credit_price' => ['required', 'boolean'],
        ], [
            'name.required' => 'Le nom est obligatoire',
            'name.max' => 'Le nom ne doit pas dépasser 255 caractères',
            'surcharge_percent.required' => 'Le taux de majoration est obligatoire',
            'surcharge_percent.integer' => 'Le taux de majoration doit être un nombre entier',
            'surcharge_percent.min' => 'Le taux de majoration doit être positif',
            'surcharge_percent.max' => 'Le taux de majoration ne peut pas dépasser 100%',
            'grace_days.required' => 'Le nombre de jours de grâce est obligatoire',
            'grace_days.integer' => 'Le nombre de jours de grâce doit être un nombre entier',
            'grace_days.min' => 'Le nombre de jours de grâce doit être positif',
        ]);

        $this->pricingPolicyService->create($validated);

        return back()->with('success', 'Politique de prix créée avec succès');
    }

    public function update(Request $request, PricingPolicy $pricingPolicy): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'surcharge_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'grace_days' => ['required', 'integer', 'min:0'],
            'apply_to_deferred_only' => ['required', 'boolean'],
            'apply_credit_price' => ['required', 'boolean'],
        ], [
            'name.required' => 'Le nom est obligatoire',
            'name.max' => 'Le nom ne doit pas dépasser 255 caractères',
            'surcharge_percent.required' => 'Le taux de majoration est obligatoire',
            'surcharge_percent.integer' => 'Le taux de majoration doit être un nombre entier',
            'surcharge_percent.min' => 'Le taux de majoration doit être positif',
            'surcharge_percent.max' => 'Le taux de majoration ne peut pas dépasser 100%',
            'grace_days.required' => 'Le nombre de jours de grâce est obligatoire',
            'grace_days.integer' => 'Le nombre de jours de grâce doit être un nombre entier',
            'grace_days.min' => 'Le nombre de jours de grâce doit être positif',
        ]);

        $this->pricingPolicyService->update($pricingPolicy, $validated);

        return back()->with('success', 'Politique de prix mise à jour avec succès');
    }

    public function activate(PricingPolicy $pricingPolicy): RedirectResponse
    {
        $this->pricingPolicyService->activate($pricingPolicy);

        return back()->with('success', "Politique \"{$pricingPolicy->name}\" activée avec succès");
    }
}
