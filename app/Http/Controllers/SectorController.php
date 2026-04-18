<?php

namespace App\Http\Controllers;

use App\Enums\DayOfWeek;
use App\Models\Beat;
use App\Models\BeatStop;
use App\Models\Customer;
use App\Models\Ligne;
use App\Models\Sector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SectorController extends Controller
{
    public function index(): \Inertia\Response
    {
        return Inertia::render('Clients/Sectors', [
            'sectors' => Sector::with(['ligne', 'customers:id,name,phone_number,sector_id'])->get(),
            'lignes' => Ligne::select('id', 'name')->get(),
            'customers' => Customer::select('id', 'name', 'phone_number')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'boundaries' => 'nullable|string',
            'ligne_id' => 'required|exists:lignes,id',
            'description' => 'nullable|string',
        ]);

        Sector::create($validated);

        return redirect()->back()->with('success', 'Secteur créé avec succès');
    }

    public function update(Request $request, Sector $sector)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'boundaries' => 'nullable|string',
            'ligne_id' => 'required|exists:lignes,id',
            'description' => 'nullable|string',
        ]);

        $sector->update($validated);

        return redirect()->back()->with('success', 'Secteur mis à jour avec succès');
    }

    public function destroy(Sector $sector)
    {
        $sector->delete();

        return redirect()->back()->with('success', 'Secteur supprimé avec succès');
    }

    public function addCustomers(Request $request, Sector $sector)
    {
        $validated = $request->validate([
            'customer_ids' => 'required|array',
            'customer_ids.*' => 'exists:customers,id',
        ]);

        try {
            DB::beginTransaction();

            // Update customers to belong to this sector
            Customer::whereIn('id', $validated['customer_ids'])->update(['sector_id' => $sector->id]);

            DB::commit();

            return redirect()->back()->with('success', 'Clients ajoutés au secteur avec succès');
        } catch (\Exception $e) {
            DB::rollBack();

            return redirect()->back()->with('error', 'Erreur lors de l\'ajout des clients au secteur');
        }
    }

    public function removeCustomer(Sector $sector, Customer $customer)
    {
        $customer->update(['sector_id' => null]);

        return redirect()->back()->with('success', 'Client retiré du secteur avec succès');
    }

    public function getBeats(Sector $sector)
    {
        $beats = $sector->beats()
            ->with(['commercial:id,name'])
            ->withCount(['stops as template_stops_count' => function ($query) {
                $query->whereNull('visit_date');
            }])
            ->latest()
            ->get()
            ->map(function (Beat $beat) {
                return [
                    'id' => $beat->id,
                    'name' => $beat->name,
                    'day_of_week' => $beat->day_of_week?->value,
                    'day_of_week_label' => $beat->day_of_week?->label(),
                    'commercial' => $beat->commercial,
                    'total_stops' => $beat->template_stops_count,
                    'created_at' => $beat->created_at->format('Y-m-d H:i:s'),
                ];
            });

        return response()->json($beats);
    }

    public function createBeat(Request $request, Sector $sector)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'day_of_week' => 'required|string|in:'.implode(',', array_column(DayOfWeek::cases(), 'value')),
            'commercial_id' => 'required|exists:commercials,id',
        ], [
            'name.required' => 'Le nom du beat est obligatoire',
            'name.max' => 'Le nom ne doit pas dépasser 255 caractères',
            'day_of_week.required' => 'Le jour de la semaine est obligatoire',
            'day_of_week.in' => 'Le jour sélectionné n\'est pas valide',
            'commercial_id.required' => 'Le commercial est obligatoire',
            'commercial_id.exists' => 'Le commercial sélectionné n\'existe pas',
        ]);

        DB::beginTransaction();

        $beat = Beat::create([
            'name' => $validated['name'],
            'day_of_week' => $validated['day_of_week'],
            'commercial_id' => $validated['commercial_id'],
            'sector_id' => $sector->id,
        ]);

        // Add all non-prospect customers of the sector as template stops
        $customerIds = $sector->customers()->where('is_prospect', false)->pluck('customers.id');
        foreach ($customerIds as $customerId) {
            $beat->templateStops()->create([
                'customer_id' => $customerId,
                'status' => BeatStop::STATUS_PLANNED,
            ]);
        }

        DB::commit();

        $loadedBeat = $beat->load(['commercial:id,name', 'stops']);
        $totalStops = $loadedBeat->stops->count();

        return response()->json([
            'message' => 'Beat créé avec succès !',
            'data' => [
                'id' => $loadedBeat->id,
                'name' => $loadedBeat->name,
                'day_of_week' => $loadedBeat->day_of_week?->value,
                'day_of_week_label' => $loadedBeat->day_of_week?->label(),
                'commercial' => $loadedBeat->commercial,
                'total_stops' => $totalStops,
                'created_at' => $loadedBeat->created_at->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    public function getCustomersForMap(Sector $sector)
    {
        $customers = Customer::whereNull('sector_id')
            ->whereNotNull('gps_coordinates')
            ->where('gps_coordinates', '!=', '')
            ->get(['id', 'name', 'phone_number', 'gps_coordinates', 'address', 'description', 'is_prospect'])
            ->map(function ($customer) {
                return array_merge($customer->toArray(), [
                    'can_be_added' => true,
                ]);
            });

        // Also get customers already in the sector but with different styling
        $sectorCustomers = $sector->customers()
            ->whereNotNull('gps_coordinates')
            ->where('gps_coordinates', '!=', '')
            ->get(['id', 'name', 'phone_number', 'gps_coordinates', 'address', 'description', 'is_prospect'])
            ->map(function ($customer) {
                return array_merge($customer->toArray(), [
                    'can_be_added' => false,
                ]);
            });

        return response()->json([
            'sector' => $sector->load('ligne'),
            'customers' => $customers,
            'sector_customers' => $sectorCustomers,
        ]);
    }

    public function map(Sector $sector): \Inertia\Response
    {
        return inertia('Clients/SectorMap', [
            'sector' => $sector->load('ligne'),
        ]);
    }
}
