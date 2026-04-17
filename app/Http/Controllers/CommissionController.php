<?php

namespace App\Http\Controllers;

use App\Data\Commission\CommissionPeriodData;
use App\Models\Commercial;
use App\Models\CommercialCategoryCommissionRate;
use App\Models\CommercialNewCustomerCommissionSetting;
use App\Models\CommercialObjectiveTier;
use App\Models\CommercialPenalty;
use App\Models\CommercialWorkPeriod;
use App\Models\DailyCommission;
use App\Models\ProductCategory;
use App\Services\Commission\DailyCommissionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class CommissionController extends Controller
{
    public function __construct(
        private readonly DailyCommissionService $dailyCommissionService,
    ) {}

    public function index(): Response
    {
        $productCategories = ProductCategory::query()->orderBy('name')->get(['id', 'name', 'commission_rate'])
            ->map(fn (ProductCategory $productCategory): array => [
                'id' => $productCategory->id,
                'name' => $productCategory->name,
                'commission_rate' => $productCategory->commission_rate !== null ? (float) $productCategory->commission_rate : null,
            ]);

        $commerciaux = Commercial::query()->orderBy('name')->with('newCustomerCommissionSetting')->get();

        $commerciauxData = $commerciaux->map(fn (Commercial $commercial): array => [
            'id' => $commercial->id,
            'name' => $commercial->name,
        ]);

        $newCustomerCommissionSettings = $commerciaux->map(fn (Commercial $commercial): array => [
            'commercial_id' => $commercial->id,
            'confirmed_customer_bonus' => $commercial->newCustomerCommissionSetting?->confirmed_customer_bonus ?? 0,
            'prospect_customer_bonus' => $commercial->newCustomerCommissionSetting?->prospect_customer_bonus ?? 0,
        ]);

        $categoryRates = CommercialCategoryCommissionRate::query()->get()
            ->map(fn (CommercialCategoryCommissionRate $rate): array => [
                'id' => $rate->id,
                'commercial_id' => $rate->commercial_id,
                'product_category_id' => $rate->product_category_id,
                'rate' => (float) $rate->rate,
            ]);

        $workPeriods = CommercialWorkPeriod::query()
            ->with(['commercial', 'dailyCommissions', 'objectiveTiers', 'penalties'])
            ->orderByDesc('period_start_date')
            ->get()
            ->map(fn (CommercialWorkPeriod $workPeriod): array => [
                'id' => $workPeriod->id,
                'commercial_id' => $workPeriod->commercial_id,
                'commercial_name' => $workPeriod->commercial->name,
                'period_start_date' => $workPeriod->period_start_date->toDateString(),
                'period_end_date' => $workPeriod->period_end_date->toDateString(),
                'is_finalized' => $workPeriod->is_finalized,
                'finalized_at' => $workPeriod->finalized_at?->toISOString(),
                'daily_commissions' => $workPeriod->dailyCommissions
                    ->sortBy('work_day')
                    ->map(fn (DailyCommission $dailyCommission): array => [
                        'id' => $dailyCommission->id,
                        'work_day' => $dailyCommission->work_day->toDateString(),
                        'base_commission' => $dailyCommission->base_commission,
                        'basket_bonus' => $dailyCommission->basket_bonus,
                        'objective_bonus' => $dailyCommission->objective_bonus,
                        'total_penalties' => $dailyCommission->total_penalties,
                        'new_confirmed_customers_bonus' => $dailyCommission->new_confirmed_customers_bonus,
                        'new_prospect_customers_bonus' => $dailyCommission->new_prospect_customers_bonus,
                        'mandatory_daily_threshold' => $dailyCommission->mandatory_daily_threshold,
                        'mandatory_threshold_reached' => $dailyCommission->mandatory_threshold_reached,
                        'cached_average_margin_rate' => $dailyCommission->cached_average_margin_rate !== null
                            ? (float) $dailyCommission->cached_average_margin_rate
                            : null,
                        'net_commission' => $dailyCommission->net_commission,
                        'basket_achieved' => $dailyCommission->basket_achieved,
                        'achieved_tier_level' => $dailyCommission->achieved_tier_level,
                    ])->values(),
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
                        'work_day' => $penalty->work_day?->toDateString(),
                        'created_at' => $penalty->created_at->toDateString(),
                    ])->values(),
            ]);

        $globalObjectiveTiers = CommercialObjectiveTier::global()
            ->orderBy('tier_level')
            ->get()
            ->map(fn (CommercialObjectiveTier $tier): array => [
                'id' => $tier->id,
                'tier_level' => $tier->tier_level,
                'ca_threshold' => $tier->ca_threshold,
                // TODO make this dynamic later
                'is_mandatory' => false,
                'bonus_amount' => $tier->bonus_amount,
            ])->values();

        return Inertia::render('Commissions/Index', [
            'productCategories' => $productCategories,
            'commerciaux' => $commerciauxData,
            'categoryRates' => $categoryRates,
            'workPeriods' => $workPeriods,
            'newCustomerCommissionSettings' => $newCustomerCommissionSettings,
            'globalObjectiveTiers' => $globalObjectiveTiers,
        ]);
    }

    // ─── Général tab — New customer commission settings ─────────────────────────

    public function upsertNewCustomerCommissionSetting(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'commercial_id' => 'required|exists:commercials,id',
            'confirmed_customer_bonus' => 'required|integer|min:0',
            'prospect_customer_bonus' => 'required|integer|min:0',
        ]);

        CommercialNewCustomerCommissionSetting::updateOrCreate(
            ['commercial_id' => $validated['commercial_id']],
            [
                'confirmed_customer_bonus' => $validated['confirmed_customer_bonus'],
                'prospect_customer_bonus' => $validated['prospect_customer_bonus'],
            ],
        );

        return redirect()->back()->with('success', 'Paramètres nouveaux clients mis à jour.');
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
        if ($workPeriod->is_finalized) {
            return redirect()->back()
                ->withErrors(['error' => 'Impossible de supprimer une période finalisée.']);
        }

        $workPeriod->delete();

        return redirect()->back()->with('success', 'Période supprimée.');
    }

    // ─── Global objective tiers ────────────────────────────────────────────────

    public function storeGlobalTier(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tier_level' => 'required|integer|min:1',
            'ca_threshold' => 'required|integer|min:0',
            'bonus_amount' => 'required|integer|min:0',
        ]);

        $tierLevelAlreadyExists = CommercialObjectiveTier::global()
            ->where('tier_level', $validated['tier_level'])
            ->exists();

        if ($tierLevelAlreadyExists) {
            return redirect()->back()
                ->withErrors(['error' => 'Un palier global de ce niveau existe déjà.']);
        }

        CommercialObjectiveTier::create([
            'commercial_work_period_id' => null,
            'is_global' => true,
            'tier_level' => $validated['tier_level'],
            'ca_threshold' => $validated['ca_threshold'],
            'bonus_amount' => $validated['bonus_amount'],
        ]);

        return redirect()->back()->with('success', 'Palier global créé.');
    }

    public function updateGlobalTier(Request $request, CommercialObjectiveTier $tier): RedirectResponse
    {
        if (! $tier->is_global) {
            return redirect()->back()
                ->withErrors(['error' => 'Ce palier n\'est pas un palier global.']);
        }

        $validated = $request->validate([
            'tier_level' => 'required|integer|min:1',
            'ca_threshold' => 'required|integer|min:0',
            'bonus_amount' => 'required|integer|min:0',
        ]);

        $tierLevelTakenByAnotherGlobalTier = CommercialObjectiveTier::global()
            ->where('tier_level', $validated['tier_level'])
            ->where('id', '!=', $tier->id)
            ->exists();

        if ($tierLevelTakenByAnotherGlobalTier) {
            return redirect()->back()
                ->withErrors(['error' => 'Un autre palier global de ce niveau existe déjà.']);
        }

        $tier->update($validated);

        return redirect()->back()->with('success', 'Palier global mis à jour.');
    }

    public function destroyGlobalTier(CommercialObjectiveTier $tier): RedirectResponse
    {
        if (! $tier->is_global) {
            return redirect()->back()
                ->withErrors(['error' => 'Ce palier n\'est pas un palier global.']);
        }

        $tier->delete();

        return redirect()->back()->with('success', 'Palier global supprimé.');
    }

    // ─── Objective tiers ───────────────────────────────────────────────────────

    public function storeTier(Request $request, CommercialWorkPeriod $workPeriod): RedirectResponse
    {
        if ($workPeriod->is_finalized) {
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
        if ($tier->workPeriod->is_finalized) {
            return redirect()->back()
                ->withErrors(['error' => 'Impossible de modifier une période finalisée.']);
        }

        $tier->delete();

        return redirect()->back()->with('success', 'Palier supprimé.');
    }

    // ─── Penalties ─────────────────────────────────────────────────────────────

    /**
     * @throws Throwable
     */
    public function storePenalty(Request $request, CommercialWorkPeriod $workPeriod): RedirectResponse
    {
        if ($workPeriod->is_finalized) {
            return redirect()->back()
                ->withErrors(['error' => 'Impossible de modifier une période finalisée.']);
        }

        $validated = $request->validate([
            'amount' => 'required|integer|min:1',
            'reason' => 'required|string|max:500',
            'work_day' => 'required|date',
        ]);

        $workPeriod->penalties()->create($validated);

        // Trigger a recalculation of the daily commission for the affected day.
        $this->dailyCommissionService->recalculateDailyCommissionForWorkDay(
            $workPeriod->commercial,
            $workPeriod,
            $validated['work_day'],
        );

        return redirect()->back()->with('success', 'Pénalité ajoutée.');
    }

    public function destroyPenalty(CommercialPenalty $penalty): RedirectResponse
    {
        if ($penalty->workPeriod->is_finalized) {
            return redirect()->back()
                ->withErrors(['error' => 'Impossible de modifier une période finalisée.']);
        }

        $affectedWorkDay = $penalty->work_day?->toDateString();
        $workPeriod = $penalty->workPeriod;
        $penalty->delete();

        // Trigger a recalculation of the daily commission for the affected day.
        if ($affectedWorkDay !== null) {
            $this->dailyCommissionService->recalculateDailyCommissionForWorkDay(
                $workPeriod->commercial,
                $workPeriod,
                $affectedWorkDay,
            );
        }

        return redirect()->back()->with('success', 'Pénalité supprimée.');
    }

    // ─── Commission computation & finalization ──────────────────────────────────

    public function computeForWorkPeriod(CommercialWorkPeriod $workPeriod): RedirectResponse
    {
        try {
            $this->dailyCommissionService->recomputeAllDaysForWorkPeriod($workPeriod);
        } catch (RuntimeException|Throwable $runtimeException) {
            return redirect()->back()->withErrors(['error' => $runtimeException->getMessage()]);
        }

        return redirect()->back()->with('success', 'Commissions journalières recalculées.');
    }

    public function recomputeCommissionsForWorkDay(CommercialWorkPeriod $workPeriod, string $workDay): RedirectResponse
    {
        try {
            $this->dailyCommissionService->recalculateDailyCommissionForWorkDay(
                $workPeriod->commercial,
                $workPeriod,
                $workDay,
            );
        } catch (RuntimeException|Throwable $runtimeException) {
            return redirect()->back()->withErrors(['error' => $runtimeException->getMessage()]);
        }

        return redirect()->back()->with('success', 'Commissions journalières recalculées.');
    }

    public function finalizeWorkPeriod(CommercialWorkPeriod $workPeriod): RedirectResponse
    {
        try {
            $this->dailyCommissionService->finalizeWorkPeriod($workPeriod);
        } catch (RuntimeException $runtimeException) {
            return redirect()->back()->withErrors(['error' => $runtimeException->getMessage()]);
        }

        return redirect()->back()->with('success', 'Période finalisée avec succès.');
    }
}
