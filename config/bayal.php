<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Warehouse GPS coordinates
    |--------------------------------------------------------------------------
    |
    | Used as the origin point when sorting beat customers by proximity.
    | Override via WAREHOUSE_LATITUDE / WAREHOUSE_LONGITUDE environment variables.
    |
    */
    'warehouse' => [
        'latitude' => (float) env('WAREHOUSE_LATITUDE', 14.753016680035563),
        'longitude' => (float) env('WAREHOUSE_LONGITUDE', -17.468550395271897),
    ],

    /*
    |--------------------------------------------------------------------------
    | Accounts Receivable — payment terms
    |--------------------------------------------------------------------------
    |
    | invoice_payment_term_days: Number of days after invoice creation by which
    |   payment is expected. Invoices are created on beat day (e.g. Monday) and
    |   must be settled by the next beat day — typically 7 days.
    |   Used when setting should_be_paid_at on new invoices.
    |
    | worst_acceptable_days_to_payment: Ceiling used by MetricGradeTranslator
    |   for days-based AR metrics (averageDaysToPayment, averageDaysDelinquent,
    |   etc.). Reaching or exceeding this threshold grades as 0/20. 30 days is
    |   considered the absolute worst acceptable collection delay.
    |
    */
    'ar' => [
        'invoice_payment_term_days' => (int) env('AR_INVOICE_PAYMENT_TERM_DAYS', 7),
        'worst_acceptable_days_to_payment' => (int) env('AR_WORST_ACCEPTABLE_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Push Score
    |--------------------------------------------------------------------------
    |
    | perfect_ceiling: The sum of product weights that represents a "perfect"
    |   invoice (scores 100/100). Raising it makes the scale harder to max out;
    |   lowering it rewards smaller variety more generously.
    |   Default: 5.0 — selling 5 distinct average-weight (1.0) products is perfect.
    |   Override via PUSH_SCORE_PERFECT_CEILING.
    |
    */
    'push_score' => [
        'perfect_ceiling' => (float) env('PUSH_SCORE_PERFECT_CEILING', 5.0),
    ],
];
