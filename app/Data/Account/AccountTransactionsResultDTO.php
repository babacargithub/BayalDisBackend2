<?php

namespace App\Data\Account;

use Illuminate\Support\Collection;

readonly class AccountTransactionsResultDTO
{
    public function __construct(
        public Collection $transactions,
        public int        $totalDeposits,
        public int        $totalWithdrawals,
    ) {}
}
