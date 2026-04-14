<?php

namespace App\Services;

use App\Enums\AccountDebtStatus;
use App\Exceptions\InsufficientAccountBalanceException;
use App\Models\Account;
use App\Models\AccountDebt;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

readonly class AccountDebtService
{
    public function __construct(
        private AccountService $accountService,
    ) {}

    /**
     * Borrow money from a creditor account into a debtor account.
     *
     * Transfers $amount from $creditorAccount → $debtorAccount and creates
     * an AccountDebt record tracking the obligation to repay.
     *
     * The creditor must have sufficient balance; the existing AccountService::debit()
     * guard enforces this and throws InsufficientAccountBalanceException if not.
     *
     * @throws InvalidArgumentException if amount is not positive
     * @throws InsufficientAccountBalanceException if the creditor has insufficient balance
     * @throws Throwable
     */
    public function borrowFromAccount(
        Account $debtorAccount,
        Account $creditorAccount,
        int $amount,
        string $reason,
    ): AccountDebt {
        if ($amount <= 0) {
            throw new InvalidArgumentException(
                "Le montant de l'emprunt doit être un entier strictement positif. Reçu : {$amount}."
            );
        }

        if ($debtorAccount->id === $creditorAccount->id) {
            throw new InvalidArgumentException(
                "Un compte ne peut pas s'emprunter à lui-même."
            );
        }

        return DB::transaction(function () use ($debtorAccount, $creditorAccount, $amount, $reason): AccountDebt {
            $debtRecord = AccountDebt::create([
                'debtor_account_id' => $debtorAccount->id,
                'creditor_account_id' => $creditorAccount->id,
                'original_amount' => $amount,
                'remaining_amount' => $amount,
                'status' => AccountDebtStatus::Pending,
                'reason' => $reason,
            ]);

            $this->accountService->transferBetweenAccounts(
                fromAccount: $creditorAccount,
                toAccount: $debtorAccount,
                amount: $amount,
                label: "Emprunt — {$reason}",
                referenceType: 'ACCOUNT_DEBT_BORROW',
                referenceId: $debtRecord->id,
            );

            $this->accountService->assertGlobalInvariantHolds();

            return $debtRecord;
        });
    }

    /**
     * Repay part or all of an outstanding debt.
     *
     * Transfers $amountToRepay from the debtor account back to the creditor account,
     * then reduces the debt's remaining_amount and updates its status accordingly.
     *
     * @throws InvalidArgumentException if amount is not positive, exceeds remaining debt, or the debt is already fully repaid
     * @throws InsufficientAccountBalanceException if the debtor has insufficient balance
     * @throws Throwable
     */
    public function repayDebt(AccountDebt $accountDebt, int $amountToRepay): AccountDebt
    {
        if ($amountToRepay <= 0) {
            throw new InvalidArgumentException(
                "Le montant du remboursement doit être supérieur à 0. Reçu : {$amountToRepay}."
            );
        }

        if ($accountDebt->isFullyRepaid()) {
            throw new InvalidArgumentException(
                'Cette dette est déjà entièrement remboursée.'
            );
        }

        if ($amountToRepay > $accountDebt->remaining_amount) {
            throw new InvalidArgumentException(
                "Le montant du remboursement ({$amountToRepay} F) dépasse le solde restant de la dette ({$accountDebt->remaining_amount} F)."
            );
        }

        return DB::transaction(function () use ($accountDebt, $amountToRepay): AccountDebt {
            $debtorAccount = $accountDebt->debtorAccount;
            $creditorAccount = $accountDebt->creditorAccount;

            $this->accountService->transferBetweenAccounts(
                fromAccount: $debtorAccount,
                toAccount: $creditorAccount,
                amount: $amountToRepay,
                label: "Remboursement dette — {$accountDebt->reason}",
                referenceType: 'ACCOUNT_DEBT_REPAYMENT',
                referenceId: $accountDebt->id,
            );

            $newRemainingAmount = $accountDebt->remaining_amount - $amountToRepay;
            $newStatus = $newRemainingAmount === 0
                ? AccountDebtStatus::FullyRepaid
                : AccountDebtStatus::PartiallyRepaid;

            $accountDebt->update([
                'remaining_amount' => $newRemainingAmount,
                'status' => $newStatus,
            ]);

            $this->accountService->assertGlobalInvariantHolds();

            return $accountDebt->fresh();
        });
    }

    /**
     * Automatically repay as many outstanding debts as possible from the debtor's
     * current available balance, processing them in chronological order (oldest first).
     *
     * Partial repayments are made when the balance is insufficient to settle a debt
     * in full. Stops as soon as the debtor account balance reaches zero.
     *
     * Returns the total amount repaid across all debts.
     *
     * @throws Throwable
     */
    public function repayAllOutstandingDebtsFromAvailableFunds(Account $debtorAccount): int
    {
        $outstandingDebts = AccountDebt::query()
            ->where('debtor_account_id', $debtorAccount->id)
            ->whereIn('status', [AccountDebtStatus::Pending->value, AccountDebtStatus::PartiallyRepaid->value])
            ->orderBy('created_at')
            ->with(['debtorAccount', 'creditorAccount'])
            ->get();

        if ($outstandingDebts->isEmpty()) {
            return 0;
        }

        $totalAmountRepaid = 0;

        foreach ($outstandingDebts as $accountDebt) {
            $debtorAccount->refresh();

            if ($debtorAccount->balance <= 0) {
                break;
            }

            $repayableAmount = min($debtorAccount->balance, $accountDebt->remaining_amount);

            if ($repayableAmount > 0) {
                $this->repayDebt($accountDebt, $repayableAmount);
                $totalAmountRepaid += $repayableAmount;
            }
        }

        return $totalAmountRepaid;
    }

    /**
     * Return all outstanding (non-fully-repaid) debts for a given debtor account,
     * ordered oldest first.
     *
     * @return Collection<int, AccountDebt>
     */
    public function getOutstandingDebtsForDebtorAccount(Account $debtorAccount): Collection
    {
        return AccountDebt::query()
            ->where('debtor_account_id', $debtorAccount->id)
            ->whereIn('status', [AccountDebtStatus::Pending->value, AccountDebtStatus::PartiallyRepaid->value])
            ->with(['creditorAccount'])
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Return all debts (as creditor) that have not been fully repaid back to the given account,
     * ordered oldest first.
     *
     * @return Collection<int, AccountDebt>
     */
    public function getOutstandingDebtsForCreditorAccount(Account $creditorAccount): Collection
    {
        return AccountDebt::query()
            ->where('creditor_account_id', $creditorAccount->id)
            ->whereIn('status', [AccountDebtStatus::Pending->value, AccountDebtStatus::PartiallyRepaid->value])
            ->with(['debtorAccount'])
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Return the total outstanding amount owed by a debtor account across all its pending debts.
     */
    public function getTotalOutstandingDebtAmountForDebtorAccount(Account $debtorAccount): int
    {
        return (int) AccountDebt::query()
            ->where('debtor_account_id', $debtorAccount->id)
            ->whereIn('status', [AccountDebtStatus::Pending->value, AccountDebtStatus::PartiallyRepaid->value])
            ->sum('remaining_amount');
    }
}
