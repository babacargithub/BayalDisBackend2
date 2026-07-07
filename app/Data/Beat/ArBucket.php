<?php

namespace App\Data\Beat;

/**
 * One aging slice in an accounts-receivable breakdown for a beat round.
 *
 * "Days overdue" is computed as the number of calendar days between the
 * invoice's created_at date and the round's planned_at date.
 *
 * Buckets are non-overlapping and cover all positive ages:
 *   1–7 days | 8–14 days | 15–21 days | 22–30 days | 31+ days
 *
 * Each bucket carries the full invoice list so callers can render
 * per-customer detail without additional queries.
 */
readonly class ArBucket
{
    /**
     * @param  int  $fromDays  Inclusive lower bound (days overdue).
     * @param  int|null  $toDays  Inclusive upper bound; null means open-ended (31+).
     * @param  int  $invoiceCount  Number of outstanding invoices in this bucket.
     * @param  int  $totalAmount  Sum of remaining balances (total_amount − total_payments) in XOF.
     * @param  array<int, array{
     *     id: int,
     *     customer_id: int,
     *     customer_name: string,
     *     total_amount: int,
     *     total_payments: int,
     *     remaining: int,
     *     days_overdue: int,
     *     created_at: string,
     * }> $invoices  Per-invoice detail for this bucket.
     */
    public function __construct(
        public string $labelFr,
        public string $labelEn,
        public int $fromDays,
        public ?int $toDays,
        public int $invoiceCount,
        public int $totalAmount,
        public array $invoices,
    ) {}
}
