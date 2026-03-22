<?php

namespace App\Data\Commission;

/**
 * Summary of a commercial's commission activity for a single calendar day.
 * Returned by DailyCommissionService::getDailyCommissionSummary() and
 * serialised via toArray() by the salesperson API endpoint.
 *
 * Field meanings:
 *  - mandatoryDailySales          : total SalesInvoice amounts created by this commercial that day
 *                                   (invoiced sales, whether paid yet or not)
 *  - totalPayments                : total Payment amounts collected that day (encaissement)
 *  - commissionsEarned            : net commission after all bonuses and penalties
 *                                   (base + basketBonus + tierBonus + newCustomerBonuses − totalPenalties)
 *  - totalPenalties               : sum of penalty amounts deducted from gross commission
 *  - tierBonus                    : objective bonus from the highest achieved CA tier
 *  - reachedTierLevel             : tier level number that was reached (null = no tier achieved)
 *  - basketBonus                  : multiplier bonus earned when all required categories were
 *                                   sold within a single invoice
 *  - newConfirmedCustomersCount   : number of confirmed customers created by this commercial that day
 *  - newProspectCustomersCount    : number of prospect customers created by this commercial that day
 *  - newConfirmedCustomersBonus   : bonus earned for confirmed new customers
 *  - newProspectCustomersBonus    : bonus earned for new prospect customers
 *  - mandatoryDailyThreshold      : minimum sales revenue required to cover daily car load cost
 *                                   (0 when no active car load or no cost data available)
 *  - mandatoryThresholdReached    : whether mandatoryDailySales >= mandatoryDailyThreshold
 *  - cachedAverageMarginRate      : average margin rate used to compute the threshold (null if unavailable)
 */
readonly class DailyCommissionSummaryData
{
    public function __construct(
        public int $mandatoryDailySales,
        public int $totalPayments,
        public int $commissionsEarned,
        public int $totalPenalties,
        public int $tierBonus,
        public ?int $reachedTierLevel,
        public int $basketBonus,
        public int $newConfirmedCustomersCount,
        public int $newProspectCustomersCount,
        public int $newConfirmedCustomersBonus,
        public int $newProspectCustomersBonus,
        public int $mandatoryDailyThreshold,
        public bool $mandatoryThresholdReached,
        public ?float $cachedAverageMarginRate,
    ) {}

    public function toArray(): array
    {
        return [
            'mandatory_daily_sales' => $this->mandatoryDailySales,
            'total_payments' => $this->totalPayments,
            'commissions_earned' => $this->commissionsEarned,
            'total_penalties' => $this->totalPenalties,
            'tier_bonus' => $this->tierBonus,
            'reached_tier_level' => $this->reachedTierLevel,
            'basket_bonus' => $this->basketBonus,
            'new_confirmed_customers_count' => $this->newConfirmedCustomersCount,
            'new_prospect_customers_count' => $this->newProspectCustomersCount,
            'new_confirmed_customers_bonus' => $this->newConfirmedCustomersBonus,
            'new_prospect_customers_bonus' => $this->newProspectCustomersBonus,
            'mandatory_daily_threshold' => $this->mandatoryDailyThreshold,
            'mandatory_threshold_reached' => $this->mandatoryThresholdReached,
            'cached_average_margin_rate' => $this->cachedAverageMarginRate,
        ];
    }
}
