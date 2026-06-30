<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AddCustomersToBeatRequest;
use App\Http\Requests\Api\CompleteVisitRequest;
use App\Http\Requests\Api\RecordBeatRoundOdometerRequest;
use App\Http\Requests\Api\StoreCustomerVisitRequest;
use App\Http\Requests\Api\UpdateBeatStopStatusRequest;
use App\Http\Resources\CustomerVisitResource;
use App\Models\Beat;
use App\Models\BeatRound;
use App\Models\BeatStop;
use App\Models\Customer;
use App\Services\BeatService;
use App\Services\CustomerVisitService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BeatRoundController extends Controller
{
    public function __construct(
        private readonly CustomerVisitService $visitService,
        private readonly BeatService $beatService,
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

    public function listBeatCustomers(Request $request, Beat $beat): JsonResponse
    {
        $commercial = $request->user()->commercial;

        if ($beat->commercial_id !== $commercial->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Recalculate proximity order only when NO stop has a position yet
        // (fresh beat with directly-inserted stops). If some stops already have
        // positions, leave them intact and let NULLS LAST handle stragglers.
        if ($beat->templateStops()->whereNotNull('display_position')->doesntExist()) {
            $this->beatService->recalculateTemplateStopsDisplayPositionByProximity($beat);
        }

        $customers = $beat->templateStops()
            ->with(['customer' => fn ($query) => $query->with([
                'salesInvoices:id,customer_id,total_amount,total_payments',
            ])])
            ->orderByRaw('display_position IS NULL ASC, display_position ASC')
            ->get()
            ->map(fn (BeatStop $stop) => [
                'id' => $stop->customer->id,
                'name' => $stop->customer->name,
                'address' => $stop->customer->address,
                'debt' => $stop->customer->total_debt,
                'display_position' => $stop->display_position,
            ]);

        return response()->json(['data' => $customers]);
    }

    public function addCustomersToBeat(AddCustomersToBeatRequest $request, Beat $beat): JsonResponse
    {
        $commercial = $request->user()->commercial;

        if ($beat->commercial_id !== $commercial->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $this->beatService->addCustomersToBeat($beat, $request->validated()['customer_ids']);

        return response()->json(['message' => 'Clients ajoutés au beat']);
    }

    public function removeCustomerFromBeat(Request $request, Beat $beat, Customer $customer): JsonResponse
    {
        $commercial = $request->user()->commercial;

        if ($beat->commercial_id !== $commercial->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $templateStop = $beat->templateStops()
            ->where('customer_id', $customer->id)
            ->first();

        if (! $templateStop) {
            return response()->json(['message' => 'Client non trouvé dans ce beat'], 404);
        }

        $templateStop->delete();

        $this->beatService->recalculateTemplateStopsDisplayPositionByProximity($beat);

        return response()->json(['message' => 'Client retiré du beat']);
    }

    public function reorderBeatCustomers(Request $request, Beat $beat): JsonResponse
    {
        $commercial = $request->user()->commercial;

        if ($beat->commercial_id !== $commercial->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'positions' => ['required', 'array'],
            'positions.*.customer_id' => ['required', 'integer'],
            'positions.*.display_position' => ['required', 'integer', 'min:0'],
        ]);

        foreach ($validated['positions'] as $entry) {
            $beat->templateStops()
                ->where('customer_id', $entry['customer_id'])
                ->update(['display_position' => $entry['display_position']]);
        }

        return response()->json(['message' => 'Ordre mis à jour']);
    }

    public function listBeatRounds(Request $request, Beat $beat): JsonResponse
    {
        $commercial = $request->user()->commercial;

        if ($beat->commercial_id !== $commercial->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json(['data' => $this->beatService->getRoundsForBeat($beat)]);
    }

    public function updateStopStatus(UpdateBeatStopStatusRequest $request, Beat $beat, string $date, int $stop): JsonResponse
    {
        $commercial = $request->user()->commercial;

        if ($beat->commercial_id !== $commercial->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $this->beatService->updateStopStatus(
            beat: $beat,
            date: $date,
            stopId: $stop,
            status: $request->validated()['status'],
            notes: $request->validated()['notes'] ?? null,
        );

        return response()->json(null, 204);
    }

    public function listBeatRoundCustomers(Request $request, Beat $beat, string $date): JsonResponse
    {
        $commercial = $request->user()->commercial;

        if ($beat->commercial_id !== $commercial->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            Carbon::createFromFormat('Y-m-d', $date);
        } catch (\Exception) {
            return response()->json(['message' => 'Format de date invalide (YYYY-MM-DD requis)'], 422);
        }

        $data = $this->beatService->getCustomersOfBeatRound($beat, $date);

        if ($data === null) {
            return response()->json(['message' => 'Aucune tournée trouvée pour cette date'], 404);
        }

        return response()->json(['data' => $data]);
    }

    public function createBeatRound(Request $request, Beat $beat): JsonResponse
    {
        $commercial = $request->user()->commercial;

        if ($beat->commercial_id !== $commercial->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'planned_at' => ['required', 'date_format:Y-m-d'],
        ]);

        $existingRound = $beat->findRoundForDate(Carbon::parse($validated['planned_at']));
        if ($existingRound !== null) {
            return response()->json(['message' => 'Une tournée existe déjà pour cette date'], 422);
        }

        $round = $this->beatService->createRound($beat, $validated['planned_at']);

        return response()->json([
            'data' => [
                'id' => $round->id,
                'planned_at' => $round->planned_at->toDateString(),
                'name' => $round->name,
            ],
        ], 201);
    }

    public function listBeatsWithCustomerCount(Request $request): JsonResponse
    {
        $commercial = $request->user()->commercial;

        $beats = Beat::where('commercial_id', $commercial->id)
            ->withCount(['stops as customers_count' => function ($query) {
                $query->whereNull('beat_round_id');
            }])
            ->orderBy('id')
            ->get()
            ->map(fn (Beat $beat) => [
                'id' => $beat->id,
                'name' => $beat->name,
                'customers_count' => $beat->customers_count,
            ]);

        return response()->json(['data' => $beats]);
    }

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

    public function recordOdometer(RecordBeatRoundOdometerRequest $request, Beat $beat, string $date): JsonResponse
    {
        $commercial = $request->user()->commercial;

        if ($beat->commercial_id !== $commercial->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            Carbon::createFromFormat('Y-m-d', $date);
        } catch (\Exception) {
            return response()->json(['message' => 'Format de date invalide (YYYY-MM-DD requis)'], 422);
        }

        $round = BeatRound::where('beat_id', $beat->id)
            ->whereDate('planned_at', $date)
            ->first();

        if ($round === null) {
            return response()->json(['message' => 'Aucune tournée trouvée pour cette date'], 404);
        }

        $validated = $request->validated();

        if ($validated['type'] === 'start') {
            $round->update([
                'vehicle_id' => $validated['vehicle_id'],
                'odometer_start_km' => $validated['km'],
                'odometer_end_km' => null,
            ]);

            return response()->json([
                'message' => 'Kilométrage de départ enregistré',
                'data' => $this->buildOdometerResponse($round),
            ]);
        }

        if ($round->odometer_start_km === null) {
            return response()->json(['message' => 'Le kilométrage de départ doit être enregistré en premier'], 422);
        }

        if ($validated['km'] < $round->odometer_start_km) {
            return response()->json([
                'message' => "Le kilométrage d'arrivée ({$validated['km']} km) ne peut pas être inférieur au kilométrage de départ ({$round->odometer_start_km} km)",
            ], 422);
        }

        $round->update(['odometer_end_km' => $validated['km']]);

        return response()->json([
            'message' => "Kilométrage d'arrivée enregistré",
            'data' => $this->buildOdometerResponse($round),
        ]);
    }

    private function buildOdometerResponse(BeatRound $round): array
    {
        return [
            'round_id' => $round->id,
            'vehicle_id' => $round->vehicle_id,
            'odometer_start_km' => $round->odometer_start_km,
            'odometer_end_km' => $round->odometer_end_km,
            'distance_km' => $round->distance_km,
        ];
    }

    public function getBeatDetails(Beat $beat): JsonResponse
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
