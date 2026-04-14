<?php

namespace Tests\Feature;

use App\Enums\AccountDebtStatus;
use App\Enums\AccountType;
use App\Enums\CaisseType;
use App\Exceptions\InsufficientAccountBalanceException;
use App\Models\Account;
use App\Models\AccountDebt;
use App\Models\Caisse;
use App\Services\AccountDebtService;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class AccountDebtTest extends TestCase
{
    use RefreshDatabase;

    private AccountDebtService $accountDebtService;

    private AccountService $accountService;

    /** Shared caisse used by all balance-seeding helpers to maintain SUM(accounts)==SUM(caisses). */
    private Caisse $testCaisse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountDebtService = app(AccountDebtService::class);
        $this->accountService = app(AccountService::class);
        $this->testCaisse = Caisse::create([
            'name' => 'Caisse test',
            'caisse_type' => CaisseType::Main->value,
            'balance' => 0,
        ]);
    }

    /**
     * Helper: create an Account and seed its balance via a paired caisse deposit
     * so that SUM(account.balance) == SUM(caisse.balance) is always satisfied.
     */
    private function createAccountWithBalance(string $name, int $balance): Account
    {
        $account = Account::create([
            'name' => $name,
            'account_type' => AccountType::MerchandiseSales->value,
            'balance' => 0,
            'is_active' => true,
        ]);

        if ($balance > 0) {
            $this->accountService->depositToCaisseAndCreditAccount(
                caisse: $this->testCaisse,
                account: $account,
                amount: $balance,
                caisseLabel: "Solde initial test — {$name}",
                accountLabel: "Solde initial test — {$name}",
            );
        }

        return $account;
    }

    /**
     * Helper: drain an account by the given amount using a paired caisse withdrawal
     * so the global invariant is not broken.
     */
    private function drainAccountBalance(Account $account, int $amount): void
    {
        $this->accountService->withdrawFromCaisseAndDebitAccount(
            caisse: $this->testCaisse,
            account: $account,
            amount: $amount,
            caisseLabel: 'Dépense simulée — test',
            accountLabel: 'Dépense simulée — test',
        );
    }

    // ── borrowFromAccount ────────────────────────────────────────────────────

    public function test_borrow_transfers_money_from_creditor_to_debtor_and_creates_debt_record(): void
    {
        $merchandiseSalesAccount = $this->createAccountWithBalance('Vente marchandises', 0);
        $vehicleDepreciationAccount = $this->createAccountWithBalance('Amortissement véhicule', 200_000);

        $accountDebt = $this->accountDebtService->borrowFromAccount(
            debtorAccount: $merchandiseSalesAccount,
            creditorAccount: $vehicleDepreciationAccount,
            amount: 50_000,
            reason: 'Achat marchandises — solde insuffisant',
        );

        // Money moved correctly
        $this->assertEquals(50_000, $merchandiseSalesAccount->fresh()->balance);
        $this->assertEquals(150_000, $vehicleDepreciationAccount->fresh()->balance);

        // Debt record created
        $this->assertInstanceOf(AccountDebt::class, $accountDebt);
        $this->assertEquals($merchandiseSalesAccount->id, $accountDebt->debtor_account_id);
        $this->assertEquals($vehicleDepreciationAccount->id, $accountDebt->creditor_account_id);
        $this->assertEquals(50_000, $accountDebt->original_amount);
        $this->assertEquals(50_000, $accountDebt->remaining_amount);
        $this->assertEquals(AccountDebtStatus::Pending, $accountDebt->status);
    }

    public function test_borrow_creates_labeled_account_transactions_with_correct_reference_type(): void
    {
        $debtorAccount = $this->createAccountWithBalance('Compte A', 0);
        $creditorAccount = $this->createAccountWithBalance('Compte B', 100_000);

        $accountDebt = $this->accountDebtService->borrowFromAccount(
            debtorAccount: $debtorAccount,
            creditorAccount: $creditorAccount,
            amount: 30_000,
            reason: 'Test emprunt',
        );

        // Creditor gets a DEBIT transaction
        $creditorDebitTransaction = $creditorAccount->transactions()
            ->where('reference_type', 'ACCOUNT_DEBT_BORROW')
            ->where('reference_id', $accountDebt->id)
            ->first();

        $this->assertNotNull($creditorDebitTransaction);
        $this->assertEquals(30_000, $creditorDebitTransaction->amount);

        // Debtor gets a CREDIT transaction
        $debtorCreditTransaction = $debtorAccount->transactions()
            ->where('reference_type', 'ACCOUNT_DEBT_BORROW')
            ->where('reference_id', $accountDebt->id)
            ->first();

        $this->assertNotNull($debtorCreditTransaction);
        $this->assertEquals(30_000, $debtorCreditTransaction->amount);
    }

    public function test_borrow_throws_when_creditor_has_insufficient_balance(): void
    {
        $debtorAccount = $this->createAccountWithBalance('Compte A', 0);
        $creditorAccount = $this->createAccountWithBalance('Compte B', 10_000);

        $this->expectException(InsufficientAccountBalanceException::class);

        $this->accountDebtService->borrowFromAccount(
            debtorAccount: $debtorAccount,
            creditorAccount: $creditorAccount,
            amount: 50_000,
            reason: 'Tentative dépassement',
        );
    }

    public function test_borrow_throws_when_amount_is_zero(): void
    {
        $debtorAccount = $this->createAccountWithBalance('Compte A', 0);
        $creditorAccount = $this->createAccountWithBalance('Compte B', 100_000);

        $this->expectException(InvalidArgumentException::class);

        $this->accountDebtService->borrowFromAccount(
            debtorAccount: $debtorAccount,
            creditorAccount: $creditorAccount,
            amount: 0,
            reason: 'Montant nul',
        );
    }

    public function test_borrow_throws_when_debtor_and_creditor_are_the_same_account(): void
    {
        $account = $this->createAccountWithBalance('Compte A', 100_000);

        $this->expectException(InvalidArgumentException::class);

        $this->accountDebtService->borrowFromAccount(
            debtorAccount: $account,
            creditorAccount: $account,
            amount: 10_000,
            reason: 'Auto-emprunt',
        );
    }

    // ── repayDebt ────────────────────────────────────────────────────────────

    public function test_repay_full_debt_transfers_money_back_and_marks_debt_as_fully_repaid(): void
    {
        $debtorAccount = $this->createAccountWithBalance('Compte débiteur', 50_000);
        $creditorAccount = $this->createAccountWithBalance('Compte créancier', 150_000);

        $accountDebt = $this->accountDebtService->borrowFromAccount(
            debtorAccount: $debtorAccount,
            creditorAccount: $creditorAccount,
            amount: 50_000,
            reason: 'Emprunt initial',
        );

        // Now debtor has 100_000, creditor has 100_000
        $debtorAccount->refresh();
        $creditorAccount->refresh();

        $repaidDebt = $this->accountDebtService->repayDebt($accountDebt, 50_000);

        $this->assertEquals(50_000, $debtorAccount->fresh()->balance);
        $this->assertEquals(150_000, $creditorAccount->fresh()->balance);
        $this->assertEquals(0, $repaidDebt->remaining_amount);
        $this->assertEquals(AccountDebtStatus::FullyRepaid, $repaidDebt->status);
    }

    public function test_repay_partial_amount_updates_remaining_and_marks_debt_as_partially_repaid(): void
    {
        $debtorAccount = $this->createAccountWithBalance('Compte débiteur', 0);
        $creditorAccount = $this->createAccountWithBalance('Compte créancier', 100_000);

        $accountDebt = $this->accountDebtService->borrowFromAccount(
            debtorAccount: $debtorAccount,
            creditorAccount: $creditorAccount,
            amount: 100_000,
            reason: 'Emprunt initial',
        );

        $repaidDebt = $this->accountDebtService->repayDebt($accountDebt, 40_000);

        $this->assertEquals(60_000, $repaidDebt->remaining_amount);
        $this->assertEquals(AccountDebtStatus::PartiallyRepaid, $repaidDebt->status);
        $this->assertEquals(100_000, $repaidDebt->original_amount);
    }

    public function test_repay_creates_labeled_account_transactions_with_correct_reference_type(): void
    {
        $debtorAccount = $this->createAccountWithBalance('Compte débiteur', 0);
        $creditorAccount = $this->createAccountWithBalance('Compte créancier', 80_000);

        $accountDebt = $this->accountDebtService->borrowFromAccount(
            debtorAccount: $debtorAccount,
            creditorAccount: $creditorAccount,
            amount: 80_000,
            reason: 'Emprunt test',
        );

        $this->accountDebtService->repayDebt($accountDebt, 20_000);

        // Debtor gets a DEBIT transaction for the repayment
        $debtorRepaymentTransaction = $debtorAccount->transactions()
            ->where('reference_type', 'ACCOUNT_DEBT_REPAYMENT')
            ->where('reference_id', $accountDebt->id)
            ->first();

        $this->assertNotNull($debtorRepaymentTransaction);
        $this->assertEquals(20_000, $debtorRepaymentTransaction->amount);

        // Creditor gets a CREDIT transaction for the repayment
        $creditorRepaymentTransaction = $creditorAccount->transactions()
            ->where('reference_type', 'ACCOUNT_DEBT_REPAYMENT')
            ->where('reference_id', $accountDebt->id)
            ->first();

        $this->assertNotNull($creditorRepaymentTransaction);
        $this->assertEquals(20_000, $creditorRepaymentTransaction->amount);
    }

    public function test_repay_throws_when_repayment_exceeds_remaining_debt(): void
    {
        $debtorAccount = $this->createAccountWithBalance('Compte débiteur', 0);
        $creditorAccount = $this->createAccountWithBalance('Compte créancier', 50_000);

        $accountDebt = $this->accountDebtService->borrowFromAccount(
            debtorAccount: $debtorAccount,
            creditorAccount: $creditorAccount,
            amount: 50_000,
            reason: 'Emprunt initial',
        );

        $this->expectException(InvalidArgumentException::class);

        $this->accountDebtService->repayDebt($accountDebt, 60_000);
    }

    public function test_repay_throws_when_debt_is_already_fully_repaid(): void
    {
        $debtorAccount = $this->createAccountWithBalance('Compte débiteur', 0);
        $creditorAccount = $this->createAccountWithBalance('Compte créancier', 40_000);

        $accountDebt = $this->accountDebtService->borrowFromAccount(
            debtorAccount: $debtorAccount,
            creditorAccount: $creditorAccount,
            amount: 40_000,
            reason: 'Emprunt initial',
        );

        $this->accountDebtService->repayDebt($accountDebt, 40_000);

        $this->expectException(InvalidArgumentException::class);

        $this->accountDebtService->repayDebt($accountDebt, 1_000);
    }

    public function test_repay_throws_when_debtor_has_insufficient_balance_to_repay(): void
    {
        $debtorAccount = $this->createAccountWithBalance('Compte débiteur', 0);
        $creditorAccount = $this->createAccountWithBalance('Compte créancier', 100_000);

        $accountDebt = $this->accountDebtService->borrowFromAccount(
            debtorAccount: $debtorAccount,
            creditorAccount: $creditorAccount,
            amount: 100_000,
            reason: 'Emprunt initial',
        );

        // Drain the debtor account so repayment is impossible
        $debtorAccount->refresh();
        $this->drainAccountBalance($debtorAccount, 100_000);

        $this->expectException(InsufficientAccountBalanceException::class);

        $accountDebt->refresh();
        $this->accountDebtService->repayDebt($accountDebt, 50_000);
    }

    // ── repayAllOutstandingDebtsFromAvailableFunds ───────────────────────────

    public function test_auto_repay_settles_multiple_debts_in_chronological_order(): void
    {
        $debtorAccount = $this->createAccountWithBalance('Compte débiteur', 0);
        $creditorAccountA = $this->createAccountWithBalance('Compte prêteur A', 30_000);
        $creditorAccountB = $this->createAccountWithBalance('Compte prêteur B', 50_000);

        $debtA = $this->accountDebtService->borrowFromAccount($debtorAccount, $creditorAccountA, 30_000, 'Dette A');
        $debtB = $this->accountDebtService->borrowFromAccount($debtorAccount, $creditorAccountB, 50_000, 'Dette B');

        // Debtor now has 80_000 from the two borrows — enough to settle both.
        $totalRepaid = $this->accountDebtService->repayAllOutstandingDebtsFromAvailableFunds($debtorAccount);

        $this->assertEquals(80_000, $totalRepaid);

        $this->assertEquals(AccountDebtStatus::FullyRepaid, $debtA->fresh()->status);
        $this->assertEquals(AccountDebtStatus::FullyRepaid, $debtB->fresh()->status);
        $this->assertEquals(0, $debtorAccount->fresh()->balance);
    }

    public function test_auto_repay_does_partial_repayment_when_balance_is_insufficient_to_cover_all_debts(): void
    {
        $debtorAccount = $this->createAccountWithBalance('Compte débiteur', 0);
        $creditorAccountA = $this->createAccountWithBalance('Compte prêteur A', 60_000);
        $creditorAccountB = $this->createAccountWithBalance('Compte prêteur B', 40_000);

        $debtA = $this->accountDebtService->borrowFromAccount($debtorAccount, $creditorAccountA, 60_000, 'Dette A');
        $debtB = $this->accountDebtService->borrowFromAccount($debtorAccount, $creditorAccountB, 40_000, 'Dette B');

        // Debtor has 100_000. Drain 30_000 so only 70_000 is available for repayment.
        $debtorAccount->refresh();
        $this->drainAccountBalance($debtorAccount, 30_000);

        $totalRepaid = $this->accountDebtService->repayAllOutstandingDebtsFromAvailableFunds($debtorAccount);

        // Oldest debt (A = 60_000) fully paid; remaining 10_000 goes to debt B.
        $this->assertEquals(70_000, $totalRepaid);

        $debtA->refresh();
        $debtB->refresh();

        $this->assertEquals(AccountDebtStatus::FullyRepaid, $debtA->status);
        $this->assertEquals(0, $debtA->remaining_amount);

        $this->assertEquals(AccountDebtStatus::PartiallyRepaid, $debtB->status);
        $this->assertEquals(30_000, $debtB->remaining_amount);

        $this->assertEquals(0, $debtorAccount->fresh()->balance);
    }

    public function test_auto_repay_returns_zero_and_does_nothing_when_there_are_no_outstanding_debts(): void
    {
        $debtorAccount = $this->createAccountWithBalance('Compte débiteur', 100_000);

        $totalRepaid = $this->accountDebtService->repayAllOutstandingDebtsFromAvailableFunds($debtorAccount);

        $this->assertEquals(0, $totalRepaid);
        $this->assertEquals(100_000, $debtorAccount->fresh()->balance);
    }

    public function test_auto_repay_returns_zero_when_debtor_balance_is_zero(): void
    {
        $debtorAccount = $this->createAccountWithBalance('Compte débiteur', 0);
        $creditorAccount = $this->createAccountWithBalance('Compte créancier', 50_000);

        $this->accountDebtService->borrowFromAccount($debtorAccount, $creditorAccount, 50_000, 'Dette');

        // Drain the debtor account
        $debtorAccount->refresh();
        $this->drainAccountBalance($debtorAccount, 50_000);

        $totalRepaid = $this->accountDebtService->repayAllOutstandingDebtsFromAvailableFunds($debtorAccount);

        $this->assertEquals(0, $totalRepaid);
    }

    // ── getOutstandingDebts ──────────────────────────────────────────────────

    public function test_get_outstanding_debts_for_debtor_excludes_fully_repaid_debts(): void
    {
        $debtorAccount = $this->createAccountWithBalance('Compte débiteur', 0);
        $creditorA = $this->createAccountWithBalance('Prêteur A', 30_000);
        $creditorB = $this->createAccountWithBalance('Prêteur B', 20_000);

        $debtA = $this->accountDebtService->borrowFromAccount($debtorAccount, $creditorA, 30_000, 'Dette A');
        $debtB = $this->accountDebtService->borrowFromAccount($debtorAccount, $creditorB, 20_000, 'Dette B');

        // Repay only debt A fully
        $this->accountDebtService->repayDebt($debtA, 30_000);

        $outstandingDebts = $this->accountDebtService->getOutstandingDebtsForDebtorAccount($debtorAccount);

        $this->assertCount(1, $outstandingDebts);
        $this->assertEquals($debtB->id, $outstandingDebts->first()->id);
    }

    public function test_get_total_outstanding_debt_amount_sums_all_remaining_amounts(): void
    {
        $debtorAccount = $this->createAccountWithBalance('Compte débiteur', 0);
        $creditorA = $this->createAccountWithBalance('Prêteur A', 40_000);
        $creditorB = $this->createAccountWithBalance('Prêteur B', 25_000);

        $debtA = $this->accountDebtService->borrowFromAccount($debtorAccount, $creditorA, 40_000, 'Dette A');
        $this->accountDebtService->borrowFromAccount($debtorAccount, $creditorB, 25_000, 'Dette B');

        // Partially repay debt A by 15_000 → remaining = 25_000
        $this->accountDebtService->repayDebt($debtA, 15_000);

        $totalOutstandingAmount = $this->accountDebtService->getTotalOutstandingDebtAmountForDebtorAccount($debtorAccount);

        // Remaining: 25_000 (debt A after partial repay) + 25_000 (debt B) = 50_000
        $this->assertEquals(50_000, $totalOutstandingAmount);
    }
}
