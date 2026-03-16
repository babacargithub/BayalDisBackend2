<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VehicleController extends Controller
{
    public function index(): Response
    {
        $vehicles = Vehicle::query()
            ->orderBy('name')
            ->get()
            ->map(function (Vehicle $vehicle): array {
                return [
                    'id' => $vehicle->id,
                    'name' => $vehicle->name,
                    'plate_number' => $vehicle->plate_number,
                    'insurance_monthly' => $vehicle->insurance_monthly,
                    'maintenance_monthly' => $vehicle->maintenance_monthly,
                    'repair_reserve_monthly' => $vehicle->repair_reserve_monthly,
                    'depreciation_monthly' => $vehicle->depreciation_monthly,
                    'driver_salary_monthly' => $vehicle->driver_salary_monthly,
                    'working_days_per_month' => $vehicle->working_days_per_month,
                    'estimated_daily_fuel_consumption' => $vehicle->estimated_daily_fuel_consumption,
                    'notes' => $vehicle->notes,
                    'total_monthly_fixed_cost' => $vehicle->total_monthly_fixed_cost,
                    'daily_fixed_cost' => $vehicle->daily_fixed_cost,
                ];
            });

        return Inertia::render('Vehicles/Index', [
            'vehicles' => $vehicles,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'plate_number' => 'nullable|string|max:255',
            'insurance_monthly' => 'required|integer|min:0',
            'maintenance_monthly' => 'required|integer|min:0',
            'repair_reserve_monthly' => 'required|integer|min:0',
            'depreciation_monthly' => 'required|integer|min:0',
            'driver_salary_monthly' => 'required|integer|min:0',
            'working_days_per_month' => 'required|integer|min:1|max:31',
            'estimated_daily_fuel_consumption' => 'required|integer|min:0',
            'notes' => 'nullable|string',
        ]);

        Vehicle::query()->create($validated);

        return redirect()->back()
            ->with('success', 'Véhicule créé avec succès');
    }

    public function update(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'plate_number' => 'nullable|string|max:255',
            'insurance_monthly' => 'required|integer|min:0',
            'maintenance_monthly' => 'required|integer|min:0',
            'repair_reserve_monthly' => 'required|integer|min:0',
            'depreciation_monthly' => 'required|integer|min:0',
            'driver_salary_monthly' => 'required|integer|min:0',
            'working_days_per_month' => 'required|integer|min:1|max:31',
            'estimated_daily_fuel_consumption' => 'required|integer|min:0',
            'notes' => 'nullable|string',
        ]);

        $vehicle->update($validated);

        return redirect()->back()
            ->with('success', 'Véhicule mis à jour avec succès');
    }

    public function destroy(Vehicle $vehicle): RedirectResponse
    {
        if ($vehicle->carLoads()->exists()) {
            return redirect()->back()
                ->with('error', 'Impossible de supprimer ce véhicule car il est associé à des chargements.');
        }

        $vehicle->delete();

        return redirect()->back()
            ->with('success', 'Véhicule supprimé avec succès');
    }
}
