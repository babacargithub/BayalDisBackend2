<?php

namespace App\Http\Controllers\Api;

use App\Enums\CarLoadExpenseType;
use App\Http\Controllers\Controller;
use App\Services\CarLoadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

class ApiCarLoadExpenseController extends Controller
{
    public function __construct(
        private readonly CarLoadService $carLoadService,
    ) {}

    /**
     * List all expenses for the current team's active car load.
     */
    public function index(Request $request): JsonResponse
    {
        $team = $request->user()->commercial->team;
        $currentCarLoad = $this->carLoadService->getCurrentCarLoadForTeam($team);

        if ($currentCarLoad === null) {
            return response()->json(['message' => 'Aucun chargement actif pour votre équipe.'], 404);
        }

        $expenses = $currentCarLoad->expenses()
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($expense) => [
                'id' => $expense->id,
                'label' => $expense->label,
                'amount' => $expense->amount,
                'type' => $expense->type->value,
                'type_label' => $expense->type->label(),
                'created_at' => $expense->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'expenses' => $expenses,
            'total' => $expenses->sum('amount'),
        ]);
    }

    /**
     * Submit a new expense for the current team's active car load.
     */
    public function store(Request $request): JsonResponse
    {
        $team = $request->user()->commercial->team;
        $currentCarLoad = $this->carLoadService->getCurrentCarLoadForTeam($team);

        if ($currentCarLoad === null) {
            return response()->json(['message' => 'Aucun chargement actif pour votre équipe.'], 422);
        }

        $validated = $request->validate([
            'label' => 'nullable|string|max:255',
            'amount' => 'required|integer|min:1',
            'type' => ['required', new Enum(CarLoadExpenseType::class)],
        ]);

        $expense = $currentCarLoad->expenses()->create($validated);

        return response()->json([
            'id' => $expense->id,
            'label' => $expense->label,
            'amount' => $expense->amount,
            'type' => $expense->type->value,
            'type_label' => $expense->type->label(),
            'created_at' => $expense->created_at?->toIso8601String(),
        ], 201);
    }

    /**
     * Delete an expense from the current team's active car load.
     */
    public function destroy(Request $request, int $expenseId): JsonResponse
    {
        $team = $request->user()->commercial->team;
        $currentCarLoad = $this->carLoadService->getCurrentCarLoadForTeam($team);

        if ($currentCarLoad === null) {
            return response()->json(['message' => 'Aucun chargement actif pour votre équipe.'], 404);
        }

        $expense = $currentCarLoad->expenses()->find($expenseId);

        if ($expense === null) {
            return response()->json(['message' => 'Dépense introuvable.'], 404);
        }

        $expense->delete();

        return response()->json(['message' => 'Dépense supprimée avec succès']);
    }
}
