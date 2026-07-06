<?php

namespace App\Http\Controllers;

use App\Enums\GoalAssigneeType;
use App\Enums\GoalMetric;
use App\Models\Goal;
use App\Services\GoalPresenterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GoalController extends Controller
{
    public function __construct(
        private readonly GoalPresenterService $goalPresenterService,
    ) {}

    public function index(Request $request): Response
    {
        $scope = $request->query('scope', 'company');
        $selectedCommercialId = $request->query('commercial_id') ? (int) $request->query('commercial_id') : null;

        $goalsQuery = Goal::query()
            ->with(['commercial'])
            ->withCount('childGoals')
            ->whereNull('parent_goal_id')
            ->orderBy('period_end', 'desc');

        if ($scope === 'commercial') {
            $goalsQuery->where('assignee_type', GoalAssigneeType::Commercial);
            if ($selectedCommercialId !== null) {
                $goalsQuery->where('assignee_id', $selectedCommercialId);
            }
        } else {
            $goalsQuery->where('assignee_type', GoalAssigneeType::Company);
        }

        return Inertia::render('Goals/Index', [
            'goals' => $this->goalPresenterService->computeAndSerializeGoals($goalsQuery->get()),
            'commerciaux' => $this->goalPresenterService->serializeCommerciauxList(),
            'availableMetrics' => $this->goalPresenterService->serializeAvailableMetrics(),
            'selectedCommercialId' => $selectedCommercialId,
            'currentScope' => $scope,
        ]);
    }

    public function show(Goal $goal): Response
    {
        $goal->load(['commercial', 'childGoals.commercial']);

        $childGoals = $goal->childGoals->loadCount('childGoals');

        return Inertia::render('Goals/Show', [
            'parentGoal' => $this->goalPresenterService->computeAndSerializeGoal($goal),
            'childGoals' => $this->goalPresenterService->computeAndSerializeGoals($childGoals),
            'commerciaux' => $this->goalPresenterService->serializeCommerciauxList(),
            'availableMetrics' => $this->goalPresenterService->serializeAvailableMetrics(),
            'linkableGoals' => $this->goalPresenterService->serializeLinkableGoals($goal),
        ]);
    }

    public function attachChild(Goal $goal, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'child_goal_id' => [
                'required',
                'exists:goals,id',
                function (string $_attribute, mixed $value, \Closure $fail) use ($goal): void {
                    if ((int) $value === $goal->id) {
                        $fail('Un objectif ne peut pas être son propre sous-objectif.');
                    }

                    $childGoal = Goal::find($value);
                    if ($childGoal && $childGoal->parent_goal_id !== null) {
                        $fail('Cet objectif est déjà rattaché à un objectif parent.');
                    }
                },
            ],
        ]);

        Goal::where('id', $validated['child_goal_id'])->update([
            'parent_goal_id' => $goal->id,
        ]);

        return redirect()->route('goals.show', $goal);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'assignee_type' => 'required|in:commercial,team,company',
            'assignee_id' => 'nullable|integer',
            'metric' => 'required|string|in:'.implode(',', array_column(GoalMetric::cases(), 'value')),
            'target_value' => 'required|numeric|min:0.01',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'parent_goal_id' => 'nullable|exists:goals,id',
        ]);

        $newGoal = Goal::create([
            ...$validated,
            'created_by' => $request->user()->id,
        ]);

        if ($newGoal->parent_goal_id !== null) {
            return redirect()->route('goals.show', $newGoal->parent_goal_id)
                ->with('success', 'Sous-objectif créé avec succès.');
        }

        return redirect()->route('goals.index')
            ->with('success', 'Objectif créé avec succès.');
    }

    public function detach(Goal $goal): RedirectResponse
    {
        $parentGoalId = $goal->parent_goal_id;

        Goal::where('id', $goal->id)->update(['parent_goal_id' => null]);

        return redirect()->route('goals.show', $parentGoalId)
            ->with('success', 'Sous-objectif délié avec succès.');
    }

    public function destroy(Goal $goal): RedirectResponse
    {
        $goal->delete();

        return back()->with('success', 'Objectif supprimé.');
    }
}
