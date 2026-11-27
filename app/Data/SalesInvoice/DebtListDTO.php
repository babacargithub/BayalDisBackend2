<?php

namespace App\Data\SalesInvoice;

use App\Data\Beat\ArBucket;

readonly class DebtListDTO
{
    /**
     * @param  ArBucket[]  $buckets
     * @param  array<string, string>  $availableWeekLabels  Week start date (Y-m-d) => French label, newest first.
     */
    public function __construct(
        public array $buckets,
        public int $invoiceCount,
        public int $totalAmount,
        public int $totalPaid,
        public int $totalRemaining,
        public int $overdueInvoiceCount,
        public int $overdueRemaining,
        public array $availableWeekLabels,
    ) {}
}
