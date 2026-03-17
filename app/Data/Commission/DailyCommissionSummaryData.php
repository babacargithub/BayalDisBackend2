<?php

namespace App\Data\Commission;

/**
 * Summary of a commercial's commission activity for a single calendar day.
 * Returned by DailyCommissionService::getDailyCommissionSummary() and
 * serialised via toArray() by the salesperson API endpoint.
 *
 * Field meanings:
 *  - mandatoryDailySales : total SalesInvoice amounts created by this commercial that day
 *                          (invoiced sales, whether paid yet or not)
 *  - totalPayments       : total Payment amounts collected that day (encaissement)
 *  - commissionsEarned   : net commission after all bonuses and penalties
 *                          (base + basketBonus + tierBonus − totalPenalties)
 *  - totalPenalties      : sum of penalty amounts deducted from gross commission
 *  - tierBonus           : objective bonus from the highest achieved CA tier
 *  - reachedTierLevel    : tier level number that was reached (null = no tier achieved)
 *  - basketBonus         : multiplier bonus earned when all required categories were
 *                          sold within a single invoice
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
        ];
    }
}
