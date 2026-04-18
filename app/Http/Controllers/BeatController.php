<?php

namespace App\Http\Controllers;

use App\Models\BeatStop;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BeatController extends Controller
{
    public function show(BeatStop $beatStop): Response
    {
        $beatStop->load(['customer', 'beat']);

        return Inertia::render('Beats/BeatStop/Show', [
            'stop' => [
                'id' => $beatStop->id,
                'customer' => [
                    'id' => $beatStop->customer->id,
                    'name' => $beatStop->customer->name,
                    'phone_number' => $beatStop->customer->phone_number,
                    'address' => $beatStop->customer->address,
                ],
                'beat' => [
                    'id' => $beatStop->beat->id,
                    'name' => $beatStop->beat->name,
                    'day_of_week' => $beatStop->beat->day_of_week?->value,
                    'day_of_week_label' => $beatStop->beat->day_of_week?->label(),
                ],
                'visit_planned_at' => $beatStop->visit_planned_at,
                'visited_at' => $beatStop->visited_at,
                'status' => $beatStop->status,
                'notes' => $beatStop->notes,
                'resulted_in_sale' => $beatStop->resulted_in_sale,
                'gps_coordinates' => $beatStop->gps_coordinates,
            ],
        ]);
    }

    public function complete(Request $request, BeatStop $beatStop)
    {
        if (! $beatStop->isPlanned()) {
            return back()->with('error', 'Cet arrêt ne peut pas être complété');
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string'],
            'resulted_in_sale' => ['required', 'boolean'],
            'gps_coordinates' => ['nullable', 'string'],
        ], [
            'resulted_in_sale.required' => 'Le résultat de la visite est obligatoire',
            'resulted_in_sale.boolean' => 'Le résultat de la visite doit être vrai ou faux',
        ]);

        $beatStop->complete($validated);

        return redirect()->route('beats.show', $beatStop->beat)
            ->with('success', 'Arrêt complété avec succès');
    }

    public function cancel(Request $request, BeatStop $beatStop)
    {
        if (! $beatStop->isPlanned()) {
            return back()->with('error', 'Cet arrêt ne peut pas être annulé');
        }

        $validated = $request->validate([
            'notes' => ['required', 'string'],
        ], [
            'notes.required' => 'La raison de l\'annulation est obligatoire',
        ]);

        $beatStop->cancel($validated['notes']);

        return redirect()->route('beats.show', $beatStop->beat)
            ->with('success', 'Arrêt annulé avec succès');
    }

    public function destroy(BeatStop $beatStop)
    {
        $beatStop->delete();

        return back()->with('success', 'Arrêt supprimé avec succès');
    }

    public function completeFromMobile(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'notes' => ['nullable', 'string'],
            'gps_coordinates' => ['required', 'string'],
        ], [
            'customer_id.required' => 'L\'identifiant du client est obligatoire',
            'customer_id.exists' => 'Le client n\'existe pas',
            'gps_coordinates.required' => 'Les coordonnées GPS sont obligatoires',
        ]);

        $beatStop = BeatStop::where('customer_id', $validated['customer_id'])
            ->where('status', BeatStop::STATUS_PLANNED)
            ->whereDate('visit_date', now()->toDateString())
            ->first();

        if (! $beatStop) {
            return response()->json([
                'message' => 'Aucun arrêt de beat planifié pour ce client',
            ], 422);
        }

        $beatStop->complete([
            'notes' => $validated['notes'],
            'resulted_in_sale' => false,
            'gps_coordinates' => $validated['gps_coordinates'],
        ]);

        return response()->json([
            'message' => 'Arrêt complété avec succès',
        ]);
    }
}
