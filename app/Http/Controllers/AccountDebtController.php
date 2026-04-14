<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientAccountBalanceException;
use App\Http\Requests\BorrowFromAccountRequest;
use App\Http\Requests\RepayAccountDebtRequest;
use App\Models\Account;
use App\Models\AccountDebt;
use App\Services\AccountDebtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class AccountDebtController extends Controller
{
    public function __construct(private readonly AccountDebtService $accountDebtService) {}

    /**
     * Return all outstanding (non-fully-repaid) debts for a given account, both as debtor and creditor.
     * Called via AJAX when the debt dialog opens.
     */
    public function outstandingDebtsForAccount(Account $account): JsonResponse
    {
        $debtsAsDebtor = $this->accountDebtService->getOutstandingDebtsForDebtorAccount($account)
            ->map(fn (AccountDebt $debt) => [
                'id' => $debt->id,
                'creditor_account_id' => $debt->creditor_account_id,
                'creditor_account_name' => $debt->creditorAccount->name,
                'original_amount' => $debt->original_amount,
                'remaining_amount' => $debt->remaining_amount,
                'status' => $debt->status->value,
                'status_label' => $debt->status->label(),
                'reason' => $debt->reason,
                'created_at' => $debt->created_at->toDateTimeString(),
            ]);

        $debtsAsCreditor = $this->accountDebtService->getOutstandingDebtsForCreditorAccount($account)
            ->map(fn (AccountDebt $debt) => [
                'id' => $debt->id,
                'debtor_account_id' => $debt->debtor_account_id,
                'debtor_account_name' => $debt->debtorAccount->name,
                'original_amount' => $debt->original_amount,
                'remaining_amount' => $debt->remaining_amount,
                'status' => $debt->status->value,
                'status_label' => $debt->status->label(),
                'reason' => $debt->reason,
                'created_at' => $debt->created_at->toDateTimeString(),
            ]);

        return response()->json([
            'debts_as_debtor' => $debtsAsDebtor,
            'debts_as_creditor' => $debtsAsCreditor,
            'total_outstanding_owed' => $this->accountDebtService->getTotalOutstandingDebtAmountForDebtorAccount($account),
        ]);
    }

    /**
     * Create a new inter-account debt: borrow from a creditor account into a debtor account.
     *
     * @throws \Throwable
     */
    public function borrow(BorrowFromAccountRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $debtorAccount = Account::findOrFail($validated['debtor_account_id']);
        $creditorAccount = Account::findOrFail($validated['creditor_account_id']);

        try {
            $this->accountDebtService->borrowFromAccount(
                debtorAccount: $debtorAccount,
                creditorAccount: $creditorAccount,
                amount: $validated['amount'],
                reason: $validated['reason'],
            );
        } catch (InsufficientAccountBalanceException $exception) {
            return back()->with('flash', ['error' => $exception->getMessage()]);
        }

        return back()->with('flash', ['success' => 'Emprunt enregistré avec succès.']);
    }

    /**
     * Repay part or all of an existing account debt.
     *
     * @throws \Throwable
     */
    public function repay(RepayAccountDebtRequest $request, AccountDebt $accountDebt): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $this->accountDebtService->repayDebt(
                accountDebt: $accountDebt,
                amountToRepay: $validated['amount'],
            );
        } catch (InsufficientAccountBalanceException|\InvalidArgumentException $exception) {
            return back()->with('flash', ['error' => $exception->getMessage()]);
        }

        return back()->with('flash', ['success' => 'Remboursement enregistré avec succès.']);
    }
}
