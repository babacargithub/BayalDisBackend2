<?php

namespace App\Data\Commission;

/**
 * Aggregated commission summary for a date range (week, month, or any custom period).
 * Returned by DailyCommissionService::getWeeklyCommissionSummary() and
 * getMonthlyCommissionSummary(), then serialised via toArray() by the salesperson API.
 *
 * Period-level totals:
 *  - startDate                          : ISO date string (inclusive) for the period start
 *  - endDate                            : ISO date string (inclusive) for the period end
 *  - mandatoryDailySales                : total SalesInvoice amounts created by the commercial in the period
 *  - totalPayments                      : total Payment amounts collected in the period (encaissement)
 *  - baseCommission                     : raw commission before bonuses and penalties
 *  - commissionsEarned                  : net commission after all bonuses and penalties
 *  - totalPenalties                     : sum of all penalty amounts deducted in the period
 *  - tierBonus                          : total objective bonus across all days
 *  - basketBonus                        : total basket bonus across all days
 *  - totalNewConfirmedCustomersCount    : total confirmed customers created across the period
 *  - totalNewProspectCustomersCount     : total prospect customers created across the period
 *  - totalNewConfirmedCustomersBonus    : total bonus for confirmed new customers across the period
 *  - totalNewProspectCustomersBonus     : total bonus for new prospect customers across the period
 *  - totalDaysThresholdReached          : number of days the mandatory daily threshold was reached
 *  - totalDaysBelowThreshold            : number of days the mandatory daily threshold was NOT reached
 *  - days                               : per-day breakdown, one entry per calendar day in the range
 *                                         (zero-filled for days with no activity)
 */
readonly class CommissionPeriodSummaryData
{
    /**
     * @param array<int, array{
     *   date: string,
     *   mandatory_daily_sales: int,
     *   total_payments: int,
     *   commissions_earned: int,
     *   total_penalties: int,
     *   tier_bonus: int,
     *   reached_tier_level: int|null,
     *   basket_bonus: int,
     *   new_confirmed_customers_count: int,
     *   new_prospect_customers_count: int,
     *   new_confirmed_customers_bonus: int,
     *   new_prospect_customers_bonus: int,
     *   mandatory_daily_threshold: int,
     *   mandatory_threshold_reached: bool,
     *   cached_average_margin_rate: float|null,
     * }> $days
     */
    public function __construct(
        public string $startDate,
        public string $endDate,
        public int $mandatoryDailySales,
        public int $totalPayments,
        public int $baseCommission,
        public int $commissionsEarned,
        public int $totalPenalties,
        public int $tierBonus,
        public int $basketBonus,
        public int $totalNewConfirmedCustomersCount,
        public int $totalNewProspectCustomersCount,
        public int $totalNewConfirmedCustomersBonus,
        public int $totalNewProspectCustomersBonus,
        public int $totalDaysThresholdReached,
        public int $totalDaysBelowThreshold,
        public array $days,
    ) {}

    public function toArray(): array
    {
        return [
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'mandatory_daily_sales' => $this->mandatoryDailySales,
            'total_payments' => $this->totalPayments,
            'base_commission' => $this->baseCommission,
            'commissions_earned' => $this->commissionsEarned,
            'total_penalties' => $this->totalPenalties,
            'tier_bonus' => $this->tierBonus,
            'basket_bonus' => $this->basketBonus,
            'total_new_confirmed_customers_count' => $this->totalNewConfirmedCustomersCount,
            'total_new_prospect_customers_count' => $this->totalNewProspectCustomersCount,
            'total_new_confirmed_customers_bonus' => $this->totalNewConfirmedCustomersBonus,
            'total_new_prospect_customers_bonus' => $this->totalNewProspectCustomersBonus,
            'total_days_threshold_reached' => $this->totalDaysThresholdReached,
            'total_days_below_threshold' => $this->totalDaysBelowThreshold,
            'days' => $this->days,
        ];
    }
}
