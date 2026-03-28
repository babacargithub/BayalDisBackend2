<?php

namespace App\Services\Commission;

use App\Data\ActivityReport\NextTierProgressDTO;
use App\Data\Commission\CommissionPeriodData;
use App\Data\Commission\CommissionPeriodSummaryData;
use App\Data\Commission\DailyCommissionSummaryData;
use App\Data\Vente\VenteStatsFilter;
use App\Models\CarLoad;
use App\Models\Commercial;
use App\Models\CommercialWorkPeriod;
use App\Models\CommissionPaymentLine;
use App\Models\CommissionPeriodSetting;
use App\Models\Customer;
use App\Models\DailyCommission;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Services\Abc\AbcVehicleCostService;
use App\Services\SalesInvoiceStatsService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Recalculates the DailyCommission for a single work day within a CommercialWorkPeriod.
 *
 * A DailyCommission record is found or created for (work_period, work_day) and then
 * fully recomputed from all payments made by the commercial on that calendar day.
 *
 * Silently skips when:
 *  - The payment has no linked SalesInvoice
 *  - No Commercial is linked to the payment's user
 *  - No CommercialWorkPeriod covers the payment date
 *  - The covering CommercialWorkPeriod is already finalized
 *
 * Per-day commission breakdown:
 *  - base_commission  : SUM of commission amounts across all payment lines
 *  - basket_bonus     : applied if all required categories were sold that day
 *  - objective_bonus  : highest achieved period tier vs daily encaissement
 *  - total_penalties  : SUM of penalties whose work_day matches this calendar day
 *  - net_commission   : max(0, base + basket + objective − penalties)
 */
