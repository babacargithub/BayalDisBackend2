<?php

namespace App\Services;

use App\Data\Beat\BeatForecastDTO;
use App\Data\Vente\VenteStatsFilter;
use App\Enums\DayOfWeek;
use App\Models\Beat;
use App\Models\BeatStop;
use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
        private PaymentService $paymentService,
    ) {}

    /**
     * Mark all relevant planned BeatStops as completed when a sale or payment occurs.
     *
     * Two categories are completed:
     *
     * 1. **Past planned stops** — any occurrence stop with visit_date < $date that is
     *    still planned. These represent rounds the customer didn't attend before this sale.
     *
     * 2. **The first upcoming occurrence** — the occurrence on the beat's next scheduled
     *    day on or after $date. If $date falls on the beat's day (e.g. Monday sale on a
     *    Monday beat) that same day's stop is used; otherwise the next matching weekday is
     *    used (e.g. Sunday sale on a Monday beat → Monday of that week). The occurrence is
     *    generated if it doesn't exist yet.
     *
     * Only planned stops are transitioned — completed or cancelled stops are untouched.
     */
    public function completeRoundStopForCustomerOnDate(int $customerId, string $date): void
    {
        $saleDate = Carbon::parse($date)->startOfDay();

        $beats = Beat::whereHas('templateStops', fn ($q) => $q->where('customer_id', $customerId))->get();

        foreach ($beats as $beat) {
            // 1. Complete every past planned stop (missed rounds before this sale).
            BeatStop::where('beat_id', $beat->id)
                ->where('customer_id', $customerId)
                ->whereNotNull('visit_date')
                ->whereDate('visit_date', '<', $saleDate->toDateString())
                ->where('status', BeatStop::STATUS_PLANNED)
                ->each(fn (BeatStop $stop) => $stop->complete([
                    'notes' => 'Terminé avec une vente',
                    'resulted_in_sale' => true,
                ]));

            // 2. Find the beat's next scheduled day on or after the sale date, generate
            //    the occurrence stop if needed, and complete it.
            $targetDate = $this->computeNextBeatDateOnOrAfter($beat, $saleDate);
            $beat->getOrGenerateStopsForDate($targetDate);

            BeatStop::where('beat_id', $beat->id)
                ->where('customer_id', $customerId)
                ->whereDate('visit_date', $targetDate->toDateString())
                ->whereNotNull('visit_date')
                ->where('status', BeatStop::STATUS_PLANNED)
                ->first()
                ?->complete([
                    'notes' => 'Terminé avec une vente',
                    'resulted_in_sale' => true,
                ]);
        }
    }

    /**
     * Haversine distance in km from a fixed point to a customer's GPS position.
     * Returns PHP_FLOAT_MAX when the customer has no GPS coordinates so they sort last.
     */
    private function haversineDistanceInKmFromPoint(float $fromLat, float $fromLng, Customer $customer): float
    {
        if (empty($customer->gps_coordinates)) {
            return PHP_FLOAT_MAX;
        }

        [$customerLat, $customerLng] = array_map('floatval', explode(',', $customer->gps_coordinates));

        $earthRadiusKm = 6371.0;
        $deltaLat = deg2rad($customerLat - $fromLat);
        $deltaLng = deg2rad($customerLng - $fromLng);
        $a = sin($deltaLat / 2) ** 2
            + cos(deg2rad($fromLat)) * cos(deg2rad($customerLat)) * sin($deltaLng / 2) ** 2;

        return $earthRadiusKm * 2 * asin(sqrt($a));
    }

    /**
     * Return the beat's next scheduled occurrence on or after $fromDate.
     *
     * If the beat has no day_of_week constraint, $fromDate itself is returned.
     * If $fromDate already falls on the beat's weekday, $fromDate is returned as-is.
     */
    private function computeNextBeatDateOnOrAfter(Beat $beat, Carbon $fromDate): Carbon
    {
        if ($beat->day_of_week === null) {
            return $fromDate->copy()->startOfDay();
        }

        $targetDayNumber = match ($beat->day_of_week) {
            DayOfWeek::Monday => Carbon::MONDAY,
            DayOfWeek::Tuesday => Carbon::TUESDAY,
            DayOfWeek::Wednesday => Carbon::WEDNESDAY,
            DayOfWeek::Thursday => Carbon::THURSDAY,
            DayOfWeek::Friday => Carbon::FRIDAY,
            DayOfWeek::Saturday => Carbon::SATURDAY,
            DayOfWeek::Sunday => Carbon::SUNDAY,
        };

        $date = $fromDate->copy()->startOfDay();

        if ($date->dayOfWeek !== $targetDayNumber) {
            $date = $date->next($targetDayNumber)->startOfDay();
        }

        return $date;
    }

    /**
     * Bulk-add customers to a beat's template roster (idempotent).
     *
     * Each customer_id that does not already have a template stop (visit_date IS NULL)
     * for this beat gets a new one created. Customers already on the roster are silently
     * skipped — re-adding is never an error.
     *
     * @param  int[]  $customerIds
     */
    public function addCustomersToBeat(Beat $beat, array $customerIds): void
    {
        $existingCustomerIds = $beat->templateStops()->pluck('customer_id')->all();

        $newCustomerIds = array_values(array_diff($customerIds, $existingCustomerIds));

        if (empty($newCustomerIds)) {
            return;
        }

        DB::transaction(function () use ($beat, $newCustomerIds): void {
            foreach ($newCustomerIds as $customerId) {
                BeatStop::create([
                    'beat_id' => $beat->id,
                    'customer_id' => $customerId,
                    'status' => BeatStop::STATUS_PLANNED,
                    'visit_date' => null,
                ]);
            }

            $this->recalculateTemplateStopsDisplayPositionByProximity($beat);
        });
    }

    /**
     * Sort all template stops for a beat by Haversine distance from the warehouse
     * and write the resulting order into display_position (0 = closest).
     *
     * Customers with no GPS coordinates are placed at the end.
     * Called automatically when the roster changes (customer added or removed).
     */
    public function recalculateTemplateStopsDisplayPositionByProximity(Beat $beat): void
    {
        $warehouseLat = (float) config('bayal.warehouse.latitude');
        $warehouseLng = (float) config('bayal.warehouse.longitude');

        $sortedStops = $beat->templateStops()
            ->with('customer:id,gps_coordinates')
            ->get()
            ->sortBy(fn (BeatStop $stop) => $this->haversineDistanceInKmFromPoint(
                $warehouseLat,
                $warehouseLng,
                $stop->customer,
            ))
            ->values();

        foreach ($sortedStops as $position => $stop) {
            $stop->update(['display_position' => $position]);
        }
    }

    /**
     * Update the status and notes of a single round occurrence stop.
     *
     * Verifies the stop belongs to the given beat and date before writing.
     * Throws ModelNotFoundException when no matching stop is found, which Laravel
     * translates to a 404 response automatically.
     */
    public function updateStopStatus(Beat $beat, string $date, int $stopId, string $status, ?string $notes): void
    {
        $stop = BeatStop::where('id', $stopId)
            ->where('beat_id', $beat->id)
            ->whereDate('visit_date', $date)
            ->whereNotNull('visit_date')
            ->first();

        if ($stop === null) {
            throw new ModelNotFoundException('Aucun arrêt trouvé pour ce beat et cette date.');
        }

        $stop->update([
            'status' => $status,
            'notes' => $notes,
            'visited_at' => \Illuminate\Support\now(),
        ]);
    }

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

    public function getRoundsForBeat(Beat $beat): array
    {
        $existingRounds = BeatStop::where('beat_id', $beat->id)
            ->whereNotNull('visit_date')
            ->selectRaw('visit_date')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed')
            ->selectRaw('SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) as cancelled')
            ->selectRaw('SUM(CASE WHEN status = "planned" THEN 1 ELSE 0 END) as planned')
            ->groupBy('visit_date')
            ->orderByDesc('visit_date')
            ->get();

        $existingDates = $existingRounds->pluck('visit_date')
            ->map(fn ($d) => $this->castToDateString($d))
            ->all();

        $templateCustomerCount = $beat->templateStops()->count();
        $upcomingDates = $this->computeUpcomingRoundDates($beat, 4, $existingDates);

        $upcomingRounds = collect($upcomingDates)->map(fn (string $date) => [
            'date' => $date,
            'label' => $this->formatRoundLabel($date),
            'status' => 'upcoming',
            'total' => $templateCustomerCount,
            'completed' => 0,
            'cancelled' => 0,
            'planned' => $templateCustomerCount,
        ]);

        $pastRounds = $existingRounds->map(function ($row) {
            $dateString = $this->castToDateString($row->visit_date);

            return [
                'date' => $dateString,
                'label' => $this->formatRoundLabel($dateString),
                'status' => $this->deriveRoundStatus($dateString, (int) $row->planned),
                'total' => (int) $row->total,
                'completed' => (int) $row->completed,
                'cancelled' => (int) $row->cancelled,
                'planned' => (int) $row->planned,
            ];
        });

        return $upcomingRounds->concat($pastRounds)
            ->sortByDesc('date')
            ->values()
            ->all();
    }

    public function getRoundCustomers(Beat $beat, string $date): array
    {
        $beat->getOrGenerateStopsForDate(Carbon::parse($date)->startOfDay());

        $stops = BeatStop::where('beat_id', $beat->id)
            ->whereDate('visit_date', $date)
            ->with([
                'customer:id,name,address,phone_number',
                'customer.salesInvoices' => fn ($q) => $q->select('id', 'customer_id', 'total_amount', 'total_payments')
                    ->whereDate('created_at', '<', $date),
            ])
            ->orderByRaw('display_position IS NULL ASC, display_position ASC, id ASC')
            ->get();

        $completed = $stops->where('status', BeatStop::STATUS_COMPLETED)->count();
        $cancelled = $stops->where('status', BeatStop::STATUS_CANCELLED)->count();
        $planned = $stops->where('status', BeatStop::STATUS_PLANNED)->count();

        $totalDebtToCollect = (int) $stops->sum(
            fn (BeatStop $stop) => $stop->customer->salesInvoices->sum('total_remaining')
        );

        $customerIds = $stops->pluck('customer.id')->filter()->all();
        $totalCollected = empty($customerIds) ? 0 : $this->salesInvoiceStatsService->totalSales(
            Carbon::parse($date)->startOfDay(),
            Carbon::parse($date)->endOfDay(),
            VenteStatsFilter::regardlessOfPaymentStatus()->forCustomers($customerIds),
        );

        return [
            'date' => $date,
            'label' => $this->formatRoundLabel($date),
            'status' => $this->deriveRoundStatus($date, $planned),
            'total' => $stops->count(),
            'completed' => $completed,
            'cancelled' => $cancelled,
            'planned' => $planned,
            'total_debt_to_collect' => $totalDebtToCollect,
            'total_collected' => (int) $totalCollected,
            'remaining_to_collect' => $totalDebtToCollect - (int) $totalCollected,
            'available_statuses' => [
                ['status' => BeatStop::STATUS_PLANNED, 'label' => 'Prévu'],
                ['status' => BeatStop::STATUS_COMPLETED, 'label' => 'Visite effectuée'],
                ['status' => BeatStop::STATUS_CANCELLED, 'label' => 'Visite annulée'],
            ],
            'customers' => $stops->map(fn (BeatStop $stop) => [
                'stop_id' => $stop->id,
                'customer_id' => $stop->customer->id,
                'name' => $stop->customer->name,
                'address' => $stop->customer->address,
                'phone_number' => $stop->customer->phone_number,
                'debt' => (int) $stop->customer->salesInvoices->sum('total_remaining'),
                'status' => $stop->status,
                'visited_at' => $stop->visited_at,
                'notes' => $stop->notes,
                'display_position' => $stop->display_position,
            ])->all(),
        ];
    }

    private function formatRoundLabel(string $date): string
    {
        return ucfirst(Carbon::parse($date)->locale('fr')->isoFormat('dddd D MMMM YYYY'));
    }

    private function deriveRoundStatus(string $date, int $planned): string
    {
        if ($planned === 0) {
            return 'done';
        }

        return Carbon::parse($date)->startOfDay()->gt(now()->startOfDay()) ? 'upcoming' : 'in_progress';
    }

    private function castToDateString(mixed $date): string
    {
        return $date instanceof Carbon ? $date->toDateString() : (string) $date;
    }

    private function computeUpcomingRoundDates(Beat $beat, int $count, array $existingDates): array
    {
        if ($beat->day_of_week === null) {
            return [];
        }

        $carbonDayOfWeek = match ($beat->day_of_week) {
            DayOfWeek::Monday => Carbon::MONDAY,
            DayOfWeek::Tuesday => Carbon::TUESDAY,
            DayOfWeek::Wednesday => Carbon::WEDNESDAY,
            DayOfWeek::Thursday => Carbon::THURSDAY,
            DayOfWeek::Friday => Carbon::FRIDAY,
            DayOfWeek::Saturday => Carbon::SATURDAY,
            DayOfWeek::Sunday => Carbon::SUNDAY,
        };

        $cursor = now()->startOfDay();
        if ($cursor->dayOfWeek !== $carbonDayOfWeek) {
            $cursor = $cursor->next($carbonDayOfWeek);
        }

        $upcoming = [];
        while (count($upcoming) < $count) {
            $dateString = $cursor->toDateString();
            if (! in_array($dateString, $existingDates)) {
                $upcoming[] = $dateString;
            }
            $cursor = $cursor->copy()->addWeek();
        }

        return $upcoming;
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
