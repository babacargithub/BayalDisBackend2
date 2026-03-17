<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Commission\DailyCommissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiCommissionController extends Controller
{
    /**
     * Return the commission summary for the authenticated salesperson on a given day.
     *
     * Query parameter:
     *   ?date=YYYY-MM-DD   (defaults to today)
     *
     * Response: DailyCommissionSummaryData::toArray()
     * Returns HTTP 404 if the authenticated user has no linked Commercial profile.
     */
    public function getDailyCommission(Request $request, DailyCommissionService $dailyCommissionService): JsonResponse
    {
        $workDay = $request->query('date', today()->toDateString());

        $commercial = $request->user()->commercial;

        if ($commercial === null) {
            return response()->json(['message' => 'Aucun profil commercial lié à cet utilisateur.'], 404);
        }

        $summary = $dailyCommissionService->getDailyCommissionSummary($commercial, $workDay);

        return response()->json($summary->toArray());
    }

    /**
     * Return the aggregated commission summary for the week (Monday–Sunday) that
     * contains the given date.
     *
     * Query parameter:
     *   ?date=YYYY-MM-DD   (defaults to today)
     *
     * Response: CommissionPeriodSummaryData::toArray()
     * Returns HTTP 404 if the authenticated user has no linked Commercial profile.
     */
    public function getWeeklyCommissions(Request $request, DailyCommissionService $dailyCommissionService): JsonResponse
    {
        $workDay = $request->query('date', today()->toDateString());

        $commercial = $request->user()->commercial;

        if ($commercial === null) {
            return response()->json(['message' => 'Aucun profil commercial lié à cet utilisateur.'], 404);
        }

        $summary = $dailyCommissionService->getWeeklyCommissionSummary($commercial, $workDay);

        return response()->json($summary->toArray());
    }

    /**
     * Return the aggregated commission summary for the calendar month that contains
     * the given date.
     *
     * Query parameter:
     *   ?date=YYYY-MM-DD   (defaults to today)
     *
     * Response: CommissionPeriodSummaryData::toArray()
     * Returns HTTP 404 if the authenticated user has no linked Commercial profile.
     */
    public function getMonthlyCommissions(Request $request, DailyCommissionService $dailyCommissionService): JsonResponse
    {
        $workDay = $request->query('date', today()->toDateString());

        $commercial = $request->user()->commercial;

        if ($commercial === null) {
            return response()->json(['message' => 'Aucun profil commercial lié à cet utilisateur.'], 404);
        }

        $summary = $dailyCommissionService->getMonthlyCommissionSummary($commercial, $workDay);

        return response()->json($summary->toArray());
    }
}
