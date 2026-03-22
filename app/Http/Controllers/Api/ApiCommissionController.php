<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Commission\DailyCommissionService;
use Carbon\CarbonImmutable;
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

    /**
     * Return a detailed commission breakdown for a specific period.
     *
     * Query parameters:
     *   ?type=daily|weekly|monthly   (required — 422 if missing or invalid)
     *   ?date=YYYY-MM-DD             (defaults to today; any date within the target period)
     *
     * The date parameter is used to determine which period to load:
     *   daily   → the single day matching date
     *   weekly  → the Mon–Sun week containing date
     *   monthly → the calendar month containing date
     *
     * Response contains:
     *   period  – type, start_date, end_date
     *   summary – aggregated commission fields across all days in the period
     *   days    – one entry per day that has a DailyCommission record (no zero-filling),
     *             ordered latest first
     *
     * Returns HTTP 404 if the authenticated user has no linked Commercial profile.
     * Returns HTTP 422 if type is missing or not one of daily|weekly|monthly.
     */
    public function getCommissionDetail(Request $request, DailyCommissionService $dailyCommissionService): JsonResponse
    {
        $commercial = $request->user()->commercial;

        if ($commercial === null) {
            return response()->json(['message' => 'Aucun profil commercial lié à cet utilisateur.'], 404);
        }

        $periodType = $request->query('type');

        if (! in_array($periodType, ['daily', 'weekly', 'monthly'], strict: true)) {
            return response()->json(
                ['message' => 'Le paramètre type doit être daily, weekly ou monthly.'],
                422,
            );
        }

        $date = CarbonImmutable::parse($request->query('date', today()->toDateString()));

        [$startDate, $endDate] = match ($periodType) {
            'daily' => [$date, $date],
            'weekly' => [$date->startOfWeek(), $date->endOfWeek()],
            'monthly' => [$date->startOfMonth(), $date->endOfMonth()],
        };

        $detail = $dailyCommissionService->getCommissionDetailForPeriod(
            commercial: $commercial,
            startDate: $startDate,
            endDate: $endDate,
            periodType: $periodType,
        );

        return response()->json($detail);
    }

    /**
     * Return a full commission overview for the current calendar month, split into
     * three sections the salesperson app can render side by side:
     *
     *   daily   – One entry per day of the current month.
     *   weekly  – One entry per Mon–Sun week that overlaps the current month
     *             (weeks that bleed into the previous/next month are included in full).
     *   monthly – One entry per calendar month from the first recorded commission to now.
     *
     * Every entry includes commissions_earned and total_penalties.
     * Returns HTTP 404 if the authenticated user has no linked Commercial profile.
     */
    public function getCommissionOverview(Request $request, DailyCommissionService $dailyCommissionService): JsonResponse
    {
        $commercial = $request->user()->commercial;

        if ($commercial === null) {
            return response()->json(['message' => 'Aucun profil commercial lié à cet utilisateur.'], 404);
        }

        $currentMonth = CarbonImmutable::now();

        $overview = $dailyCommissionService->getCommissionOverviewForMonth($commercial, $currentMonth);

        return response()->json($overview);
    }
}
