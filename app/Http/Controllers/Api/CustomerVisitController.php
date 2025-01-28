<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteVisitRequest;
use App\Http\Requests\StoreCustomerVisitRequest;
use App\Models\CustomerVisit;
use App\Models\VisitBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerVisitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = CustomerVisit::with(['customer', 'visitBatch'])
            ->whereHas('visitBatch', function ($query) use ($request) {
                $query->where('commercial_id', $request->user()->commercial->id);
            })
            ->latest();

        // Filter by status if provided
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range if provided
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('visit_planned_at', [
                $request->start_date,
                $request->end_date,
            ]);
        }

        $visits = $query->get()->map(function ($visit) {
            return [
                'id' => $visit->id,
                'customer' => [
                    'id' => $visit->customer->id,
                    'name' => $visit->customer->name,
                    'phone_number' => $visit->customer->phone_number,
                    'address' => $visit->customer->address,
                ],
                'visit_batch' => [
                    'id' => $visit->visitBatch->id,
                    'name' => $visit->visitBatch->name,
                    'visit_date' => $visit->visitBatch->visit_date,
                ],
                'visit_planned_at' => $visit->visit_planned_at,
                'visited_at' => $visit->visited_at,
                'status' => $visit->status,
                'notes' => $visit->notes,
                'resulted_in_sale' => $visit->resulted_in_sale,
                'gps_coordinates' => $visit->gps_coordinates,
            ];
        });

        return response()->json(['data' => $visits]);
    }

    public function store(StoreCustomerVisitRequest $request): JsonResponse
    {
        $visit = CustomerVisit::create($request->validated());

        return response()->json([
            'message' => 'Visite planifiée avec succès',
            'data' => $visit->load(['customer', 'visitBatch']),
        ], 201);
    }

    public function complete(CompleteVisitRequest $request, CustomerVisit $visit): JsonResponse
    {
        if (!$visit->isPlanned()) {
            return response()->json([
                'message' => 'Cette visite ne peut pas être complétée',
            ], 422);
        }

        $visit->complete($request->validated());

        return response()->json([
            'message' => 'Visite complétée avec succès',
            'data' => $visit->load(['customer', 'visitBatch']),
        ]);
    }

    public function cancel(Request $request, CustomerVisit $visit): JsonResponse
    {
        if (!$visit->isPlanned()) {
            return response()->json([
                'message' => 'Cette visite ne peut pas être annulée',
            ], 422);
        }

        $request->validate([
            'notes' => ['required', 'string'],
        ], [
            'notes.required' => 'La raison de l\'annulation est obligatoire',
        ]);

        $visit->cancel($request->notes);

        return response()->json([
            'message' => 'Visite annulée avec succès',
            'data' => $visit->load(['customer', 'visitBatch']),
        ]);
    }

    public function show(CustomerVisit $visit): JsonResponse
    {
        return response()->json([
            'data' => $visit->load(['customer', 'visitBatch']),
        ]);
    }
} 