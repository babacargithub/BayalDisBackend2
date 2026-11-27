<?php

namespace App\Data\SalesInvoice;

/** What a bulk action would do, shown to the user before confirming. */
readonly class BulkInvoiceActionPreviewDTO
{
    /**
     * @param  int[]  $eligibleInvoiceIds
     */
    public function __construct(
        public array $eligibleInvoiceIds,
        public int $ineligibleInvoiceCount,
        public int $totalCollected,
        public int $totalRemaining,
    ) {}
}
