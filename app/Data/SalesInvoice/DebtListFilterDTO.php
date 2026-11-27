<?php

namespace App\Data\SalesInvoice;

readonly class DebtListFilterDTO
{
    public const STATUS_ALL = 'all';

    public const STATUS_PAID = 'paid';

    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PAST_DUE = 'past_due';

    /**
     * @param  string[]  $selectedWeekStartDates  Mondays (Y-m-d) of the selected weeks.
     */
    public function __construct(
        public string $paymentStatus = self::STATUS_ALL,
        public string $customerNameSearch = '',
        public array $selectedWeekStartDates = [],
    ) {}
}
