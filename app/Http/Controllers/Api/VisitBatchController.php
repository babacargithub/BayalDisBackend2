<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VisitBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisitBatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $batches = VisitBatch::with('visits.customer')
            ->where('commercial_id', $request->user()->commercial->id)
            ->latest()
            ->get()
            ->map(function ($batch) {
                return [
                    'id' => $batch->id,
                    'name' => $batch->name,
                    'visit_date' => $batch->visit_date,
                    'visits_count' => $batch->visits->count(),
                    'completed_visits_count' => $batch->visits->where('status', 'completed')->count(),
                    'created_at' => $batch->created_at,
                ];
            });

        return response()->json(['data' => $batches]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'visit_date' => ['required', 'date'],
        ], [
            'name.required' => 'Le nom est obligatoire',
            'name.string' => 'Le nom doit être une chaîne de caractères',
            'name.max' => 'Le nom ne doit pas dépasser 255 caractères',
            'visit_date.required' => 'La date de visite est obligatoire',
            'visit_date.date' => 'La date de visite n\'est pas valide',
        ]);

        $batch = VisitBatch::create([
            ...$validated,
            'commercial_id' => $request->user()->commercial->id,
        ]);

        return response()->json([
            'message' => 'Lot de visites créé avec succès',
            'data' => $batch,
        ], 201);
    }

    public function show(VisitBatch $visitBatch): JsonResponse
    {
        return response()->json([
            'data' => [
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

    public function update(Request $request, VisitBatch $visitBatch): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'visit_date' => ['required', 'date'],
        ], [
            'name.required' => 'Le nom est obligatoire',
            'name.string' => 'Le nom doit être une chaîne de caractères',
            'name.max' => 'Le nom ne doit pas dépasser 255 caractères',
            'visit_date.required' => 'La date de visite est obligatoire',
            'visit_date.date' => 'La date de visite n\'est pas valide',
        ]);

        $visitBatch->update($validated);

        return response()->json([
            'message' => 'Lot de visites mis à jour avec succès',
            'data' => $visitBatch,
        ]);
    }

    public function destroy(VisitBatch $visitBatch): JsonResponse
    {
        $visitBatch->delete();

        return response()->json([
            'message' => 'Lot de visites supprimé avec succès',
        ]);
    }
} 