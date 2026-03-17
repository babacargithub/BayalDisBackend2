<?php

namespace App\Services\Commission;

use App\Data\Commission\CommissionPeriodData;
use App\Data\Commission\CommissionPeriodSummaryData;
use App\Data\Commission\DailyCommissionSummaryData;
use App\Models\Commercial;
use App\Models\CommercialWorkPeriod;
use App\Models\CommissionPaymentLine;
use App\Models\CommissionPeriodSetting;
use App\Models\DailyCommission;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesInvoice;
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
        $workPeriod = CommercialWorkPeriod::query()
            ->where('commercial_id', $commercial->id)
            ->whereDate('period_start_date', '<=', $workDay)
            ->whereDate('period_end_date', '>=', $workDay)
            ->first();

        if ($workPeriod === null || $workPeriod->is_finalized) {
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

            $netCommission = max(0, $baseCommission + $basketBonus + $objectiveBonus - $totalPenalties);

            // --- Persist DailyCommission ---
            $dailyCommission->update([
                'base_commission' => $baseCommission,
                'basket_bonus' => $basketBonus,
                'objective_bonus' => $objectiveBonus,
                'total_penalties' => $totalPenalties,
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
        $mandatoryDailySales = SalesInvoice::where('commercial_id', $commercial->id)
            ->whereDate('created_at', $workDay)
            ->sum('total_amount');

        $totalPayments = $commercial->payments()
            ->whereDate('created_at', $workDay)
            ->sum('amount');

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
            );
        }

        return new DailyCommissionSummaryData(
            mandatoryDailySales: $mandatoryDailySales,
            totalPayments: $totalPayments,
            commissionsEarned: $dailyCommission->net_commission,
            totalPenalties: $dailyCommission->total_penalties,
            tierBonus: $dailyCommission->objective_bonus,
            reachedTierLevel: $dailyCommission->achieved_tier_level,
            basketBonus: $dailyCommission->basket_bonus,
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
        $mandatoryDailySales = (int) SalesInvoice::where('commercial_id', $commercial->id)
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->sum('total_amount');

        $totalPayments = (int) $commercial->payments()
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->sum('amount');

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
        $currentDate = $startDate;

        while ($currentDate->lte($endDate)) {
            $dateString = $currentDate->toDateString();
            $dailyCommission = $dailyCommissionsByDate->get($dateString);

            $days[] = [
                'date' => $dateString,
                'mandatory_daily_sales' => (int) ($invoiceTotalsByDate[$dateString] ?? 0),
                'total_payments' => (int) ($paymentTotalsByDate[$dateString] ?? 0),
                'commissions_earned' => $dailyCommission?->net_commission ?? 0,
                'total_penalties' => $dailyCommission?->total_penalties ?? 0,
                'tier_bonus' => $dailyCommission?->objective_bonus ?? 0,
                'reached_tier_level' => $dailyCommission?->achieved_tier_level,
                'basket_bonus' => $dailyCommission?->basket_bonus ?? 0,
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
            days: $days,
        );
    }
}
