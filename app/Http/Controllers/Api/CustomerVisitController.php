<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CompleteVisitRequest;
use App\Http\Requests\Api\StoreCustomerVisitRequest;
use App\Http\Resources\CustomerVisitResource;
use App\Models\CustomerVisit;
use App\Models\VisitBatch;
use App\Services\CustomerVisitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerVisitController extends Controller
{
    public function __construct(
        private readonly CustomerVisitService $visitService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = CustomerVisit::with(['customer', 'visitBatch'])
            ->whereHas('visitBatch', function ($query) use ($request) {
                $query->where('commercial_id', $request->user()->commercial->id);
            })
            ->latest();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('visit_planned_at', [$request->start_date, $request->end_date]);
        }

        $visits = $query->get()->map(fn ($visit) => [
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
        ]);

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
        if (! $visit->isPlanned()) {
            return response()->json(['message' => 'Cette visite ne peut pas être complétée'], 422);
        }

        $visit->complete($request->validated());

        return response()->json([
            'message' => 'Visite complétée avec succès',
            'data' => $visit->load(['customer', 'visitBatch']),
        ]);
    }

    public function cancel(Request $request, CustomerVisit $visit): JsonResponse
    {
        if (! $visit->isPlanned()) {
            return response()->json(['message' => 'Cette visite ne peut pas être annulée'], 422);
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
        return response()->json(['data' => $visit->load(['customer', 'visitBatch'])]);
    }

    // ─── Visit batch & today's visits (mobile salesperson API) ───────────────

    public function getVisitBatches(Request $request): JsonResponse
    {
        $commercial = $request->user()->commercial;
        $batches = $this->visitService->getVisitBatches($commercial);

        return response()->json(['data' => $batches]);
    }

    public function getTodayVisits(Request $request): JsonResponse
    {
        $commercial = $request->user()->commercial;

        return response()->json($this->visitService->getTodayVisits($commercial));
    }

    public function getVisitBatchDetails(Request $request, VisitBatch $visitBatch): JsonResponse
    {
        $visitBatch->load(['visits' => function ($query) {
            $query->with('customer:id,name,phone_number,address,gps_coordinates')
                ->orderBy('visit_planned_at');
        }]);

        return response()->json([
            'data' => [
                'id' => $visitBatch->id,
                'name' => $visitBatch->name,
                'visit_date' => $visitBatch->visit_date,
                'commercial_id' => $visitBatch->commercial_id,
                'created_at' => $visitBatch->created_at,
                'visits' => CustomerVisitResource::collection($visitBatch->visits),
            ],
        ]);
    }

    public function completeVisit(Request $request, CustomerVisit $customerVisit): JsonResponse
    {
        $validated = $request->validate([
            'notes' => 'nullable|string',
            'gps_coordinates' => 'required|string',
            'resulted_in_sale' => 'required|boolean',
        ]);

        $visit = $this->visitService->completeVisit($customerVisit, $validated);

        return response()->json(new CustomerVisitResource($visit));
    }

    public function cancelVisit(Request $request, CustomerVisit $customerVisit): JsonResponse
    {
        $commercial = $request->user()->commercial;

        if (! $this->visitService->canAccessVisit($commercial, $customerVisit)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate(['notes' => 'nullable|string']);

        $visit = $this->visitService->cancelVisit($customerVisit, $validated);

        return response()->json($visit);
    }

    public function updateVisit(Request $request, CustomerVisit $customerVisit): JsonResponse
    {
        $commercial = $request->user()->commercial;

        if (! $this->visitService->canAccessVisit($commercial, $customerVisit)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string',
            'visit_planned_at' => 'nullable|date_format:H:i',
        ]);

        $visit = $this->visitService->updateVisit($customerVisit, $validated);

        return response()->json($visit);
    }
}
