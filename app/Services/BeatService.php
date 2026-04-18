<?php

namespace App\Services;

use App\Data\Beat\BeatForecastDTO;
use App\Data\Vente\VenteStatsFilter;
use App\Enums\DayOfWeek;
use App\Models\Beat;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Domain service for Beat-related computations.
 *
 * This service is read-only — it never mutates data. All sales and payment
 * queries are delegated exclusively to SalesInvoiceStatsService, which is
 * the single source of truth for financial aggregations in this application.
 */
readonly class BeatService
{
    private const FORECAST_LOOKBACK_DAYS = 15;

    public function __construct(
        private SalesInvoiceStatsService $salesInvoiceStatsService,
    ) {}

    /**
     * Compute forecasted total sales and profit for a beat's next scheduled occurrence.
     *
     * Algorithm:
     *  1. Collect all past dates within FORECAST_LOOKBACK_DAYS that share the beat's day-of-week.
     *  2. For each such date, query the beat's template customers' actual sales via SalesInvoiceStatsService.
     *  3. Return the arithmetic average across those data points as the forecast.
     *
     * Returns a zero-valued forecast when:
     *  - the beat has no template stops (no customers assigned), or
     *  - no matching dates exist in the lookback window (should not happen in practice).
     */
    public function computeForecastedSalesForBeat(Beat $beat): BeatForecastDTO
    {
        $customerIds = $beat->templateStops()->pluck('customer_id')->toArray();

        if (empty($customerIds)) {
            return new BeatForecastDTO(
                forecastedTotalSales: 0,
                forecastedTotalProfit: 0,
                dataPointsCount: 0,
            );
        }

        $pastBeatDates = $this->findPastDatesMatchingDayOfWeek(
            $beat->day_of_week,
            self::FORECAST_LOOKBACK_DAYS,
        );

        if ($pastBeatDates->isEmpty()) {
            return new BeatForecastDTO(
                forecastedTotalSales: 0,
                forecastedTotalProfit: 0,
                dataPointsCount: 0,
            );
        }

        $beatCustomersFilter = VenteStatsFilter::regardlessOfPaymentStatus()
            ->forCustomers($customerIds);

        $totalSalesAcrossDataPoints = 0;
        $totalProfitAcrossDataPoints = 0;

        foreach ($pastBeatDates as $pastBeatDate) {
            $startOfDay = $pastBeatDate->copy()->startOfDay();
            $endOfDay = $pastBeatDate->copy()->endOfDay();

            $totalSalesAcrossDataPoints += $this->salesInvoiceStatsService->totalSales(
                $startOfDay,
                $endOfDay,
                $beatCustomersFilter,
            );

            $totalProfitAcrossDataPoints += $this->salesInvoiceStatsService->totalEstimatedProfits(
                $startOfDay,
                $endOfDay,
                $beatCustomersFilter,
            );
        }

        $dataPointsCount = $pastBeatDates->count();

        return new BeatForecastDTO(
            forecastedTotalSales: (int) round($totalSalesAcrossDataPoints / $dataPointsCount),
            forecastedTotalProfit: (int) round($totalProfitAcrossDataPoints / $dataPointsCount),
            dataPointsCount: $dataPointsCount,
        );
    }

    /**
     * Find all past dates within the given number of days (not including today)
     * that fall on the specified day of week.
     *
     * In a 15-day window, any given weekday appears 2 or 3 times depending on
     * the current day of the week, providing a stable set of data points.
     *
     * @return Collection<int, Carbon>
     */
    private function findPastDatesMatchingDayOfWeek(DayOfWeek $targetDayOfWeek, int $lookbackDays): Collection
    {
        $matchingDates = collect();
        $todayStart = now()->startOfDay();

        for ($daysBack = 1; $daysBack <= $lookbackDays; $daysBack++) {
            $candidateDate = $todayStart->copy()->subDays($daysBack);

            if (DayOfWeek::fromCarbon($candidateDate) === $targetDayOfWeek) {
                $matchingDates->push($candidateDate);
            }
        }

        return $matchingDates;
    }
}
