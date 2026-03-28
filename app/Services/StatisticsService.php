<?php

namespace App\Services;

use App\Data\Statistics\DailyActivityDTO;
use App\Data\Statistics\MonthlyActivitySummaryDTO;
use App\Data\Statistics\MonthlyTotalsDTO;
use App\Data\Statistics\YearlyActivitySummaryDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds per-day and per-month financial activity summaries for the Statistics page.
 *
 * Uses bulk SQL GROUP BY queries (one for invoices, one for payments) to aggregate
 * an entire month's worth of data in exactly two database round-trips, keeping
 * performance acceptable for a 31-day breakdown.
 *
 * This service is read-only — it never mutates data.
 */
class StatisticsService
{
    /**
     * Build the daily activity breakdown for a given calendar month.
     *
     * Returns one DailyActivityDTO per calendar day of the month (including days with
     * no activity where all values are zero), plus aggregated monthly totals.
     *
     * Invoice-based metrics (sales, estimated profit, commissions, delivery cost) are
     * grouped by the invoice creation date. Realized profit is grouped by the payment
     * received date — matching SalesInvoiceStatsService::buildStatsForPeriod().
     *
     * @param  int  $year  Four-digit year (e.g. 2026).
     * @param  int  $month  Month number 1–12.
     */
    public function buildMonthlyActivity(int $year, int $month): MonthlyActivitySummaryDTO
    {
        $periodStart = Carbon::createFromDate($year, $month, 1)->startOfDay();
        $periodEnd = $periodStart->copy()->endOfMonth()->endOfDay();

        $invoiceDataByDay = $this->fetchInvoiceAggregatesByDay($periodStart, $periodEnd);
        $paymentDataByDay = $this->fetchPaymentAggregatesByDay($periodStart, $periodEnd);

        $daysInMonth = $periodStart->daysInMonth;
        $dailyActivity = [];

        for ($dayNumber = 1; $dayNumber <= $daysInMonth; $dayNumber++) {
            $dateString = sprintf('%04d-%02d-%02d', $year, $month, $dayNumber);

            $invoiceData = $invoiceDataByDay->get($dateString);
            $paymentData = $paymentDataByDay->get($dateString);

            $invoicesCount = (int) ($invoiceData?->invoices_count ?? 0);
            $totalSales = (int) ($invoiceData?->total_sales ?? 0);
            $totalEstimatedProfit = (int) ($invoiceData?->total_estimated_profit ?? 0);
            $totalCommissions = (int) ($invoiceData?->total_commissions ?? 0);
            $totalDeliveryCost = (int) ($invoiceData?->total_delivery_cost ?? 0);
            $totalRealizedProfit = (int) ($paymentData?->total_realized_profit ?? 0);

            $netProfit = $totalRealizedProfit - $totalCommissions - $totalDeliveryCost;
            $invoiceAverageTotal = $invoicesCount > 0
                ? (int) round($totalSales / $invoicesCount)
                : 0;

            $dailyActivity[] = new DailyActivityDTO(
                date: $dateString,
                invoicesCount: $invoicesCount,
                totalSales: $totalSales,
                totalEstimatedProfit: $totalEstimatedProfit,
                totalRealizedProfit: $totalRealizedProfit,
                totalDeliveryCost: $totalDeliveryCost,
                totalCommissions: $totalCommissions,
                netProfit: $netProfit,
                invoiceAverageTotal: $invoiceAverageTotal,
                isDeficit: $netProfit < 0,
            );
        }

        $totalInvoicesCount = (int) array_sum(array_column(
            array_map(fn (DailyActivityDTO $day) => ['count' => $day->invoicesCount], $dailyActivity),
            'count'
        ));
        $totalSalesMonthly = (int) array_sum(array_column(
            array_map(fn (DailyActivityDTO $day) => ['sales' => $day->totalSales], $dailyActivity),
            'sales'
        ));
        $totalEstimatedProfitMonthly = (int) array_sum(array_column(
            array_map(fn (DailyActivityDTO $day) => ['profit' => $day->totalEstimatedProfit], $dailyActivity),
            'profit'
        ));
        $totalRealizedProfitMonthly = (int) array_sum(array_column(
            array_map(fn (DailyActivityDTO $day) => ['profit' => $day->totalRealizedProfit], $dailyActivity),
            'profit'
        ));
        $totalDeliveryCostMonthly = (int) array_sum(array_column(
            array_map(fn (DailyActivityDTO $day) => ['cost' => $day->totalDeliveryCost], $dailyActivity),
            'cost'
        ));
        $totalCommissionsMonthly = (int) array_sum(array_column(
            array_map(fn (DailyActivityDTO $day) => ['commissions' => $day->totalCommissions], $dailyActivity),
            'commissions'
        ));

        $activeDaysCount = count(array_filter(
            $dailyActivity,
            fn (DailyActivityDTO $day) => $day->invoicesCount > 0
        ));

        $netProfitMonthly = $totalRealizedProfitMonthly - $totalCommissionsMonthly - $totalDeliveryCostMonthly;

        $averageDailySales = $activeDaysCount > 0
            ? (int) round($totalSalesMonthly / $activeDaysCount)
            : 0;

        $averageInvoiceTotal = $totalInvoicesCount > 0
            ? (int) round($totalSalesMonthly / $totalInvoicesCount)
            : 0;

        return new MonthlyActivitySummaryDTO(
            year: $year,
            month: $month,
            daysInMonth: $daysInMonth,
            activeDaysCount: $activeDaysCount,
            dailyActivity: $dailyActivity,
            totalInvoicesCount: $totalInvoicesCount,
            totalSales: $totalSalesMonthly,
            totalEstimatedProfit: $totalEstimatedProfitMonthly,
            totalRealizedProfit: $totalRealizedProfitMonthly,
            totalDeliveryCost: $totalDeliveryCostMonthly,
            totalCommissions: $totalCommissionsMonthly,
            netProfit: $netProfitMonthly,
            averageDailySales: $averageDailySales,
            averageInvoiceTotal: $averageInvoiceTotal,
        );
    }