readonly class DailyCommissionService
{
    public function __construct(
        private CommissionCalculatorService $commissionCalculatorService,
        private AbcVehicleCostService $abcVehicleCostService,
        private SalesInvoiceStatsService $salesInvoiceStatsService,
    ) {}

    /**
     * Entry point called from RecalculateDailyCommissionJob after a Payment is saved or deleted.
     * Accepts primitive data rather than the Payment model so it works safely after deletion.
     * Resolves the commercial and work period, then delegates to recalculateDailyCommissionForWorkDay().
     *
     * @throws Throwable
     */
    public function recalculateDailyCommissionForPaymentData(
        int $userId,
        string $workDay,
        ?int $salesInvoiceId,
    ): void {
        if ($salesInvoiceId === null) {
            return;
        }

        $commercial = Commercial::where('user_id', $userId)->first();

        if ($commercial === null) {
            return;
        }

        // Use whereDate so that date-only columns stored as '2026-03-02 00:00:00' in
        // SQLite (test DB) still compare correctly against the plain date string.
        $workPeriod = CommercialWorkPeriod::findOrCreateWeeklyPeriodForCommercialOnDate(
            commercialId: $commercial->id,
            date: $workDay,
        );

        if ($workPeriod->is_finalized) {
            return;
        }

        $this->recalculateDailyCommissionForWorkDay($commercial, $workPeriod, $workDay);
    }

    /**
     * Convenience wrapper kept for direct calls in tests and other internal code.
     * Delegates to recalculateDailyCommissionForPaymentData() using the payment's fields.
     *
     * @throws Throwable
     */
    public function recalculateDailyCommissionForPayment(Payment $payment): void
    {
        $this->recalculateDailyCommissionForPaymentData(
            userId: $payment->user_id,
            workDay: $payment->created_at->toDateString(),
            salesInvoiceId: $payment->sales_invoice_id,
        );
    }

    /**
     * Recomputes the DailyCommission for a specific work day.
     * Deletes all existing CommissionPaymentLine records for that day and rebuilds them.
     *
     * @throws Throwable
     */
    public function recalculateDailyCommissionForWorkDay(
        Commercial $commercial,
        CommercialWorkPeriod $workPeriod,
        string $workDay,
    ): DailyCommission {
        return DB::transaction(function () use ($commercial, $workPeriod, $workDay) {
            // Use whereDate so MySQL DATE and SQLite datetime-stored dates both match.
            $dailyCommission = DailyCommission::where('commercial_work_period_id', $workPeriod->id)
                ->whereDate('work_day', $workDay)
                ->first();

            if ($dailyCommission === null) {
                $dailyCommission = DailyCommission::create([
                    'commercial_work_period_id' => $workPeriod->id,
                    'work_day' => $workDay,
                    'base_commission' => 0,
                    'basket_bonus' => 0,
                    'objective_bonus' => 0,
                    'total_penalties' => 0,
                    'net_commission' => 0,
                    'basket_achieved' => false,
                    'basket_multiplier_applied' => null,
                    'achieved_tier_level' => null,
                    'new_confirmed_customers_bonus' => 0,
                    'new_prospect_customers_bonus' => 0,
                ]);
            }

            // Delete existing payment lines so we recompute from scratch.
            $dailyCommission->paymentLines()->delete();

            // Fetch all payments for this commercial on the exact work day.
            $paymentsOnWorkDay = $commercial->payments()
                ->whereDate('created_at', $workDay)
                ->with('salesInvoice')
                ->get();

            // Load the period setting once — needed for per-invoice basket check.
            $periodData = new CommissionPeriodData(
                CarbonImmutable::parse($workPeriod->period_start_date),
                CarbonImmutable::parse($workPeriod->period_end_date),
            );

            $periodSetting = CommissionPeriodSetting::forPeriod($periodData);

            $baseCommission = 0;
            $basketBonus = 0;
            $basketAchieved = false;
            $basketMultiplierApplied = null;
            $allPaymentLines = [];

            foreach ($paymentsOnWorkDay as $payment) {
                /** @var Payment $payment */
                $paymentLines = $this->commissionCalculatorService
                    ->computePaymentLinesForCommercial($payment, $commercial);

                $paymentCommissionAmount = 0;
                $soldCategoryIdsInPayment = [];

                foreach ($paymentLines as $paymentLineData) {
                    $paymentCommissionAmount += $paymentLineData->commissionAmount;
                    $allPaymentLines[] = $paymentLineData;

                    $productCategoryId = Product::find($paymentLineData->productId)?->product_category_id;

                    if ($productCategoryId !== null) {
                        $soldCategoryIdsInPayment[$productCategoryId] = true;
                    }
                }

                $baseCommission += $paymentCommissionAmount;

                // --- Basket bonus per invoice: all required categories must appear in this single invoice ---
                if ($periodSetting !== null && ! empty($periodSetting->required_category_ids)) {
                    $allRequiredCategoriesSoldInThisInvoice = collect($periodSetting->required_category_ids)
                        ->every(fn (int $categoryId) => isset($soldCategoryIdsInPayment[$categoryId]));

                    if ($allRequiredCategoriesSoldInThisInvoice) {
                        $basketAchieved = true;
                        $basketMultiplierApplied = (float) $periodSetting->basket_multiplier;
                        $basketBonus += (int) round($paymentCommissionAmount * ($basketMultiplierApplied - 1));
                    }
                }
            }

            // --- Objective bonus (daily encaissement vs period tiers) ---
            $dailyEncaissement = $paymentsOnWorkDay->sum('amount');

            $highestAchievedTier = $workPeriod->objectiveTiers()
                ->where('ca_threshold', '<=', $dailyEncaissement)
                ->orderByDesc('tier_level')
                ->first();

            $objectiveBonus = $highestAchievedTier?->bonus_amount ?? 0;
            $achievedTierLevel = $highestAchievedTier?->tier_level;

            // --- Penalties (only those assigned to this specific work day) ---
            $totalPenalties = $workPeriod->penalties()
                ->whereDate('work_day', $workDay)
                ->sum('amount');

            // --- New customer bonuses ---
            $newCustomerBonuses = $this->computeNewCustomerBonusesForDay($workPeriod, $workDay);
            $newConfirmedCustomersBonus = $newCustomerBonuses['confirmed'];
            $newProspectCustomersBonus = $newCustomerBonuses['prospect'];

            // --- Mandatory daily threshold ---
            $workDayActivityReport = $this->salesInvoiceStatsService->buildCommercialActivityReport(
                $commercial,
                Carbon::parse($workDay)->startOfDay(),
                Carbon::parse($workDay)->endOfDay(),
            );
            $mandatoryDailySales = $workDayActivityReport->totalSales;

            $thresholdData = $this->computeMandatoryDailyThresholdForWorkDay($commercial, $workDay);
            $mandatoryDailyThreshold = $thresholdData['threshold'];
            $cachedAverageMarginRate = $thresholdData['margin_rate'];
            $mandatoryThresholdReached = !($mandatoryDailyThreshold > 0) || $mandatoryDailySales >= $mandatoryDailyThreshold;

            $netCommission = max(
                0,
                $baseCommission
                + $basketBonus
                + $objectiveBonus
                + $newConfirmedCustomersBonus
                + $newProspectCustomersBonus
                - $totalPenalties,
            );

            // --- Persist DailyCommission ---
            $dailyCommission->update([
                'base_commission' => $baseCommission,
                'basket_bonus' => $basketBonus,
                'objective_bonus' => $objectiveBonus,
                'total_penalties' => $totalPenalties,
                'new_confirmed_customers_bonus' => $newConfirmedCustomersBonus,
                'new_prospect_customers_bonus' => $newProspectCustomersBonus,
                'mandatory_daily_threshold' => $mandatoryDailyThreshold,
                'mandatory_threshold_reached' => $mandatoryThresholdReached,
                'cached_average_margin_rate' => $cachedAverageMarginRate,
                'net_commission' => $netCommission,
                'basket_achieved' => $basketAchieved,
                'basket_multiplier_applied' => $basketMultiplierApplied,
                'achieved_tier_level' => $achievedTierLevel,
            ]);

            // --- Persist payment lines ---
            foreach ($allPaymentLines as $paymentLineData) {
                CommissionPaymentLine::create([
                    'daily_commission_id' => $dailyCommission->id,
                    'payment_id' => $paymentLineData->paymentId,
                    'product_id' => $paymentLineData->productId,
                    'rate_applied' => $paymentLineData->rateApplied,
                    'payment_amount_allocated' => $paymentLineData->paymentAmountAllocated,
                    'commission_amount' => $paymentLineData->commissionAmount,
                ]);
            }

            return $dailyCommission->fresh();
        });
    }

    /**
     * Computes new-customer bonuses earned by the commercial on a specific work day.
     *
     * Counts customers where:
     *  - commercial_id = the work period's commercial
     *  - DATE(created_at) = work_day
     *  - is_prospect = false → confirmed customer
     *  - is_prospect = true → prospect customer
     *
     * Returns ['confirmed' => int, 'prospect' => int].
     * Both are 0 when no CommercialNewCustomerCommissionSetting exists for the commercial.
     *
     * @return array{confirmed: int, prospect: int}
     */
    public function computeNewCustomerBonusesForDay(
        CommercialWorkPeriod $workPeriod,
        string $workDay,
    ): array {
        $setting = $workPeriod->commercial->newCustomerCommissionSetting;

        if ($setting === null) {
            return ['confirmed' => 0, 'prospect' => 0];
        }

        $commercial = $workPeriod->commercial;

        $counts = Customer::where('commercial_id', $commercial->id)
            ->whereDate('created_at', $workDay)
            ->selectRaw('
            SUM(CASE WHEN is_prospect = 0 THEN 1 ELSE 0 END) as confirmed_count,
            SUM(CASE WHEN is_prospect = 1 THEN 1 ELSE 0 END) as prospect_count
        ')
            ->first();

        $newConfirmedCustomersCount = $counts->confirmed_count ?? 0;
        $newProspectCustomersCount = $counts->prospect_count ?? 0;

        return [
            'confirmed' => $newConfirmedCustomersCount * $setting->confirmed_customer_bonus,
            'prospect' => $newProspectCustomersCount * $setting->prospect_customer_bonus,
        ];
    }

    /**
     * Finds the CarLoad that was active for the commercial's team on a given work day.
     *
     * Looks for a CarLoad whose load_date <= workDay and whose return_date is null
     * (still active) or >= workDay (not yet returned). Returns the most recent one
     * by load_date when multiple overlap (e.g. back-to-back trips).
     */
    private function findActiveCarLoadForCommercialOnWorkDay(
        Commercial $commercial,
        string $workDay,
    ): ?CarLoad {
        if ($commercial->team_id === null) {
            return null;
        }

        return CarLoad::where('team_id', $commercial->team_id)
            ->whereDate('load_date', '<=', $workDay)
            ->where(fn ($query) => $query->whereNull('return_date')->orWhereDate('return_date', '>=', $workDay))
            ->latest('load_date')
            ->first();
    }

    /**
     * Computes the global average margin rate across all existing Vente records.
     *
     * Formula: SUM(profit) / SUM(price × quantity)
     *
     * Returns null when there are no sales or total revenue is zero (cannot compute margin).
     */
    private function computeAverageMarginRateFromAllSales(): ?float
    {
        $allSalesFilter = VenteStatsFilter::regardlessOfPaymentStatus();
        $totalRevenue = $this->salesInvoiceStatsService->totalSales(null, null, $allSalesFilter);
        $totalProfit = $this->salesInvoiceStatsService->totalEstimatedProfits(null, null, $allSalesFilter);

        if ($totalRevenue === 0) {
            return null;
        }

        return (float) $totalProfit / (float) $totalRevenue;
    }

    /**
     * Computes the mandatory daily sales threshold for a commercial on a given work day.
     *
     * The threshold is the minimum invoiced sales revenue needed so that the margin
     * from those sales covers the car load's daily operating cost.
     *
     * Formula: threshold = ceil(daily_total_cost / average_margin_rate)
     * Example: cost = 15,000 XOF, margin = 30% → threshold = ceil(15000 / 0.30) = 50,000 XOF
     *
     * Returns ['threshold' => 0, 'margin_rate' => null] when:
     *  - No active CarLoad was found for the commercial's team on that day
     *  - The CarLoad has zero daily cost
     *  - No sales data exists to compute an average margin rate
     *  - The average margin rate is zero or negative
     *
     * @return array{threshold: int, margin_rate: float|null}
     */
    public function computeMandatoryDailyThresholdForWorkDay(
        Commercial $commercial,
        string $workDay,
    ): array {
        $activeCarLoad = $this->findActiveCarLoadForCommercialOnWorkDay($commercial, $workDay);

        if ($activeCarLoad === null) {
            return ['threshold' => 0, 'margin_rate' => null];
        }

        $dailyTotalCost = $this->abcVehicleCostService->computeDailyFixedAndVariableVehicleCostForCarLoad($activeCarLoad);

        if ($dailyTotalCost === 0) {
            return ['threshold' => 0, 'margin_rate' => null];
        }

        $averageMarginRate = $this->computeAverageMarginRateFromAllSales();

        if ($averageMarginRate === null || $averageMarginRate <= 0.0) {
            return ['threshold' => 0, 'margin_rate' => $averageMarginRate];
        }

        $threshold = (int) ceil($dailyTotalCost / $averageMarginRate);

        return ['threshold' => $threshold, 'margin_rate' => $averageMarginRate];
    }

    /**
     * Builds a structured overview of commissions for the current month, organised
     * into three sections that a salesperson's mobile app can render at once.
     *
     * daily   – One entry per calendar day from the 1st of the target month up to
     *           and including today (upcoming days are omitted).
     *           Ordered latest-first (today at index 0).
     *
     * weekly  – One entry per Monday–Sunday week that overlaps the target month,
     *           up to and including the week containing today (future weeks omitted).
     *           Weeks that bleed into the previous month are included in full so
     *           their commissions are always accurate.
     *           Ordered latest-first (current week at index 0).
     *
     * monthly – One entry per calendar month from the earliest stored DailyCommission
     *           for this commercial up to and including the target month.
     *           Returns only the target month when no historical records exist.
     *           Ordered latest-first (current month at index 0).
     *
     * All commission amounts are integers (XOF). Days with no DailyCommission record
     * are zero-filled so the mobile app never has to handle missing dates.
     *
     * @return array{
     *   daily: array<int, array{date: string, commissions_earned: int, total_penalties: int}>,
     *   weekly: array<int, array{start_date: string, end_date: string, commissions_earned: int, total_penalties: int}>,
     *   monthly: array<int, array{year: int, month: int, commissions_earned: int, total_penalties: int}>,
     * }
     */
    public function getCommissionOverviewForMonth(
        Commercial $commercial,
        CarbonImmutable $targetMonth,
    ): array {
        $monthStart = $targetMonth->startOfMonth();
        $today = CarbonImmutable::now()->startOfDay();

        // Daily and weekly sections stop at today, not end-of-month.
        $dailyCutoff = $today->lt($targetMonth->endOfMonth()) ? $today : $targetMonth->endOfMonth();

        $workPeriodIds = CommercialWorkPeriod::where('commercial_id', $commercial->id)->pluck('id');

        // Single query – load every DailyCommission record for this commercial.
        // Keyed by 'YYYY-MM-DD' for O(1) lookups in the per-day loops below.
        $allDailyCommissions = DailyCommission::whereIn('commercial_work_period_id', $workPeriodIds)->get();

        $allDailyCommissionsKeyedByDate = $allDailyCommissions
            ->keyBy(fn (DailyCommission $dailyCommission) => $dailyCommission->work_day->toDateString());

        // ─── Daily section ────────────────────────────────────────────────────────
        // One entry per calendar day from the 1st of the month to today.
        // Built chronologically then reversed so the most recent day is first.
        $dailyEntries = [];
        $dayCursor = $monthStart;

        while ($dayCursor->lte($dailyCutoff)) {
            $dateString = $dayCursor->toDateString();
            $dailyCommission = $allDailyCommissionsKeyedByDate->get($dateString);

            $dailyEntries[] = [
                'date' => $dateString,
                'commissions_earned' => $dailyCommission?->net_commission ?? 0,
                'total_penalties' => $dailyCommission?->total_penalties ?? 0,
            ];

            $dayCursor = $dayCursor->addDay();
        }

        $dailyEntries = array_reverse($dailyEntries);

        // ─── Weekly section ───────────────────────────────────────────────────────
        // Every Monday–Sunday week that overlaps the target month, stopping at the
        // week that contains today (future weeks are excluded).
        // Weeks that bleed into the previous month are included in full.
        // Built chronologically then reversed so the current week is first.
        $weeklyEntries = [];
        $firstWeekMonday = $monthStart->startOfWeek();      // Mon on or before 1st of month
        $currentWeekMonday = $dailyCutoff->startOfWeek();   // Mon of the week containing today

        $weekStartCursor = $firstWeekMonday;

        while ($weekStartCursor->lte($currentWeekMonday)) {
            $weekEnd = $weekStartCursor->endOfWeek();
            $weekCommissionsEarned = 0;
            $weekTotalPenalties = 0;

            $weekDayCursor = $weekStartCursor;

            while ($weekDayCursor->lte($weekEnd)) {
                $dailyCommission = $allDailyCommissionsKeyedByDate->get($weekDayCursor->toDateString());
                $weekCommissionsEarned += $dailyCommission?->net_commission ?? 0;
                $weekTotalPenalties += $dailyCommission?->total_penalties ?? 0;
                $weekDayCursor = $weekDayCursor->addDay();
            }

            $weeklyEntries[] = [
                'start_date' => $weekStartCursor->toDateString(),
                'end_date' => $weekEnd->toDateString(),
                'commissions_earned' => $weekCommissionsEarned,
                'total_penalties' => $weekTotalPenalties,
            ];

            $weekStartCursor = $weekStartCursor->addWeek();
        }

        $weeklyEntries = array_reverse($weeklyEntries);

        // ─── Monthly section ──────────────────────────────────────────────────────
        // All calendar months from the commercial's earliest commission up to now.
        // Falls back to just the target month when no historical records exist.
        // Built chronologically then reversed so the most recent month is first.
        $monthlyEntries = [];
        $monthEnd = $targetMonth->endOfMonth();

        if ($allDailyCommissions->isNotEmpty()) {
            $earliestDateString = $allDailyCommissionsKeyedByDate->keys()->sort()->first();
            $firstAvailableMonth = CarbonImmutable::parse($earliestDateString)->startOfMonth();
        } else {
            $firstAvailableMonth = $monthStart;
        }

        // Group all records once by 'YYYY-MM' to avoid iterating the full set per month.
        $allDailyCommissionsGroupedByYearMonth = $allDailyCommissions->groupBy(
            fn (DailyCommission $dailyCommission) => $dailyCommission->work_day->format('Y-m'),
        );

        $monthCursor = $firstAvailableMonth;

        while ($monthCursor->lte($monthEnd)) {
            $yearMonthKey = $monthCursor->format('Y-m');
            $monthDailyCommissions = $allDailyCommissionsGroupedByYearMonth->get($yearMonthKey, collect());

            $monthlyEntries[] = [
                'year' => $monthCursor->year,
                'month' => $monthCursor->month,
                'commissions_earned' => (int) $monthDailyCommissions->sum('net_commission'),
                'total_penalties' => (int) $monthDailyCommissions->sum('total_penalties'),
            ];

            $monthCursor = $monthCursor->addMonth();
        }

        $monthlyEntries = array_reverse($monthlyEntries);

        return [
            'daily' => $dailyEntries,
            'weekly' => $weeklyEntries,
            'monthly' => $monthlyEntries,
        ];
    }

    /**
     * Builds a detailed commission breakdown for a specific period.
     *
     * Returns three sections:
     *  - period   : type (daily|weekly|monthly), start_date, end_date
     *  - summary  : aggregated totals across all DailyCommission records in the period.
     *               mandatory_threshold_reached is true only if every day with a non-zero
     *               threshold actually reached it.
     *  - days     : one entry per DailyCommission record found in the period (days without
     *               any record are omitted). Ordered by work_day descending (latest first).
     *
     * mandatory_daily_sales in both summary and days entries is sourced from SalesInvoice
     * totals (not stored on DailyCommission) so it always reflects the invoiced amounts.
     *
     * @return array{
     *   period: array{type: string, start_date: string, end_date: string},
     *   summary: array{
     *     base_commission: int,
     *     basket_bonus: int,
     *     objective_bonus: int,
     *     new_confirmed_customers_bonus: int,
     *     new_prospect_customers_bonus: int,
     *     total_penalties: int,
     *     net_commission: int,
     *     mandatory_daily_sales: int,
     *     mandatory_daily_threshold: int,
     *     mandatory_threshold_reached: bool,
     *   },
     *   days: array<int, array{
     *     date: string,
     *     base_commission: int,
     *     basket_bonus: int,
     *     objective_bonus: int,
     *     new_confirmed_customers_bonus: int,
     *     new_prospect_customers_bonus: int,
     *     total_penalties: int,
     *     net_commission: int,
     *     mandatory_daily_sales: int,
     *     mandatory_daily_threshold: int,
     *     mandatory_threshold_reached: bool,
     *   }>,
     * }
     */
    public function getCommissionDetailForPeriod(
        Commercial $commercial,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        string $periodType,
    ): array {
        $startDateString = $startDate->toDateString();
        $endDateString = $endDate->toDateString();

        $workPeriodIds = CommercialWorkPeriod::where('commercial_id', $commercial->id)->pluck('id');

        // All DailyCommission records in the period, ordered latest first.
        $dailyCommissionsInPeriod = DailyCommission::whereIn('commercial_work_period_id', $workPeriodIds)
            ->whereDate('work_day', '>=', $startDateString)
            ->whereDate('work_day', '<=', $endDateString)
            ->orderBy('work_day', 'desc')
            ->get();

        // Per-day SalesInvoice totals for mandatory_daily_sales (not stored on DailyCommission).
        // toBase() bypasses Eloquent model hydration, preventing accessor conflicts.
        $invoiceTotalsByDate = SalesInvoice::where('commercial_id', $commercial->id)
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->selectRaw('DATE(created_at) as day, SUM(total_amount) as day_total')
            ->groupBy('day')
            ->toBase()
            ->pluck('day_total', 'day');

        $periodTotalMandatoryDailySales = (int) $invoiceTotalsByDate->sum();

        // ─── Days array ───────────────────────────────────────────────────────────
        // Only days that have a DailyCommission record (already in desc order).
        $days = $dailyCommissionsInPeriod->map(function (DailyCommission $dailyCommission) use ($invoiceTotalsByDate) {
            $dateString = $dailyCommission->work_day->toDateString();

            return [
                'date' => $dateString,
                'base_commission' => $dailyCommission->base_commission,
                'basket_bonus' => $dailyCommission->basket_bonus,
                'objective_bonus' => $dailyCommission->objective_bonus,
                'new_confirmed_customers_bonus' => $dailyCommission->new_confirmed_customers_bonus ?? 0,
                'new_prospect_customers_bonus' => $dailyCommission->new_prospect_customers_bonus ?? 0,
                'total_penalties' => $dailyCommission->total_penalties,
                'net_commission' => $dailyCommission->net_commission,
                'mandatory_daily_sales' => (int) ($invoiceTotalsByDate[$dateString] ?? 0),
                'mandatory_daily_threshold' => $dailyCommission->mandatory_daily_threshold ?? 0,
                'mandatory_threshold_reached' => (bool) $dailyCommission->mandatory_threshold_reached,
            ];
        })->values()->all();

        // ─── Summary ──────────────────────────────────────────────────────────────
        // mandatory_threshold_reached: true only when every day whose threshold is
        // non-zero actually reached it (days with no threshold are not a failure).
        $daysWithNonZeroThreshold = $dailyCommissionsInPeriod
            ->filter(fn (DailyCommission $dc) => ($dc->mandatory_daily_threshold ?? 0) > 0);

        $allDaysWithThresholdReachedIt =
            ! $daysWithNonZeroThreshold->isNotEmpty() || $daysWithNonZeroThreshold->every(fn (DailyCommission $dc) => (bool) $dc->mandatory_threshold_reached);

        $summary = [
            'base_commission' => (int) $dailyCommissionsInPeriod->sum('base_commission'),
            'basket_bonus' => (int) $dailyCommissionsInPeriod->sum('basket_bonus'),
            'objective_bonus' => (int) $dailyCommissionsInPeriod->sum('objective_bonus'),
            'new_confirmed_customers_bonus' => (int) $dailyCommissionsInPeriod->sum('new_confirmed_customers_bonus'),
            'new_prospect_customers_bonus' => (int) $dailyCommissionsInPeriod->sum('new_prospect_customers_bonus'),
            'total_penalties' => (int) $dailyCommissionsInPeriod->sum('total_penalties'),
            'net_commission' => (int) $dailyCommissionsInPeriod->sum('net_commission'),
            'mandatory_daily_sales' => $periodTotalMandatoryDailySales,
            'mandatory_daily_threshold' => (int) $dailyCommissionsInPeriod->sum('mandatory_daily_threshold'),
            'mandatory_threshold_reached' => $allDaysWithThresholdReachedIt,
        ];

        return [
            'period' => [
                'type' => $periodType,
                'start_date' => $startDateString,
                'end_date' => $endDateString,
            ],
            'summary' => $summary,
            'days' => $days,
        ];
    }

    /**
     * Recomputes all daily commissions for every day that has payments in the work period,
     * plus any existing DailyCommission records (to zero out days where payments were removed).
     *
     * Called by the manager's "Recalculer" button (e.g., after commission rate changes).
     *
     * @throws RuntimeException if the work period is already finalized
     * @throws Throwable
     */
    public function recomputeAllDaysForWorkPeriod(CommercialWorkPeriod $workPeriod): void
    {
        if ($workPeriod->is_finalized) {
            throw new RuntimeException(
                "La période de travail #{$workPeriod->id} est finalisée et ne peut pas être recalculée."
            );
        }

        $commercial = $workPeriod->commercial;

        // Collect all dates that have payments in this period.
        $paymentDatesWithPayments = $commercial->payments()
            ->whereBetween('created_at', [
                $workPeriod->period_start_date->startOfDay(),
                $workPeriod->period_end_date->endOfDay(),
            ])
            ->selectRaw('DATE(created_at) as work_day')
            ->distinct()
            ->pluck('work_day');

        // Also include days that already have a DailyCommission (to zero-out removed-payment days).
        $existingDailyCommissionDays = $workPeriod->dailyCommissions()
            ->pluck('work_day')
            ->map(fn ($workDay) => $workDay->toDateString());

        $allWorkDaysToProcess = $paymentDatesWithPayments
            ->merge($existingDailyCommissionDays)
            ->unique()
            ->sort()
            ->values();

        foreach ($allWorkDaysToProcess as $workDay) {
            $this->recalculateDailyCommissionForWorkDay($commercial, $workPeriod, $workDay);
        }
    }

    /**
     * Lock a work period so no further daily commission recalculations can be triggered.
     * Once finalized, the background job will silently skip any payment in this period.
     *
     * @throws RuntimeException if the work period is already finalized
     */
    public function finalizeWorkPeriod(CommercialWorkPeriod $workPeriod): CommercialWorkPeriod
    {
        if ($workPeriod->is_finalized) {
            throw new RuntimeException(
                "La période de travail #{$workPeriod->id} est déjà finalisée."
            );
        }

        $workPeriod->update([
            'is_finalized' => true,
            'finalized_at' => now(),
        ]);

        return $workPeriod->fresh();
    }

    /**
     * Builds a DailyCommissionSummaryData for the given commercial on a specific calendar day.
     *
     * Always returns a populated DTO (never null). When no DailyCommission record exists yet
     * (e.g. no sales or no active work period), all commission fields are returned as zero.
     *
     * - mandatoryDailySales : sum of SalesInvoice total_amount created by the commercial that day
     * - totalPayments       : sum of Payment amounts collected that day
     * - commissionsEarned   : net_commission from the DailyCommission record
     * - totalPenalties      : total penalties deducted that day
     * - tierBonus           : objective bonus from the highest achieved CA tier
     * - reachedTierLevel    : tier level number reached (null if none)
     * - basketBonus         : bonus earned from selling all required categories in one invoice
     */
    public function getDailyCommissionSummary(
        Commercial $commercial,
        string $workDay,
    ): DailyCommissionSummaryData {
        $workDayActivityReport = $this->salesInvoiceStatsService->buildCommercialActivityReport(
            $commercial,
            Carbon::parse($workDay)->startOfDay(),
            Carbon::parse($workDay)->endOfDay(),
        );
        $mandatoryDailySales = $workDayActivityReport->totalSales;
        $totalPayments = $workDayActivityReport->totalPayments;

        $workPeriod = CommercialWorkPeriod::query()
            ->where('commercial_id', $commercial->id)
            ->whereDate('period_start_date', '<=', $workDay)
            ->whereDate('period_end_date', '>=', $workDay)
            ->first();

        if ($workPeriod === null) {
            return new DailyCommissionSummaryData(
                mandatoryDailySales: $mandatoryDailySales,
                totalPayments: $totalPayments,
                commissionsEarned: 0,
                totalPenalties: 0,
                tierBonus: 0,
                reachedTierLevel: null,
                basketBonus: 0,
                newConfirmedCustomersCount: 0,
                newProspectCustomersCount: 0,
                newConfirmedCustomersBonus: 0,
                newProspectCustomersBonus: 0,
                mandatoryDailyThreshold: 0,
                mandatoryThresholdReached: true,
                cachedAverageMarginRate: null,
            );
        }

        $dailyCommission = DailyCommission::where('commercial_work_period_id', $workPeriod->id)
            ->whereDate('work_day', $workDay)
            ->first();

        if ($dailyCommission === null) {
            return new DailyCommissionSummaryData(
                mandatoryDailySales: $mandatoryDailySales,
                totalPayments: $totalPayments,
                commissionsEarned: 0,
                totalPenalties: 0,
                tierBonus: 0,
                reachedTierLevel: null,
                basketBonus: 0,
                newConfirmedCustomersCount: 0,
                newProspectCustomersCount: 0,
                newConfirmedCustomersBonus: 0,
                newProspectCustomersBonus: 0,
                mandatoryDailyThreshold: 0,
                mandatoryThresholdReached: true,
                cachedAverageMarginRate: null,
            );
        }

        $newConfirmedCustomersCount = Customer::where('commercial_id', $workPeriod->commercial_id)
            ->where('is_prospect', false)
            ->whereDate('created_at', $workDay)
            ->count();

        $newProspectCustomersCount = Customer::where('commercial_id', $workPeriod->commercial_id)
            ->where('is_prospect', true)
            ->whereDate('created_at', $workDay)
            ->count();

        return new DailyCommissionSummaryData(
            mandatoryDailySales: $mandatoryDailySales,
            totalPayments: $totalPayments,
            commissionsEarned: $dailyCommission->net_commission,
            totalPenalties: $dailyCommission->total_penalties,
            tierBonus: $dailyCommission->objective_bonus,
            reachedTierLevel: $dailyCommission->achieved_tier_level,
            basketBonus: $dailyCommission->basket_bonus,
            newConfirmedCustomersCount: $newConfirmedCustomersCount,
            newProspectCustomersCount: $newProspectCustomersCount,
            newConfirmedCustomersBonus: $dailyCommission->new_confirmed_customers_bonus,
            newProspectCustomersBonus: $dailyCommission->new_prospect_customers_bonus,
            mandatoryDailyThreshold: $dailyCommission->mandatory_daily_threshold,
            mandatoryThresholdReached: (bool) $dailyCommission->mandatory_threshold_reached,
            cachedAverageMarginRate: $dailyCommission->cached_average_margin_rate !== null
                ? (float) $dailyCommission->cached_average_margin_rate
                : null,
        );
    }

    /**
     * Returns a CommissionPeriodSummaryData aggregating all commissions for the week
     * (Monday–Sunday) that contains the given calendar date.
     *
     * Query parameter: ?date=YYYY-MM-DD (defaults to today)
     */
    public function getWeeklyCommissionSummary(
        Commercial $commercial,
        string $workDay,
    ): CommissionPeriodSummaryData {
        $date = CarbonImmutable::parse($workDay);

        return $this->getCommissionSummaryForDateRange(
            commercial: $commercial,
            startDate: $date->startOfWeek(),
            endDate: $date->endOfWeek(),
        );
    }

    /**
     * Returns a CommissionPeriodSummaryData aggregating all commissions for the calendar
     * month that contains the given date.
     *
     * Query parameter: ?date=YYYY-MM-DD (defaults to today)
     */
    public function getMonthlyCommissionSummary(
        Commercial $commercial,
        string $workDay,
    ): CommissionPeriodSummaryData {
        $date = CarbonImmutable::parse($workDay);

        return $this->getCommissionSummaryForDateRange(
            commercial: $commercial,
            startDate: $date->startOfMonth(),
            endDate: $date->endOfMonth(),
        );
    }

    /**
     * Core aggregation method used by both getWeeklyCommissionSummary and
     * getMonthlyCommissionSummary (and any other period-based caller).
     *
     * Builds a CommissionPeriodSummaryData by:
     *  1. Summing SalesInvoice totals and Payment totals for the period.
     *  2. Loading all DailyCommission records for the commercial in the range,
     *     across any number of CommercialWorkPeriods that overlap.
     *  3. Summing commission components from those records.
     *  4. Generating a per-calendar-day breakdown (zero-filled for inactive days).
     */
    public function getCommissionSummaryForDateRange(
        Commercial $commercial,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): CommissionPeriodSummaryData {
        $startDateString = $startDate->toDateString();
        $endDateString = $endDate->toDateString();

        // Period-level invoice and payment totals.
        $periodActivityReport = $this->salesInvoiceStatsService->buildCommercialActivityReport(
            $commercial,
            Carbon::parse($startDate->startOfDay()),
            Carbon::parse($endDate->endOfDay()),
        );
        $mandatoryDailySales = $periodActivityReport->totalSales;
        $totalPayments = $periodActivityReport->totalPayments;

        // Collect all work-period IDs for this commercial, then load matching DailyCommissions.
        $workPeriodIds = CommercialWorkPeriod::where('commercial_id', $commercial->id)->pluck('id');

        /** @var \Illuminate\Support\Collection<string, DailyCommission> $dailyCommissionsByDate */
        $dailyCommissionsByDate = DailyCommission::whereIn('commercial_work_period_id', $workPeriodIds)
            ->whereDate('work_day', '>=', $startDateString)
            ->whereDate('work_day', '<=', $endDateString)
            ->get()
            ->keyBy(fn (DailyCommission $dailyCommission) => $dailyCommission->work_day->toDateString());

        // Period-level commission component totals.
        $baseCommission = (int) $dailyCommissionsByDate->sum('base_commission');
        $commissionsEarned = (int) $dailyCommissionsByDate->sum('net_commission');
        $totalPenalties = (int) $dailyCommissionsByDate->sum('total_penalties');
        $tierBonus = (int) $dailyCommissionsByDate->sum('objective_bonus');
        $basketBonus = (int) $dailyCommissionsByDate->sum('basket_bonus');
        $totalNewConfirmedCustomersBonus = (int) $dailyCommissionsByDate->sum('new_confirmed_customers_bonus');
        $totalNewProspectCustomersBonus = (int) $dailyCommissionsByDate->sum('new_prospect_customers_bonus');

        // Period-level new customer counts (queried directly for accuracy).
        $totalNewConfirmedCustomersCount = (int) Customer::where('commercial_id', $commercial->id)
            ->where('is_prospect', false)
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->count();

        $totalNewProspectCustomersCount = (int) Customer::where('commercial_id', $commercial->id)
            ->where('is_prospect', true)
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->count();

        // Per-day new-customer counts grouped by calendar date.
        $newConfirmedCustomerCountsByDate = Customer::where('commercial_id', $commercial->id)
            ->where('is_prospect', false)
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->selectRaw('DATE(created_at) as day, COUNT(*) as day_count')
            ->groupBy('day')
            ->pluck('day_count', 'day');

        $newProspectCustomerCountsByDate = Customer::where('commercial_id', $commercial->id)
            ->where('is_prospect', true)
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->selectRaw('DATE(created_at) as day, COUNT(*) as day_count')
            ->groupBy('day')
            ->pluck('day_count', 'day');

        // Per-day invoice and payment sums (single query each, grouped by calendar date).
        // toBase() bypasses Eloquent model hydration, preventing accessor conflicts (e.g. getTotalAttribute).
        $invoiceTotalsByDate = SalesInvoice::where('commercial_id', $commercial->id)
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->selectRaw('DATE(created_at) as day, SUM(total_amount) as day_total')
            ->groupBy('day')
            ->toBase()
            ->pluck('day_total', 'day');

        $paymentTotalsByDate = $commercial->payments()
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->selectRaw('DATE(created_at) as day, SUM(amount) as day_total')
            ->groupBy('day')
            ->pluck('day_total', 'day');

        // Build one entry per calendar day in the range, zero-filling days with no activity.
        $days = [];
        $totalDaysThresholdReached = 0;
        $totalDaysBelowThreshold = 0;
        $currentDate = $startDate;

        while ($currentDate->lte($endDate)) {
            $dateString = $currentDate->toDateString();
            $dailyCommission = $dailyCommissionsByDate->get($dateString);

            $dayMandatoryDailyThreshold = $dailyCommission?->mandatory_daily_threshold ?? 0;
            $dayMandatoryThresholdReached = $dailyCommission !== null
                ? (bool) $dailyCommission->mandatory_threshold_reached
                : true;
            $dayCachedAverageMarginRate = $dailyCommission?->cached_average_margin_rate !== null
                ? (float) $dailyCommission->cached_average_margin_rate
                : null;

            if ($dailyCommission !== null && $dayMandatoryDailyThreshold > 0) {
                if ($dayMandatoryThresholdReached) {
                    $totalDaysThresholdReached++;
                } else {
                    $totalDaysBelowThreshold++;
                }
            }

            $days[] = [
                'date' => $dateString,
                'mandatory_daily_sales' => (int) ($invoiceTotalsByDate[$dateString] ?? 0),
                'total_payments' => (int) ($paymentTotalsByDate[$dateString] ?? 0),
                'commissions_earned' => $dailyCommission?->net_commission ?? 0,
                'total_penalties' => $dailyCommission?->total_penalties ?? 0,
                'tier_bonus' => $dailyCommission?->objective_bonus ?? 0,
                'reached_tier_level' => $dailyCommission?->achieved_tier_level,
                'basket_bonus' => $dailyCommission?->basket_bonus ?? 0,
                'new_confirmed_customers_count' => (int) ($newConfirmedCustomerCountsByDate[$dateString] ?? 0),
                'new_prospect_customers_count' => (int) ($newProspectCustomerCountsByDate[$dateString] ?? 0),
                'new_confirmed_customers_bonus' => $dailyCommission?->new_confirmed_customers_bonus ?? 0,
                'new_prospect_customers_bonus' => $dailyCommission?->new_prospect_customers_bonus ?? 0,
                'mandatory_daily_threshold' => $dayMandatoryDailyThreshold,
                'mandatory_threshold_reached' => $dayMandatoryThresholdReached,
                'cached_average_margin_rate' => $dayCachedAverageMarginRate,
            ];

            $currentDate = $currentDate->addDay();
        }

        return new CommissionPeriodSummaryData(
            startDate: $startDateString,
            endDate: $endDateString,
            mandatoryDailySales: $mandatoryDailySales,
            totalPayments: $totalPayments,
            baseCommission: $baseCommission,
            commissionsEarned: $commissionsEarned,
            totalPenalties: $totalPenalties,
            tierBonus: $tierBonus,
            basketBonus: $basketBonus,
            totalNewConfirmedCustomersCount: $totalNewConfirmedCustomersCount,
            totalNewProspectCustomersCount: $totalNewProspectCustomersCount,
            totalNewConfirmedCustomersBonus: $totalNewConfirmedCustomersBonus,
            totalNewProspectCustomersBonus: $totalNewProspectCustomersBonus,
            totalDaysThresholdReached: $totalDaysThresholdReached,
            totalDaysBelowThreshold: $totalDaysBelowThreshold,
            days: $days,
        );
    }

    /**
     * Returns the nearest unachieved objective tier for a commercial on a given work day.
     *
     * Looks up the CommercialWorkPeriod that covers the work day, then finds the tier
     * with the lowest ca_threshold that is still above the commercial's current daily
     * encaissement (total payments collected on that day).
     *
     * Returns null when:
     *  - No work period covers the given day
     *  - No tiers are configured for the work period
     *  - All tiers have already been achieved (currentDailyEncaissement >= every ca_threshold)
     */
    public function computeNextTierProgressForCommercialOnDay(
        Commercial $commercial,
        string $workDay,
        int $currentDailyEncaissement,
    ): ?NextTierProgressDTO {
        $workPeriod = CommercialWorkPeriod::query()
            ->where('commercial_id', $commercial->id)
            ->whereDate('period_start_date', '<=', $workDay)
            ->whereDate('period_end_date', '>=', $workDay)
            ->first();

        if ($workPeriod === null) {
            return null;
        }

        $nextTier = $workPeriod->objectiveTiers()
            ->where('ca_threshold', '>', $currentDailyEncaissement)
            ->orderBy('ca_threshold')
            ->first();

        if ($nextTier === null) {
            return null;
        }

        return new NextTierProgressDTO(
            tierLevel: $nextTier->tier_level,
            caThreshold: $nextTier->ca_threshold,
            bonusAmount: $nextTier->bonus_amount,
            missingAmount: $nextTier->ca_threshold - $currentDailyEncaissement,
        );
    }
}
