<?php

namespace App\Http\Controllers;

use App\Models\CustomerVisit;
use App\Models\Sector;
use App\Models\Customer;
use App\Models\VisitBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SectorController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'boundaries' => 'nullable|string',
            'ligne_id' => 'required|exists:lignes,id',
            'description' => 'nullable|string'
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
            'description' => 'nullable|string'
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
            'customer_ids.*' => 'exists:customers,id'
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

    public function getVisitBatches(Sector $sector)
    {
        $visitBatches = $sector->visitBatches()
            ->with(['commercial:id,name', 'visits'])
            ->latest()
            ->get()
            ->map(function ($batch) {
                $totalVisits = $batch->visits->count();
                $completedVisits = $batch->visits->where('status', CustomerVisit::STATUS_COMPLETED)->count();
                $cancelledVisits = $batch->visits->where('status', CustomerVisit::STATUS_PLANNED)->count();
                $pendingVisits = $totalVisits - $completedVisits - $cancelledVisits;
                
                $progressPercentage = $totalVisits > 0 
                    ? round(($completedVisits / $totalVisits) * 100) 
                    : 0;

                return [
                    'id' => $batch->id,
                    'name' => $batch->name,
                    'visit_date' => $batch->visit_date,
                    'commercial' => $batch->commercial,
                    'total_visits' => $totalVisits,
                    'completed_visits' => $completedVisits,
                    'cancelled_visits' => $cancelledVisits,
                    'pending_visits' => $pendingVisits,
                    'progress_percentage' => $progressPercentage,
                    'created_at' => $batch->created_at->format('Y-m-d H:i:s'),
                ];
            });

        return response()->json($visitBatches);
    }

    public function createVisitBatch(Request $request, Sector $sector)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'visit_date' => 'required|date',
            'commercial_id' => 'required|exists:commercials,id'
        ], [
            'name.required' => 'Le nom du lot est obligatoire',
            'name.max' => 'Le nom ne doit pas dépasser 255 caractères',
            'visit_date.required' => 'La date de visite est obligatoire',
            'visit_date.date' => 'La date de visite n\'est pas valide',
            'commercial_id.required' => 'Le commercial est obligatoire',
            'commercial_id.exists' => 'Le commercial sélectionné n\'existe pas',
        ]);

            DB::beginTransaction();

            // Create the visit batch
            $visitBatch = VisitBatch::create([
                'name' => $validated['name'],
                'visit_date' => $validated['visit_date'],
                'commercial_id' => $validated['commercial_id'],
                'sector_id' => $sector->id
            ]);

            // Add all customers from the sector to the visit batch
        // TODO remove this condition later to include all customers
            $customerIds = $sector->customers()->where("is_prospect", false)->pluck('customers.id');
            foreach ($customerIds as $customerId) {
                $visitBatch->visits()->create([
                    'customer_id' => $customerId,
                    'visit_planned_at' => $validated['visit_date'],
                    'status' => CustomerVisit::STATUS_PLANNED
                ]);
            }

            DB::commit();
            
            // Return the newly created batch with its details
            $batch = $visitBatch->load(['commercial:id,name', 'visits']);
            $totalVisits = $batch->visits->count();
            
            return response()->json([
                'message' => 'Visites créées avec succès !',
                'data' => [
                    'id' => $batch->id,
                    'name' => $batch->name,
                    'visit_date' => $batch->visit_date,
                    'commercial' => $batch->commercial,
                    'total_visits' => $totalVisits,
                    'completed_visits' => 0,
                    'cancelled_visits' => 0,
                    'pending_visits' => $totalVisits,
                    'progress_percentage' => 0,
                    'created_at' => $batch->created_at->format('Y-m-d H:i:s'),
                ]
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
                    'can_be_added' => true
                ]);
            });

        // Also get customers already in the sector but with different styling
        $sectorCustomers = $sector->customers()
            ->whereNotNull('gps_coordinates')
            ->where('gps_coordinates', '!=', '')
            ->get(['id', 'name', 'phone_number', 'gps_coordinates', 'address', 'description', 'is_prospect'])
            ->map(function ($customer) {
                return array_merge($customer->toArray(), [
                    'can_be_added' => false
                ]);
            });

        return response()->json([
            'sector' => $sector->load('ligne'),
            'customers' => $customers,
            'sector_customers' => $sectorCustomers
        ]);
    }

    public function map(Sector $sector)
    {
        $googleMapsApiKey = config('services.google.maps_api_key');
        
        if (!$googleMapsApiKey) {
            return redirect()->back()->with('error', 'La clé API Google Maps n\'est pas configurée.');
        }

        return inertia('Clients/SectorMap', [
            'sector' => $sector->load('ligne'),
            'googleMapsApiKey' => $googleMapsApiKey
        ]);
    }
} 