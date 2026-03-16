<?php

namespace App\Http\Controllers;

use App\Enums\MonthlyFixedCostPool;
use App\Enums\MonthlyFixedCostSubCategory;
use App\Models\Commercial;
use App\Models\MonthlyFixedCost;
use App\Models\Vehicle;
use App\Services\Abc\AbcCostSummaryService;
use App\Services\Abc\AbcFixedCostDistributionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MonthlyFixedCostController extends Controller
{
    public function __construct(
        private readonly AbcFixedCostDistributionService $abcFixedCostDistributionService,
        private readonly AbcCostSummaryService $abcCostSummaryService,
    ) {}

    public function index(Request $request): Response
    {
        $year = (int) $request->integer('year', now()->year);
        $month = (int) $request->integer('month', now()->month);

        $costs = MonthlyFixedCost::query()
            ->orderBy('period_year', 'desc')
            ->orderBy('period_month', 'desc')
            ->orderBy('cost_pool')
            ->orderBy('sub_category')
            ->get()
            ->map(function (MonthlyFixedCost $cost): array {
                return [
                    'id' => $cost->id,
                    'cost_pool' => $cost->cost_pool->value,
                    'cost_pool_label' => $cost->cost_pool->label(),
                    'sub_category' => $cost->sub_category->value,
                    'sub_category_label' => $cost->sub_category->label(),
                    'amount' => $cost->amount,
                    'label' => $cost->label,
                    'period_year' => $cost->period_year,
                    'period_month' => $cost->period_month,
                    'per_vehicle_amount' => $cost->per_vehicle_amount,
                    'active_vehicle_count' => $cost->active_vehicle_count,
                    'finalized_at' => $cost->finalized_at?->toISOString(),
                    'notes' => $cost->notes,
                ];
            });

        $pools = collect(MonthlyFixedCostPool::cases())->map(fn (MonthlyFixedCostPool $pool): array => [
            'value' => $pool->value,
            'label' => $pool->label(),
        ]);

        $subCategories = collect(MonthlyFixedCostSubCategory::cases())->map(fn (MonthlyFixedCostSubCategory $subCategory): array => [
            'value' => $subCategory->value,
            'label' => $subCategory->label(),
            'pool' => $subCategory->pool()->value,
        ]);

        $commerciaux = Commercial::query()
            ->orderBy('name')
            ->get(['id', 'name', 'salary'])
            ->map(fn (Commercial $commercial): array => [
                'id' => $commercial->id,
                'name' => $commercial->name,
                'salary' => $commercial->salary,
            ]);

        $vehicles = Vehicle::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Vehicle $vehicle): array => [
                'id' => $vehicle->id,
                'name' => $vehicle->name,
                'plate_number' => $vehicle->plate_number,
                'insurance_monthly' => $vehicle->insurance_monthly,
                'maintenance_monthly' => $vehicle->maintenance_monthly,
                'repair_reserve_monthly' => $vehicle->repair_reserve_monthly,
                'depreciation_monthly' => $vehicle->depreciation_monthly,
                'driver_salary_monthly' => $vehicle->driver_salary_monthly,
                'working_days_per_month' => $vehicle->working_days_per_month,
                'total_monthly_fixed_cost' => $vehicle->total_monthly_fixed_cost,
                'daily_fixed_cost' => $vehicle->daily_fixed_cost,
            ]);

        $costSummary = $this->abcCostSummaryService->computeForPeriod($year, $month);

        return Inertia::render('Abc/MonthlyFixedCosts/Index', [
            'costs' => $costs,
            'pools' => $pools,
            'subCategories' => $subCategories,
            'commerciaux' => $commerciaux,
            'vehicles' => $vehicles,
            'costSummary' => $costSummary->toArray(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'cost_pool' => ['required', Rule::enum(MonthlyFixedCostPool::class)],
            'sub_category' => ['required', Rule::enum(MonthlyFixedCostSubCategory::class)],
            'amount' => 'required|integer|min:1',
            'label' => 'nullable|string|max:255',
            'period_year' => 'required|integer|min:2020|max:2100',
            'period_month' => 'required|integer|min:1|max:12',
            'notes' => 'nullable|string',
        ]);

        MonthlyFixedCost::query()->create($validated);

        return redirect()->back()
            ->with('success', 'Coût fixe ajouté avec succès');
    }

    public function update(Request $request, MonthlyFixedCost $monthlyFixedCost): RedirectResponse
    {
        if ($monthlyFixedCost->isFinalized()) {
            return redirect()->back()
                ->with('error', 'Impossible de modifier un coût fixe finalisé.');
        }

        $validated = $request->validate([
            'cost_pool' => ['required', Rule::enum(MonthlyFixedCostPool::class)],
            'sub_category' => ['required', Rule::enum(MonthlyFixedCostSubCategory::class)],
            'amount' => 'required|integer|min:1',
            'label' => 'nullable|string|max:255',
            'period_year' => 'required|integer|min:2020|max:2100',
            'period_month' => 'required|integer|min:1|max:12',
            'notes' => 'nullable|string',
        ]);

        $monthlyFixedCost->update($validated);

        return redirect()->back()
            ->with('success', 'Coût fixe mis à jour avec succès');
    }

    public function destroy(MonthlyFixedCost $monthlyFixedCost): RedirectResponse
    {
        if ($monthlyFixedCost->isFinalized()) {
            return redirect()->back()
                ->with('error', 'Impossible de supprimer un coût fixe finalisé.');
        }

        $monthlyFixedCost->delete();

        return redirect()->back()
            ->with('success', 'Coût fixe supprimé avec succès');
    }

    public function finalizeMonth(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'year' => 'required|integer|min:2020|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $this->abcFixedCostDistributionService->finalizeMonth(
            year: $validated['year'],
            month: $validated['month'],
        );

        return redirect()->back()
            ->with('success', 'Mois finalisé avec succès');
    }
}
