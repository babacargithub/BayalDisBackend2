<?php

namespace App\Data\Account;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Support\Collection;

class AccountBalanceSummaryDTO
{
    public function __construct(
        public readonly int $merchandiseSalesBalance,
        public readonly int $profitAccountBalance,
        public readonly int $reservesBalance,
        public readonly int $totalNonUtilisable,
    ) {}

    /**
     * Build the summary from a collection of Account models.
     *
     * @param  Collection<int, Account>  $accounts
     */
    public static function fromAccounts(Collection $accounts): self
    {
        $merchandiseSalesBalance = $accounts
            ->filter(fn (Account $account) => $account->account_type === AccountType::MerchandiseSales)
            ->sum('balance');

        $profitAccountBalance = $accounts
            ->filter(fn (Account $account) => $account->account_type === AccountType::Profit)
            ->sum('balance');

        $reservesBalance = $accounts
            ->filter(fn (Account $account) => ! in_array($account->account_type, [
                AccountType::MerchandiseSales,
                AccountType::Profit,
            ], strict: true))
            ->sum('balance');

        return new self(
            merchandiseSalesBalance: (int) $merchandiseSalesBalance,
            profitAccountBalance: (int) $profitAccountBalance,
            reservesBalance: (int) $reservesBalance,
            totalNonUtilisable: (int) ($profitAccountBalance + $reservesBalance),
        );
    }

    public function toArray(): array
    {
        return [
            'merchandise_sales_balance' => $this->merchandiseSalesBalance,
            'profit_account_balance' => $this->profitAccountBalance,
            'reserves_balance' => $this->reservesBalance,
            'total_non_utilisable' => $this->totalNonUtilisable,
        ];
    }
}
