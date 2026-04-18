<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CompleteVisitRequest;
use App\Http\Requests\Api\StoreCustomerVisitRequest;
use App\Http\Resources\CustomerVisitResource;
use App\Models\Beat;
use App\Models\BeatStop;
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
        $query = BeatStop::with(['customer', 'beat'])
            ->whereHas('beat', function ($query) use ($request) {
                $query->where('commercial_id', $request->user()->commercial->id);
            })
            ->latest();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('visit_planned_at', [$request->start_date, $request->end_date]);
        }

        $stops = $query->get()->map(fn ($stop) => [
            'id' => $stop->id,
            'customer' => [
                'id' => $stop->customer->id,
                'name' => $stop->customer->name,
                'phone_number' => $stop->customer->phone_number,
                'address' => $stop->customer->address,
            ],
            'beat' => [
                'id' => $stop->beat->id,
                'name' => $stop->beat->name,
                'day_of_week' => $stop->beat->day_of_week?->value,
                'day_of_week_label' => $stop->beat->day_of_week?->label(),
            ],
            'visit_planned_at' => $stop->visit_planned_at,
            'visited_at' => $stop->visited_at,
            'status' => $stop->status,
            'notes' => $stop->notes,
            'resulted_in_sale' => $stop->resulted_in_sale,
            'gps_coordinates' => $stop->gps_coordinates,
        ]);

        return response()->json(['data' => $stops]);
    }

    public function store(StoreCustomerVisitRequest $request): JsonResponse
    {
        $stop = BeatStop::create($request->validated());

        return response()->json([
            'message' => 'Arrêt planifié avec succès',
            'data' => $stop->load(['customer', 'beat']),
        ], 201);
    }

    public function complete(CompleteVisitRequest $request, BeatStop $beatStop): JsonResponse
    {
        if (! $beatStop->isPlanned()) {
            return response()->json(['message' => 'Cet arrêt ne peut pas être complété'], 422);
        }

        $beatStop->complete($request->validated());

        return response()->json([
            'message' => 'Arrêt complété avec succès',
            'data' => $beatStop->load(['customer', 'beat']),
        ]);
    }

    public function cancel(Request $request, BeatStop $beatStop): JsonResponse
    {
        if (! $beatStop->isPlanned()) {
            return response()->json(['message' => 'Cet arrêt ne peut pas être annulé'], 422);
        }

        $request->validate([
            'notes' => ['required', 'string'],
        ], [
            'notes.required' => 'La raison de l\'annulation est obligatoire',
        ]);

        $beatStop->cancel($request->notes);

        return response()->json([
            'message' => 'Arrêt annulé avec succès',
            'data' => $beatStop->load(['customer', 'beat']),
        ]);
    }

    public function show(BeatStop $beatStop): JsonResponse
    {
        return response()->json(['data' => $beatStop->load(['customer', 'beat'])]);
    }

    // ─── Beat listing & today's stops (mobile salesperson API) ───────────────

    public function getBeats(Request $request): JsonResponse
    {
        $commercial = $request->user()->commercial;
        $beats = $this->visitService->getBeats($commercial);

        return response()->json(['data' => $beats]);
    }

    public function getTodayStops(Request $request): JsonResponse
    {
        $commercial = $request->user()->commercial;

        return response()->json($this->visitService->getTodayStops($commercial));
    }

    public function getBeatDetails(Request $request, Beat $beat): JsonResponse
    {
        $beat->load(['stops' => function ($query) {
            $query->with('customer:id,name,phone_number,address,gps_coordinates')
                ->orderBy('visit_planned_at');
        }]);

        return response()->json([
            'data' => [
                'id' => $beat->id,
                'name' => $beat->name,
                'day_of_week' => $beat->day_of_week?->value,
                'day_of_week_label' => $beat->day_of_week?->label(),
                'commercial_id' => $beat->commercial_id,
                'created_at' => $beat->created_at,
                'stops' => CustomerVisitResource::collection($beat->stops),
            ],
        ]);
    }

    public function completeBeatStop(Request $request, BeatStop $beatStop): JsonResponse
    {
        $validated = $request->validate([
            'notes' => 'nullable|string',
            'gps_coordinates' => 'required|string',
            'resulted_in_sale' => 'required|boolean',
        ]);

        $stop = $this->visitService->completeBeatStop($beatStop, $validated);

        return response()->json(new CustomerVisitResource($stop));
    }

    public function cancelBeatStop(Request $request, BeatStop $beatStop): JsonResponse
    {
        $commercial = $request->user()->commercial;

        if (! $this->visitService->canAccessBeatStop($commercial, $beatStop)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate(['notes' => 'nullable|string']);

        $stop = $this->visitService->cancelBeatStop($beatStop, $validated);

        return response()->json($stop);
    }

    public function updateBeatStop(Request $request, BeatStop $beatStop): JsonResponse
    {
        $commercial = $request->user()->commercial;

        if (! $this->visitService->canAccessBeatStop($commercial, $beatStop)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string',
            'visit_planned_at' => 'nullable|date_format:H:i',
        ]);

        $stop = $this->visitService->updateBeatStop($beatStop, $validated);

        return response()->json($stop);
    }
}
