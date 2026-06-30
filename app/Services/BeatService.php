<?php

namespace App\Services;

use App\Data\Beat\BeatForecastDTO;
use App\Data\Vente\VenteStatsFilter;
use App\Enums\BeatStopStatus;
use App\Enums\DayOfWeek;
use App\Models\Beat;
use App\Models\BeatRound;
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
        private SalesInvoiceStatsService $salesInvoiceStatsService
    ) {}

    /**
     * Mark all relevant planned BeatStops as completed when a sale or payment occurs.
     *
     * Two categories are completed:
     *
     * 1. **Past planned stops** — any occurrence stop linked to a round with planned_at < $date
     *    that is still planned. These represent rounds the customer didn't attend before this sale.
     *
     * 2. **The first upcoming occurrence** — the occurrence on the beat's next scheduled
     *    day on or after $date, but only if a round has already been explicitly created for
     *    that date. Rounds are never auto-created here.
     *
     * Only planned stops are transitioned — completed or cancelled stops are untouched.
     *
     * Returns the IDs of all target BeatRounds (one per matching beat) for which a round
     * exists on the sale date's scheduled occurrence. The caller uses these IDs to dispatch
     * RecalculateBeatRoundStrikeRateJob for each affected round.
     *
     * @return int[] BeatRound IDs that correspond to the sale date's round occurrence.
     */
    public function completeRoundStopForCustomerOnDate(int $customerId, string $date): array
    {
        $saleDate = Carbon::parse($date)->startOfDay();
        $affectedBeatRoundIds = [];

        $beats = Beat::whereHas('templateStops', fn ($q) => $q->where('customer_id', $customerId))->get();

        foreach ($beats as $beat) {
            // 1. Complete every past planned stop (missed rounds before this sale).
            BeatStop::where('beat_id', $beat->id)
                ->where('customer_id', $customerId)
                ->whereNotNull('beat_round_id')
                ->whereHas('round', fn ($q) => $q->whereDate('planned_at', '<', $saleDate->toDateString()))
                ->where('status', BeatStop::STATUS_PLANNED)
                ->each(fn (BeatStop $stop) => $stop->complete([
                    'notes' => 'Terminé avec une vente',
                    'resulted_in_sale' => true,
                ]));

            // 2. Complete the planned stop on the beat's next scheduled date — only if a
            //    round was already explicitly created for that date.
            $targetDate = $this->computeNextBeatDateOnOrAfter($beat, $saleDate);
            $targetRound = $beat->findRoundForDate($targetDate);

            if ($targetRound === null) {
                continue;
            }

            BeatStop::where('beat_id', $beat->id)
                ->where('customer_id', $customerId)
                ->where('beat_round_id', $targetRound->id)
                ->where('status', BeatStop::STATUS_PLANNED)
                ->first()
                ?->complete([
                    'notes' => 'Terminé avec une vente',
                    'resulted_in_sale' => true,
                ]);

            $affectedBeatRoundIds[] = $targetRound->id;
        }

        return $affectedBeatRoundIds;
    }

    /**
     * Explicitly create a BeatRound for the given date and pre-populate it with
     * occurrence stops cloned from the beat's template roster.
     */
    public function createRound(Beat $beat, string $date): BeatRound
    {
        $parsedDate = Carbon::parse($date)->startOfDay();

        $round = BeatRound::create([
            'beat_id' => $beat->id,
            'planned_at' => $parsedDate->toDateString(),
            'name' => $beat->name.' - '.$parsedDate->toDateString(),
            'week_day' => $beat->day_of_week?->value,
            'commercial_id' => $beat->commercial_id,
        ]);

        $beat->getOrGenerateStopsForRound($round);

        return $round;
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
     * Each customer_id that does not already have a template stop (beat_round_id IS NULL)
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
        $round = BeatRound::where('beat_id', $beat->id)
            ->whereDate('planned_at', $date)
            ->first();

        if ($round === null) {
            throw new ModelNotFoundException('Aucun round trouvé pour ce beat et cette date.');
        }

        $stop = BeatStop::where('id', $stopId)
            ->where('beat_round_id', $round->id)
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
        return BeatRound::where('beat_id', $beat->id)
            ->with('vehicle:id,name,plate_number')
            ->withCount([
                'stops as total',
                'stops as completed' => fn ($q) => $q->where('status', BeatStop::STATUS_COMPLETED),
                'stops as cancelled' => fn ($q) => $q->where('status', BeatStop::STATUS_CANCELLED),
                'stops as planned' => fn ($q) => $q->where('status', BeatStop::STATUS_PLANNED),
                'stops as no_sale' => fn ($q) => $q->whereIn('status', BeatStopStatus::noSaleValues()),
                'stops as with_sale' => fn ($q) => $q->where('resulted_in_sale', true),
            ])
            ->orderByDesc('planned_at')
            ->get()
            ->map(function (BeatRound $round) {
                $dateString = $this->castToDateString($round->planned_at);

                return [
                    'id' => $round->id,
                    'date' => $dateString,
                    'label' => $this->formatRoundLabel($dateString),
                    'status' => $this->deriveRoundStatus($dateString, (int) $round->planned),
                    'total' => (int) $round->total,
                    'completed' => (int) $round->completed,
                    'cancelled' => (int) $round->cancelled,
                    'no_sale' => (int) $round->no_sale,
                    'planned' => (int) $round->planned,
                    'with_sale' => (int) $round->with_sale,
                    'strike_rate' => $round->strike_rate ?? (
                        (int) $round->total > 0
                            ? round((int) $round->with_sale / (int) $round->total * 100, 1)
                            : 0.0
                    ),
                    'vehicle' => $round->vehicle ? [
                        'id' => $round->vehicle->id,
                        'name' => $round->vehicle->name,
                        'plate_number' => $round->vehicle->plate_number,
                    ] : null,
                    'odometer_start_km' => $round->odometer_start_km,
                    'odometer_end_km' => $round->odometer_end_km,
                    'distance_km' => $round->distance_km,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Compute the strike rate for a BeatRound.
     *
     * Strike rate = distinct customers who financially engaged on the round's planned_at date
     *               / total distinct customer IDs in the round's stops × 100.
     *
     * A customer is counted when they satisfy at least one of:
     *  - They have a SalesInvoice created on planned_at (new purchase during the round).
     *  - They have a Payment recorded on planned_at (settling a due invoice during the visit).
     *
     * This is the single source of truth for strike rate computation. Both the
     * inline detail view (getRoundCustomers) and the async job
     * (RecalculateBeatRoundStrikeRateJob) delegate to this method.
     *
     * Returns 0.0 when the round has no stops.
     */
    public function calculateStrikeRateForBeatRound(BeatRound $round): float
    {
        $distinctCustomerIdsInRound = BeatStop::where('beat_round_id', $round->id)
            ->whereNotNull('customer_id')
            ->distinct()
            ->pluck('customer_id')
            ->all();

        $totalDistinctCustomers = count($distinctCustomerIdsInRound);

        if ($totalDistinctCustomers === 0) {
            return 0.0;
        }

        $roundDate = Carbon::parse($round->planned_at);
        $roundCustomersFilter = VenteStatsFilter::regardlessOfPaymentStatus()
            ->forCustomers($distinctCustomerIdsInRound);

        $engagedCustomersCount = $this->salesInvoiceStatsService->distinctEngagedCustomersCount(
            $roundDate->copy()->startOfDay(),
            $roundDate->copy()->endOfDay(),
            $roundCustomersFilter,
        );

        return round($engagedCustomersCount / $totalDistinctCustomers * 100, 1);
    }

    public function getCustomersOfBeatRound(Beat $beat, string $date): ?array
    {
        $parsedDate = Carbon::parse($date)->startOfDay();
        $round = $beat->findRoundForDate($parsedDate);

        if ($round === null) {
            return null;
        }

        $beat->getOrGenerateStopsForRound($round);

        $stops = BeatStop::where('beat_round_id', $round->id)
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
        $noSale = $stops->whereIn('status', BeatStopStatus::noSaleValues())->count();

        $totalDebtToCollect = (int) $stops->sum(
            fn (BeatStop $stop) => $stop->customer->salesInvoices->sum('total_remaining')
        );

        $customerIds = $stops->pluck('customer.id')->filter()->all();
        $roundStartOfDay = Carbon::parse($date)->startOfDay();
        $roundEndOfDay = Carbon::parse($date)->endOfDay();
        $roundCustomersFilter = VenteStatsFilter::regardlessOfPaymentStatus()->forCustomers($customerIds);

        $totalCollected = empty($customerIds) ? 0 : $this->salesInvoiceStatsService->totalSales(
            $roundStartOfDay,
            $roundEndOfDay,
            $roundCustomersFilter,
        );

        $strikeRate = $this->calculateStrikeRateForBeatRound($round);

        $buyingCustomersCount = empty($customerIds) ? 0 : $this->salesInvoiceStatsService->distinctEngagedCustomersCount(
            $roundStartOfDay,
            $roundEndOfDay,
            $roundCustomersFilter,
        );

        $round->load('vehicle:id,name,plate_number');

        return [
            'date' => $date,
            'label' => $this->formatRoundLabel($date),
            'status' => $this->deriveRoundStatus($date, $planned),
            'total' => $stops->count(),
            'completed' => $completed,
            'cancelled' => $cancelled,
            'no_sale' => $noSale,
            'planned' => $planned,
            'total_debt_to_collect' => $totalDebtToCollect,
            'total_collected' => (int) $totalCollected,
            'remaining_to_collect' => $totalDebtToCollect - (int) $totalCollected,
            'buying_customers_count' => $buyingCustomersCount,
            'strike_rate' => $strikeRate,
            'vehicle' => $round->vehicle ? [
                'id' => $round->vehicle->id,
                'name' => $round->vehicle->name,
                'plate_number' => $round->vehicle->plate_number,
            ] : null,
            'odometer_start_km' => $round->odometer_start_km,
            'odometer_end_km' => $round->odometer_end_km,
            'distance_km' => $round->distance_km,
            'available_statuses' => array_map(
                fn (BeatStopStatus $statusCase) => ['status' => $statusCase->value, 'label' => $statusCase->label()],
                BeatStopStatus::cases(),
            ),
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
