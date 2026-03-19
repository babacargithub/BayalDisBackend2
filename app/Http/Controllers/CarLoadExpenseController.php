<?php

namespace App\Http\Controllers;

use App\Enums\CarLoadExpenseType;
use App\Models\CarLoad;
use App\Models\CarLoadExpense;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

class CarLoadExpenseController extends Controller
{
    public function store(Request $request, CarLoad $carLoad): RedirectResponse
    {
        $validated = $request->validate([
            'label' => 'nullable|string|max:255',
            'amount' => 'required|integer|min:1',
            'type' => ['required', new Enum(CarLoadExpenseType::class)],
        ]);

        $carLoad->expenses()->create($validated);

        return redirect()->back()
            ->with('success', 'Dépense ajoutée avec succès');
    }

    public function destroy(CarLoad $carLoad, CarLoadExpense $expense): RedirectResponse
    {
        $expense->delete();

        return redirect()->back()
            ->with('success', 'Dépense supprimée avec succès');
    }
}
