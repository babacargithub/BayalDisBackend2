<?php

namespace App\Http\Controllers;

use App\Services\StatisticsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StatisticsController extends Controller
{
    public function __construct(private readonly StatisticsService $statisticsService) {}

    public function index(Request $request): Response
    {
        $viewType = in_array($request->query('view_type'), ['monthly', 'yearly'], strict: true)
            ? $request->query('view_type')
            : 'monthly';

        $year = max(2000, min(2100, (int) $request->query('year', Carbon::today()->year)));
        $month = max(1, min(12, (int) $request->query('month', Carbon::today()->month)));

        if ($viewType === 'yearly') {
            $yearlyActivity = $this->statisticsService->buildYearlyActivity($year);

            return Inertia::render('Admin/Statistiques', [
                'viewType' => 'yearly',
                'year' => $year,
                'month' => $month,
                'monthlyActivity' => null,
                'yearlyActivity' => $yearlyActivity->toArray(),
            ]);
        }

        $monthlyActivity = $this->statisticsService->buildMonthlyActivity($year, $month);

        return Inertia::render('Admin/Statistiques', [
            'viewType' => 'monthly',
            'year' => $year,
            'month' => $month,
            'monthlyActivity' => $monthlyActivity->toArray(),
            'yearlyActivity' => null,
        ]);
    }
}
