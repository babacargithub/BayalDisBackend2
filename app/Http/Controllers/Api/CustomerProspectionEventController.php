<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreCustomerProspectionEventRequest;
use App\Models\Customer;
use App\Models\CustomerProspectionEvent;
use Illuminate\Http\JsonResponse;

class CustomerProspectionEventController extends Controller
{
    public function store(StoreCustomerProspectionEventRequest $request, Customer $customer): JsonResponse
    {
        $commercial = $request->user()->commercial;

        $event = CustomerProspectionEvent::create([
            'customer_id' => $customer->id,
            'commercial_id' => $commercial?->id,
            'status' => $request->validated()['status'],
            'notes' => $request->validated()['notes'] ?? null,
            'scheduled_revisit_date' => $request->validated()['scheduled_revisit_date'] ?? null,
        ]);

        $customer->refresh();

        return response()->json([
            'message' => 'Interaction enregistrée avec succès',
            'data' => [
                'id' => $event->id,
                'status' => $event->status->value,
                'status_label' => $event->status->label(),
                'notes' => $event->notes,
                'scheduled_revisit_date' => $event->scheduled_revisit_date?->toDateString(),
                'commercial_name' => $event->commercial?->name,
                'created_at' => $event->created_at,
                'customer_current_prospect_status' => $customer->current_prospect_status,
                'customer_is_prospect' => $customer->is_prospect,
            ],
        ], 201);
    }

    public function index(Customer $customer): JsonResponse
    {
        $events = $customer->prospectionEvents()
            ->with('commercial:id,name')
            ->get()
            ->map(fn (CustomerProspectionEvent $event) => [
                'id' => $event->id,
                'status' => $event->status->value,
                'status_label' => $event->status->label(),
                'status_color' => $event->status->color(),
                'notes' => $event->notes,
                'scheduled_revisit_date' => $event->scheduled_revisit_date?->toDateString(),
                'commercial_name' => $event->commercial?->name,
                'created_at' => $event->created_at,
            ]);

        return response()->json(['data' => $events]);
    }
}
