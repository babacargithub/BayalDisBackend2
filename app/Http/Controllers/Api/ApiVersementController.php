<?php

namespace App\Http\Controllers\Api;

use App\Enums\CaisseType;
use App\Exceptions\DayAlreadyClosedException;
use App\Http\Controllers\Controller;
use App\Models\Caisse;
use App\Models\Commercial;
use App\Services\CaisseService;
use App\Services\VersementService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiVersementController extends Controller
{
    public function __construct(
        private readonly VersementService $versementService,
        private readonly CaisseService $closeDayService,
    ) {}

    /**
     * Perform a versement: sweep the commercial's caisse balance to the main caisse
     * and credit the earned commission to the commercial's account.
     *
     * The authenticated user's commercial profile is used to locate the source caisse.
     * The destination is the designated main caisse passed via `main_caisse_id`, or
     * the first active main caisse if not specified.
     *
     * POST /api/salesperson/versement
     *
     * Body (optional):
     *   main_caisse_id: int — ID of the target main caisse. Defaults to the first main caisse.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'main_caisse_id' => ['sometimes', 'integer', 'exists:caisses,id'],
        ]);

        $commercial = Commercial::where('user_id', $request->user()->id)->first();

        if ($commercial === null) {
            return response()->json(
                ['message' => 'Aucun profil commercial trouvé pour cet utilisateur.'],
                403
            );
        }

        if (isset($validated['main_caisse_id'])) {
            $mainCaisse = Caisse::find($validated['main_caisse_id']);

            if ($mainCaisse === null || ! $mainCaisse->isMainCaisse()) {
                return response()->json(
                    ['message' => 'La caisse sélectionnée n\'est pas une caisse principale.'],
                    422
                );
            }
        } else {
            $mainCaisse = Caisse::where('caisse_type', CaisseType::Main->value)
                ->where('closed', false)
                ->first();

            if ($mainCaisse === null) {
                return response()->json(
                    ['message' => 'Aucune caisse principale active trouvée.'],
                    422
                );
            }
        }

        // Close the day before performing the versement so that commissions are
        // finalized, costs distributed, and net profit recorded. If the day was
        // already closed earlier (e.g., the commercial closed it manually first),
        // proceed directly to the versement.
        try {
            $this->closeDayService->closeCaisseForDay($commercial, Carbon::today());
        } catch (DayAlreadyClosedException) {
            // Day was already closed — proceed to versement.
        }

        $versement = $this->versementService->performVersement($commercial, $mainCaisse);

        return response()->json([
            'message' => 'Versement effectué avec succès.',
            'versement' => [
                'id' => $versement->id,
                'versement_date' => $versement->versement_date->toDateString(),
                'amount_versed' => $versement->amount_versed,
                'commission_credited' => $versement->commission_credited,
                'merchandise_credited' => $versement->merchandise_credited,
            ],
        ], 201);
    }

    /**
     * Return the authenticated commercial's caisse balance.
     *
     * GET /api/salesperson/caisse
     */
    public function balance(Request $request): JsonResponse
    {
        $commercial = Commercial::where('user_id', $request->user()->id)->first();

        if ($commercial === null) {
            return response()->json(['balance' => 0]);
        }

        $caisse = $commercial->caisse;

        return response()->json([
            'balance' => $caisse?->balance ?? 0,
        ]);
    }

    /**
     * List versements for the authenticated commercial.
     *
     * GET /api/salesperson/versements
     */
    public function index(Request $request): JsonResponse
    {
        $commercial = Commercial::where('user_id', $request->user()->id)->first();

        if ($commercial === null) {
            return response()->json([], 200);
        }

        $versements = $commercial->versements()
            ->orderByDesc('versement_date')
            ->get(['id', 'versement_date', 'amount_versed', 'commission_credited', 'merchandise_credited', 'created_at']);

        return response()->json($versements);
    }
}
