<?php

namespace App\Http\Controllers;

use App\Models\CustomerVisit;
use App\Models\VisitBatch;
use App\Models\Customer;
use App\Models\Commercial;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VisitBatchController extends Controller
{
    public function index(): Response
    {
        $batches = VisitBatch::with(['visits' => function ($query) {
            $query->select('id', 'visit_batch_id', 'customer_id', 'status');
        }])
            ->with(['commercial:id,name'])
            ->latest()
            ->get()
            ->map(function ($batch) {
                return [
                    'id' => $batch->id,
                    'name' => $batch->name,
                    'visit_date' => $batch->visit_date,
                    'commercial' => [
                        'id' => $batch->commercial->id,
                        'name' => $batch->commercial->name
                    ],
                    'visits' => $batch->visits->map(function ($visit) {
                        return [
                            'id' => $visit->id,
                            'customer_id' => $visit->customer_id,
                            'status' => $visit->status
                        ];
                    })->values()->all(),
                    'visits_count' => $batch->visits->count(),
                    'completed_visits_count' => $batch->visits->where('status', 'completed')->count(),
                    'created_at' => $batch->created_at,
                ];
            });

        $customers = Customer::select('id', 'name', 'phone_number', 'address', 'created_at')
            ->with(['visits' => function($query) {
                $query->select('id', 'customer_id', 'visited_at', 'status')
                    ->whereIn('status', ['completed', 'cancelled'])
                    ->latest('visited_at');
            }])
            ->with(['ventes' => function($query) {
                $query->select('id', 'customer_id', 'created_at')
                    ->latest('created_at')
                    ->take(1);
            }])
            ->orderBy('name')
            ->get()
            ->map(function ($customer) {
                return [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone_number' => $customer->phone_number,
                    'address' => $customer->address,
                    'last_visit' => $customer->last_visit,
                ];
            });

        return Inertia::render('Visits/Index', [
            'batches' => $batches,
            'customers' => $customers,
        ]);
    }

    public function create(): Response
    {
        $customers = Customer::select('id', 'name', 'phone_number', 'address')
            ->get();
            
        $commercials = Commercial::select('id', 'name')
            ->get();

        return Inertia::render('Visits/Create', [
            'customers' => $customers,
            'commercials' => $commercials,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'visit_date' => ['required', 'date'],
            'commercial_id' => ['nullable', 'exists:commercials,id'],
            'visits' => ['required', 'array', 'min:1'],
            'visits.*.visit_planned_at' => ['nullable'],
            'visits.*.customer_id' => ['required', 'exists:customers,id'],
            'visits.*.notes' => ['nullable', 'string'],
        ], [
            'name.required' => 'Le nom est obligatoire',
            'name.string' => 'Le nom doit être une chaîne de caractères',
            'name.max' => 'Le nom ne doit pas dépasser 255 caractères',
            'visit_date.required' => 'La date de visite est obligatoire',
            'visit_date.date' => 'La date de visite n\'est pas valide',
            'commercial_id.exists' => 'Le commercial sélectionné n\'existe pas',
            'visits.required' => 'Au moins une visite est requise',
            'visits.*.customer_id.required' => 'Le client est obligatoire',
            'visits.*.customer_id.exists' => 'Le client sélectionné n\'existe pas',
            'visits.*.visit_planned_at.required' => 'L\'heure de visite est obligatoire',
            'visits.*.visit_planned_at.date' => 'L\'heure de visite n\'est pas valide',
        ]);

        $batch = VisitBatch::create([
            'name' => $validated['name'],
            'visit_date' => $validated['visit_date'],
            'commercial_id' => $validated['commercial_id'] ?? null,
        ]);

        foreach ($validated['visits'] as $visitData) {
            $batch->visits()->create([
                'customer_id' => $visitData['customer_id'],
                'visit_planned_at' => $visitData['visit_planned_at'],
                'notes' => $visitData['notes'] ?? null,
                'status' => 'planned',
            ]);
        }

        return redirect()->route('visits.show', $batch)
            ->with('success', 'Lot de visites créé avec succès');
    }

    public function show(VisitBatch $visitBatch): Response
    {
        $visitBatch->load(['visits.customer']);

        return Inertia::render('Visits/Show', [
            'batch' => [
                'id' => $visitBatch->id,
                'name' => $visitBatch->name,
                'visit_date' => $visitBatch->visit_date,
                'visits' => $visitBatch->visits->map(function ($visit) {
                    return [
                        'id' => $visit->id,
                        'customer' => [
                            'id' => $visit->customer->id,
                            'name' => $visit->customer->name,
                            'phone_number' => $visit->customer->phone_number,
                            'address' => $visit->customer->address,
                        ],
                        'visit_planned_at' => $visit->visit_planned_at,
                        'visited_at' => $visit->visited_at,
                        'status' => $visit->status,
                        'notes' => $visit->notes,
                        'resulted_in_sale' => $visit->resulted_in_sale,
                        'gps_coordinates' => $visit->gps_coordinates,
                    ];
                }),
            ],
        ]);
    }

    public function edit(VisitBatch $visitBatch): Response
    {
        $visitBatch->load('visits.customer');
        
        $customers = Customer::select('id', 'name', 'phone_number', 'address')
            ->get();

        return Inertia::render('Visits/Edit', [
            'batch' => [
                'id' => $visitBatch->id,
                'name' => $visitBatch->name,
                'visit_date' => $visitBatch->visit_date,
                'visits' => $visitBatch->visits->map(function ($visit) {
                    return [
                        'id' => $visit->id,
                        'customer_id' => $visit->customer_id,
                        'visit_planned_at' => $visit->visit_planned_at,
                        'notes' => $visit->notes,
                    ];
                }),
            ],
            'customers' => $customers,
        ]);
    }

    public function update(Request $request, VisitBatch $visitBatch)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'visit_date' => ['required', 'date'],
            'visits' => ['required', 'array', 'min:1'],
            'visits.*.customer_id' => ['required', 'exists:customers,id'],
            'visits.*.visit_planned_at' => ['required', 'date'],
            'visits.*.notes' => ['nullable', 'string'],
        ]);

        $visitBatch->update([
            'name' => $validated['name'],
            'visit_date' => $validated['visit_date'],
        ]);

        // Delete existing visits and create new ones
        $visitBatch->visits()->delete();

        foreach ($validated['visits'] as $visitData) {
            $visitBatch->visits()->create([
                'customer_id' => $visitData['customer_id'],
                'visit_planned_at' => $visitData['visit_planned_at'],
                'notes' => $visitData['notes'] ?? null,
                'status' => 'planned',
            ]);
        }

        return redirect()->route('visits.show', $visitBatch)
            ->with('success', 'Lot de visites mis à jour avec succès');
    }

    public function destroy(VisitBatch $visitBatch)
    {
        $visitBatch->delete();

        return redirect()->route('visits.index')
            ->with('success', 'Lot de visites supprimé avec succès');
    }

    public function addCustomers(Request $request, VisitBatch $visitBatch)
    {
        $request->validate([
            'customer_ids' => ['required', 'array'],
            'customer_ids.*' => ['required', 'exists:customers,id'],
        ]);


        // Get existing customer IDs in this batch to avoid duplicates
        $existingCustomerIds = $visitBatch->visits()->pluck('customer_id')->toArray();
        
        // Filter out customers that are already in the batch
        $newCustomerIds = array_diff($request->customer_ids, $existingCustomerIds);

        // Create new visits for each customer
        foreach ($newCustomerIds as $customerId) {
            $visitBatch->visits()->create([
                'customer_id' => $customerId,
                'status' => CustomerVisit::STATUS_PLANNED,
            ]);
        }

        return back()->with('success', count($newCustomerIds) . ' client(s) ajouté(s) avec succès');
    }
} 