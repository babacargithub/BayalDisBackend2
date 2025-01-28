<?php

namespace App\Http\Controllers;

use App\Models\CustomerVisit;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerVisitController extends Controller
{
    public function show(CustomerVisit $customerVisit): Response
    {
        $customerVisit->load(['customer', 'visitBatch']);

        return Inertia::render('Visits/CustomerVisit/Show', [
            'visit' => [
                'id' => $customerVisit->id,
                'customer' => [
                    'id' => $customerVisit->customer->id,
                    'name' => $customerVisit->customer->name,
                    'phone_number' => $customerVisit->customer->phone_number,
                    'address' => $customerVisit->customer->address,
                ],
                'visit_batch' => [
                    'id' => $customerVisit->visitBatch->id,
                    'name' => $customerVisit->visitBatch->name,
                    'visit_date' => $customerVisit->visitBatch->visit_date,
                ],
                'visit_planned_at' => $customerVisit->visit_planned_at,
                'visited_at' => $customerVisit->visited_at,
                'status' => $customerVisit->status,
                'notes' => $customerVisit->notes,
                'resulted_in_sale' => $customerVisit->resulted_in_sale,
                'gps_coordinates' => $customerVisit->gps_coordinates,
            ],
        ]);
    }

    public function complete(Request $request, CustomerVisit $customerVisit)
    {
        if (!$customerVisit->isPlanned()) {
            return back()->with('error', 'Cette visite ne peut pas être complétée');
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string'],
            'resulted_in_sale' => ['required', 'boolean'],
            'gps_coordinates' => ['nullable', 'string'],
        ], [
            'resulted_in_sale.required' => 'Le résultat de la visite est obligatoire',
            'resulted_in_sale.boolean' => 'Le résultat de la visite doit être vrai ou faux',
        ]);

        $customerVisit->complete($validated);

        return redirect()->route('visits.show', $customerVisit->visitBatch)
            ->with('success', 'Visite complétée avec succès');
    }

    public function cancel(Request $request, CustomerVisit $customerVisit)
    {
        if (!$customerVisit->isPlanned()) {
            return back()->with('error', 'Cette visite ne peut pas être annulée');
        }

        $validated = $request->validate([
            'notes' => ['required', 'string'],
        ], [
            'notes.required' => 'La raison de l\'annulation est obligatoire',
        ]);

        $customerVisit->cancel($validated['notes']);

        return redirect()->route('visits.show', $customerVisit->visitBatch)
            ->with('success', 'Visite annulée avec succès');
    }

    public function destroy(CustomerVisit $customerVisit)
    {
       

        $customerVisit->delete();

        return back()->with('success', 'Visite supprimée avec succès');
    }
} 