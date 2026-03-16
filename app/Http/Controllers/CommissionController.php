<?php

namespace App\Http\Controllers;

use App\Data\Commission\CommissionPeriodData;
use App\Models\Commercial;
use App\Models\CommercialCategoryCommissionRate;
use App\Models\CommercialObjectiveTier;
use App\Models\CommercialPenalty;
use App\Models\CommercialWorkPeriod;
use App\Models\Commission;
use App\Models\ProductCategory;
use App\Services\Commission\CommercialWorkPeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class CommissionController extends Controller
{
    public function __construct(
        private readonly CommercialWorkPeriodService $commissionPeriodService,
    ) {}

    public function index(): Response
    {
        $productCategories = ProductCategory::query()->orderBy('name')->get(['id', 'name', 'commission_rate'])
            ->map(fn (ProductCategory $productCategory): array => [
                'id' => $productCategory->id,
                'name' => $productCategory->name,
                'commission_rate' => $productCategory->commission_rate !== null ? (float) $productCategory->commission_rate : null,
            ]);
        $commerciaux = Commercial::query()->orderBy('name')->get(['id', 'name']);

        $categoryRates = CommercialCategoryCommissionRate::query()->get()
            ->map(fn (CommercialCategoryCommissionRate $rate): array => [
                'id' => $rate->id,
                'commercial_id' => $rate->commercial_id,
                'product_category_id' => $rate->product_category_id,
                'rate' => (float) $rate->rate,
            ]);

        $workPeriods = CommercialWorkPeriod::query()
            ->with(['commercial', 'commission', 'objectiveTiers', 'penalties'])
            ->orderByDesc('period_start_date')
            ->get()
            ->map(fn (CommercialWorkPeriod $workPeriod): array => [
                'id' => $workPeriod->id,
                'commercial_id' => $workPeriod->commercial_id,
                'commercial_name' => $workPeriod->commercial->name,
                'period_start_date' => $workPeriod->period_start_date->toDateString(),
                'period_end_date' => $workPeriod->period_end_date->toDateString(),
                'commission' => $workPeriod->commission ? [
                    'id' => $workPeriod->commission->id,
                    'base_commission' => $workPeriod->commission->base_commission,
                    'basket_bonus' => $workPeriod->commission->basket_bonus,
                    'objective_bonus' => $workPeriod->commission->objective_bonus,
                    'total_penalties' => $workPeriod->commission->total_penalties,
                    'net_commission' => $workPeriod->commission->net_commission,
                    'basket_achieved' => $workPeriod->commission->basket_achieved,
                    'achieved_tier_level' => $workPeriod->commission->achieved_tier_level,
                    'is_finalized' => $workPeriod->commission->is_finalized,
                    'finalized_at' => $workPeriod->commission->finalized_at?->toISOString(),
                ] : null,
                'objective_tiers' => $workPeriod->objectiveTiers
                    ->sortBy('tier_level')
                    ->map(fn (CommercialObjectiveTier $tier): array => [
                        'id' => $tier->id,
                        'tier_level' => $tier->tier_level,
                        'ca_threshold' => $tier->ca_threshold,
                        'bonus_amount' => $tier->bonus_amount,
                    ])->values(),
                'penalties' => $workPeriod->penalties
                    ->map(fn (CommercialPenalty $penalty): array => [
                        'id' => $penalty->id,
                        'amount' => $penalty->amount,
                        'reason' => $penalty->reason,
                        'created_at' => $penalty->created_at->toDateString(),
                    ])->values(),
            ]);

        return Inertia::render('Commissions/Index', [
            'productCategories' => $productCategories,
            'commerciaux' => $commerciaux,
            'categoryRates' => $categoryRates,
            'workPeriods' => $workPeriods,
        ]);
    }

    // ─── Général tab — Category commission rates ────────────────────────────────

    public function upsertCategoryRate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'commercial_id' => 'required|exists:commercials,id',
            'product_category_id' => 'required|exists:product_categories,id',
            'rate' => 'required|numeric|min:0|max:1',
        ]);

        CommercialCategoryCommissionRate::updateOrCreate(
            [
                'commercial_id' => $validated['commercial_id'],
                'product_category_id' => $validated['product_category_id'],
            ],
            ['rate' => $validated['rate']],
        );

        return redirect()->back()->with('success', 'Taux de commission mis à jour.');
    }

    public function destroyCategoryRate(CommercialCategoryCommissionRate $categoryRate): RedirectResponse
    {
        $categoryRate->delete();

        return redirect()->back()->with('success', 'Taux supprimé.');
    }

    // ─── Commerciaux tab — Work periods ────────────────────────────────────────

    public function storeWorkPeriod(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'commercial_id' => 'required|exists:commercials,id',
            'period_start_date' => 'required|date',
            'period_end_date' => 'required|date|after_or_equal:period_start_date',
        ]);

        $period = new CommissionPeriodData(
            CarbonImmutable::parse($validated['period_start_date']),
            CarbonImmutable::parse($validated['period_end_date']),
        );

        if (CommercialWorkPeriod::hasOverlappingPeriodForCommercial((int) $validated['commercial_id'], $period)) {
            return redirect()->back()
                ->withErrors(['error' => 'Cette période chevauche une période existante pour ce commercial.']);
        }

        CommercialWorkPeriod::create($validated);

        return redirect()->back()->with('success', 'Période de travail créée avec succès.');
    }

    public function destroyWorkPeriod(CommercialWorkPeriod $workPeriod): RedirectResponse
    {
        if ($workPeriod->commission?->is_finalized) {
            return redirect()->back()
                ->withErrors(['error' => 'Impossible de supprimer une période dont la commission est finalisée.']);
        }

        $workPeriod->delete();

        return redirect()->back()->with('success', 'Période supprimée.');
    }

    // ─── Objective tiers ───────────────────────────────────────────────────────

    public function storeTier(Request $request, CommercialWorkPeriod $workPeriod): RedirectResponse
    {
        if ($workPeriod->commission?->is_finalized) {
            return redirect()->back()
                ->withErrors(['error' => 'Impossible de modifier une période finalisée.']);
        }

        $validated = $request->validate([
            'tier_level' => 'required|integer|min:1',
            'ca_threshold' => 'required|integer|min:0',
            'bonus_amount' => 'required|integer|min:0',
        ]);

        $workPeriod->objectiveTiers()->create($validated);

        return redirect()->back()->with('success', 'Palier objectif créé.');
    }

    public function destroyTier(CommercialObjectiveTier $tier): RedirectResponse
    {
        if ($tier->workPeriod->commission?->is_finalized) {
            return redirect()->back()
                ->withErrors(['error' => 'Impossible de modifier une période finalisée.']);
        }

        $tier->delete();

        return redirect()->back()->with('success', 'Palier supprimé.');
    }

    // ─── Penalties ─────────────────────────────────────────────────────────────

    public function storePenalty(Request $request, CommercialWorkPeriod $workPeriod): RedirectResponse
    {
        if ($workPeriod->commission?->is_finalized) {
            return redirect()->back()
                ->withErrors(['error' => 'Impossible de modifier une période finalisée.']);
        }

        $validated = $request->validate([
            'amount' => 'required|integer|min:1',
            'reason' => 'required|string|max:500',
        ]);

        $workPeriod->penalties()->create($validated);

        return redirect()->back()->with('success', 'Pénalité ajoutée.');
    }

    public function destroyPenalty(CommercialPenalty $penalty): RedirectResponse
    {
        if ($penalty->workPeriod->commission?->is_finalized) {
            return redirect()->back()
                ->withErrors(['error' => 'Impossible de modifier une période finalisée.']);
        }

        $penalty->delete();

        return redirect()->back()->with('success', 'Pénalité supprimée.');
    }

    // ─── Commission computation & finalization ──────────────────────────────────

    public function computeForWorkPeriod(CommercialWorkPeriod $workPeriod): RedirectResponse
    {
        try {
            $period = new CommissionPeriodData(
                CarbonImmutable::parse($workPeriod->period_start_date),
                CarbonImmutable::parse($workPeriod->period_end_date),
            );

            $this->commissionPeriodService->computeOrRefreshCommissionForPeriod(
                $workPeriod->commercial,
                $period,
            );
        } catch (RuntimeException $runtimeException) {
            return redirect()->back()->withErrors(['error' => $runtimeException->getMessage()]);
        }

        return redirect()->back()->with('success', 'Commission calculée avec succès.');
    }

    public function finalizeCommission(Commission $commission): RedirectResponse
    {
        try {
            $this->commissionPeriodService->finalizeCommission($commission);
        } catch (RuntimeException $runtimeException) {
            return redirect()->back()->withErrors(['error' => $runtimeException->getMessage()]);
        }

        return redirect()->back()->with('success', 'Commission finalisée avec succès.');
    }
}