    /**
     * Build the monthly activity breakdown for a given calendar year.
     *
     * Returns one MonthlyTotalsDTO per month of the year (January–December, including
     * months with zero activity), plus aggregated yearly totals.
     *
     * Reuses the same two day-level SQL queries as buildMonthlyActivity() but spans
     * the full year, then groups the results by month number in PHP — still only
     * two database round-trips for the entire year.
     *
     * @param  int  $year  Four-digit year (e.g. 2026).
     */
    public function buildYearlyActivity(int $year): YearlyActivitySummaryDTO
    {
        $periodStart = Carbon::createFromDate($year, 1, 1)->startOfDay();
        $periodEnd = Carbon::createFromDate($year, 12, 31)->endOfDay();

        $allDailyInvoiceData = $this->fetchInvoiceAggregatesByDay($periodStart, $periodEnd);
        $allDailyPaymentData = $this->fetchPaymentAggregatesByDay($periodStart, $periodEnd);

        // Group day-level rows by month number (extracted from "YYYY-MM-DD").
        $invoiceRowsByMonth = $allDailyInvoiceData->groupBy(
            fn ($row) => (int) substr($row->activity_date, 5, 2)
        );
        $paymentRowsByMonth = $allDailyPaymentData->groupBy(
            fn ($row) => (int) substr($row->activity_date, 5, 2)
        );

        $monthlyTotals = [];

        for ($monthNumber = 1; $monthNumber <= 12; $monthNumber++) {
            $invoiceRows = $invoiceRowsByMonth->get($monthNumber, collect());
            $paymentRows = $paymentRowsByMonth->get($monthNumber, collect());

            $invoicesCount = (int) $invoiceRows->sum('invoices_count');
            $totalSales = (int) $invoiceRows->sum('total_sales');
            $totalEstimatedProfit = (int) $invoiceRows->sum('total_estimated_profit');
            $totalCommissions = (int) $invoiceRows->sum('total_commissions');
            $totalDeliveryCost = (int) $invoiceRows->sum('total_delivery_cost');
            $totalRealizedProfit = (int) $paymentRows->sum('total_realized_profit');

            $netProfit = $totalRealizedProfit - $totalCommissions - $totalDeliveryCost;
            $invoiceAverageTotal = $invoicesCount > 0
                ? (int) round($totalSales / $invoicesCount)
                : 0;

            // Active days = distinct days within this month that had at least one invoice.
            $activeDaysCount = $invoiceRows->filter(fn ($row) => $row->invoices_count > 0)->count();

            $monthlyTotals[] = new MonthlyTotalsDTO(
                monthNumber: $monthNumber,
                invoicesCount: $invoicesCount,
                totalSales: $totalSales,
                totalEstimatedProfit: $totalEstimatedProfit,
                totalRealizedProfit: $totalRealizedProfit,
                totalDeliveryCost: $totalDeliveryCost,
                totalCommissions: $totalCommissions,
                netProfit: $netProfit,
                invoiceAverageTotal: $invoiceAverageTotal,
                isDeficit: $netProfit < 0,
                activeDaysCount: $activeDaysCount,
            );
        }

        $totalInvoicesCount = (int) array_sum(array_column(
            array_map(fn (MonthlyTotalsDTO $m) => ['c' => $m->invoicesCount], $monthlyTotals), 'c'
        ));
        $totalSalesYearly = (int) array_sum(array_column(
            array_map(fn (MonthlyTotalsDTO $m) => ['s' => $m->totalSales], $monthlyTotals), 's'
        ));
        $totalEstimatedProfitYearly = (int) array_sum(array_column(
            array_map(fn (MonthlyTotalsDTO $m) => ['p' => $m->totalEstimatedProfit], $monthlyTotals), 'p'
        ));
        $totalRealizedProfitYearly = (int) array_sum(array_column(
            array_map(fn (MonthlyTotalsDTO $m) => ['p' => $m->totalRealizedProfit], $monthlyTotals), 'p'
        ));
        $totalDeliveryCostYearly = (int) array_sum(array_column(
            array_map(fn (MonthlyTotalsDTO $m) => ['c' => $m->totalDeliveryCost], $monthlyTotals), 'c'
        ));
        $totalCommissionsYearly = (int) array_sum(array_column(
            array_map(fn (MonthlyTotalsDTO $m) => ['c' => $m->totalCommissions], $monthlyTotals), 'c'
        ));

        $activeMonthsCount = count(array_filter(
            $monthlyTotals,
            fn (MonthlyTotalsDTO $m) => $m->invoicesCount > 0
        ));

        $netProfitYearly = $totalRealizedProfitYearly - $totalCommissionsYearly - $totalDeliveryCostYearly;

        $averageMonthlySales = $activeMonthsCount > 0
            ? (int) round($totalSalesYearly / $activeMonthsCount)
            : 0;

        $averageInvoiceTotal = $totalInvoicesCount > 0
            ? (int) round($totalSalesYearly / $totalInvoicesCount)
            : 0;

        return new YearlyActivitySummaryDTO(
            year: $year,
            monthlyTotals: $monthlyTotals,
            totalInvoicesCount: $totalInvoicesCount,
            totalSales: $totalSalesYearly,
            totalEstimatedProfit: $totalEstimatedProfitYearly,
            totalRealizedProfit: $totalRealizedProfitYearly,
            totalDeliveryCost: $totalDeliveryCostYearly,
            totalCommissions: $totalCommissionsYearly,
            netProfit: $netProfitYearly,
            averageMonthlySales: $averageMonthlySales,
            averageInvoiceTotal: $averageInvoiceTotal,
            activeMonthsCount: $activeMonthsCount,
        );
    }

