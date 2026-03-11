<?php

namespace App\Http\Controllers;

use App\Data\Dashboard\DashboardStats;
use App\Services\SalesInvoiceStatsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private readonly SalesInvoiceStatsService $salesInvoiceStatsService) {}

    public function index(Request $request): Response
    {
        $selectedDate = Carbon::parse($request->query('date', Carbon::today()->toDateString()));

        return Inertia::render('Dashboard', [
            'selectedDate' => $selectedDate->toDateString(),
            'dailyStats' => $this->getDailyStats($selectedDate)->toSnakeCaseArray(),
            'weeklyStats' => $this->getWeeklyStats($selectedDate)->toSnakeCaseArray(),
            'monthlyStats' => $this->getMonthlyStats($selectedDate)->toSnakeCaseArray(),
            'overallStats' => $this->getOverallStats()->toSnakeCaseArray(),
        ]);

    }

    private function getDailyStats(Carbon $date): DashboardStats
    {
        return $this->salesInvoiceStatsService->buildStatsForPeriod(
            startDate: $date->copy()->startOfDay(),
            endDate: $date->copy()->endOfDay(),
        );
    }

    private function getWeeklyStats(Carbon $date): DashboardStats
    {
        return $this->salesInvoiceStatsService->buildStatsForPeriod(
            startDate: $date->copy()->startOfWeek(),
            endDate: $date->copy()->endOfWeek(),
        );
    }

    private function getMonthlyStats(Carbon $date): DashboardStats
    {
        return $this->salesInvoiceStatsService->buildStatsForPeriod(
            startDate: $date->copy()->startOfMonth(),
            endDate: $date->copy()->endOfMonth(),
        );
    }

    private function getOverallStats(): DashboardStats
    {
        return $this->salesInvoiceStatsService->buildStatsForPeriod(startDate: null, endDate: null);
    }
}
