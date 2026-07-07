<?php

namespace App\Data\Beat;

/**
 * Aggregated performance snapshot for a single beat round.
 *
 * All money values are integers (XOF). Rates are floats (0–100).
 *
 * Metric taxonomy:
 *
 *   totalDebtToCollect  — outstanding balance on invoices created BEFORE the round date.
 *   totalDebtCollected  — payments made ON the round date for those pre-existing invoices only.
 *   totalNewInvoices    — total invoice amount of new sales created ON the round date.
 *   totalPayments       — all payments received ON the round date (debt repayments + instant sales).
 *   strikeRate          — % of round customers who bought or paid on the round date.
 *   ceiRate             — Collection Effectiveness Index: totalDebtCollected / totalDebtToCollect × 100.
 *   arBuckets           — outstanding pre-existing invoices sliced into five aging buckets.
 */
readonly class BeatRoundPerformanceDTO
{
    /**
     * @param  ArBucket[]  $arBuckets  Five aging buckets: ≤7d, 8–14d, 15–21d, 22–30d, 31+d.
     */
    public function __construct(
        public int $totalDebtToCollect,
        public int $totalDebtCollected,
        public int $totalNewInvoices,
        public int $totalPayments,
        public float $strikeRate,
        public float $ceiRate,
        public array $arBuckets,
    ) {}
}