    // =========================================================================
    // Private query helpers
    // =========================================================================

    /**
     * Aggregate sales_invoices data grouped by calendar day within the period.
     *
     * Returns a Collection keyed by "Y-m-d" date strings. Each item contains:
     *   invoices_count, total_sales, total_estimated_profit,
     *   total_commissions, total_delivery_cost.
     *
     * @return Collection<string, object>
     */
    private function fetchInvoiceAggregatesByDay(Carbon $periodStart, Carbon $periodEnd): Collection
    {
        return DB::table('sales_invoices')
            ->select([
                DB::raw('DATE(created_at) as activity_date'),
                DB::raw('COUNT(*) as invoices_count'),
                DB::raw('COALESCE(SUM(total_amount), 0) as total_sales'),
                DB::raw('COALESCE(SUM(total_estimated_profit), 0) as total_estimated_profit'),
                DB::raw('COALESCE(SUM(estimated_commercial_commission), 0) as total_commissions'),
                DB::raw('COALESCE(SUM(delivery_cost), 0) as total_delivery_cost'),
            ])
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->groupBy('activity_date')
            ->orderBy('activity_date')
            ->get()
            ->keyBy('activity_date');
    }

    /**
     * Aggregate payments data grouped by calendar day within the period.
     *
     * Only includes payments linked to a sales invoice (sales_invoice_id IS NOT NULL).
     * Returns a Collection keyed by "Y-m-d" date strings. Each item contains:
     *   total_realized_profit.
     *
     * @return Collection<string, object>
     */
    private function fetchPaymentAggregatesByDay(Carbon $periodStart, Carbon $periodEnd): Collection
    {
        return DB::table('payments')
            ->select([
                DB::raw('DATE(created_at) as activity_date'),
                DB::raw('COALESCE(SUM(profit), 0) as total_realized_profit'),
            ])
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->whereNotNull('sales_invoice_id')
            ->groupBy('activity_date')
            ->orderBy('activity_date')
            ->get()
            ->keyBy('activity_date');
    }
}
