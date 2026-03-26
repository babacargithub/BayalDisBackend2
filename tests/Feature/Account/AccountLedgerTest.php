<?php

namespace Tests\Feature\Account;

use App\Enums\AccountTransactionType;
use App\Enums\AccountType;
use App\Exceptions\InsufficientAccountBalanceException;
use App\Models\Account;
use App\Models\Caisse;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountLedgerTest extends TestCase
{
    use RefreshDatabase;

    private AccountService $accountService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountService = app(AccountService::class);
    }

    private function makeAccount(int $initialBalance = 0): Account
    {
        return Account::create([
            'name' => 'Test Account',
            'account_type' => AccountType::MerchandiseSales,
            'balance' => $initialBalance,
            'is_active' => true,
        ]);
    }

    private function makeCaisse(int $balance = 0): Caisse
    {
        return Caisse::create(['name' => 'Test Caisse', 'balance' => $balance, 'closed' => false]);
    }

    // ── Credit ───────────────────────────────────────────────────────────────

    public function test_credit_increases_cached_balance_by_exact_amount(): void
    {
        $account = $this->makeAccount(0);
        $this->accountService->credit($account, 10_000, 'Test crédit');
        $account->refresh();
        $this->assertSame(10_000, $account->balance);
    }

    public function test_credit_creates_a_credit_account_transaction(): void
    {
        $account = $this->makeAccount(0);
        $this->accountService->credit($account, 5_000, 'Libellé test', 'VERSEMENT', 42);
        $this->assertDatabaseHas('account_transactions', [
            'account_id' => $account->id,
            'amount' => 5_000,
            'transaction_type' => AccountTransactionType::Credit->value,
            'label' => 'Libellé test',
            'reference_type' => 'VERSEMENT',
            'reference_id' => 42,
        ]);
    }

    public function test_multiple_credits_accumulate_correctly(): void
    {
        $account = $this->makeAccount(0);
        $this->accountService->credit($account, 10_000, 'Crédit 1');
        $this->accountService->credit($account, 25_000, 'Crédit 2');
        $this->accountService->credit($account, 5_000, 'Crédit 3');
        $account->refresh();
        $this->assertSame(40_000, $account->balance);
    }

    public function test_credit_throws_for_zero_amount(): void
    {
        $account = $this->makeAccount(0);
        $this->expectException(\InvalidArgumentException::class);
        $this->accountService->credit($account, 0, 'Zero');
    }

    public function test_credit_throws_for_negative_amount(): void
    {
        $account = $this->makeAccount(0);
        $this->expectException(\InvalidArgumentException::class);
        $this->accountService->credit($account, -500, 'Négatif');
    }

    // ── Debit ────────────────────────────────────────────────────────────────

    public function test_debit_decreases_cached_balance_by_exact_amount(): void
    {
        $account = $this->makeAccount(50_000);
        $this->accountService->debit($account, 15_000, 'Test débit');
        $account->refresh();
        $this->assertSame(35_000, $account->balance);
    }

    public function test_debit_creates_a_debit_account_transaction(): void
    {
        $account = $this->makeAccount(30_000);
        $this->accountService->debit($account, 12_000, 'Paiement assurance', 'EXPENSE', 7);
        $this->assertDatabaseHas('account_transactions', [
            'account_id' => $account->id,
            'amount' => 12_000,
            'transaction_type' => AccountTransactionType::Debit->value,
            'label' => 'Paiement assurance',
            'reference_type' => 'EXPENSE',
            'reference_id' => 7,
        ]);
    }

    public function test_debit_throws_exception_when_balance_is_insufficient(): void
    {
        $account = $this->makeAccount(5_000);
        $this->expectException(InsufficientAccountBalanceException::class);
        $this->accountService->debit($account, 5_001, 'Trop grand');
    }

    public function test_debit_throws_exception_when_account_is_empty(): void
    {
        $account = $this->makeAccount(0);
        $this->expectException(InsufficientAccountBalanceException::class);
        $this->accountService->debit($account, 1, 'Vide');
    }

    public function test_debit_exactly_full_balance_brings_account_to_zero(): void
    {
        $account = $this->makeAccount(20_000);
        $this->accountService->debit($account, 20_000, 'Vidage total');
        $account->refresh();
        $this->assertSame(0, $account->balance);
    }

    public function test_debit_throws_for_zero_amount(): void
    {
        $account = $this->makeAccount(10_000);
        $this->expectException(\InvalidArgumentException::class);
        $this->accountService->debit($account, 0, 'Zero');
    }

    // ── Ledger recomputation ─────────────────────────────────────────────────

    public function test_compute_balance_from_ledger_matches_cached_balance_after_credits(): void
    {
        $account = $this->makeAccount(0);
        $this->accountService->credit($account, 30_000, 'C1');
        $this->accountService->credit($account, 20_000, 'C2');
        $account->refresh();
        $this->assertSame($account->balance, $account->computeBalanceFromLedger());
    }

    public function test_compute_balance_from_ledger_matches_cached_balance_after_mixed_transactions(): void
    {
        $account = $this->makeAccount(0);
        $this->accountService->credit($account, 100_000, 'Crédit');
        $this->accountService->debit($account, 30_000, 'Débit 1');
        $this->accountService->credit($account, 50_000, 'Crédit 2');
        $this->accountService->debit($account, 20_000, 'Débit 2');
        $account->refresh();
        $this->assertSame(100_000, $account->balance);
        $this->assertSame($account->balance, $account->computeBalanceFromLedger());
    }

    public function test_fresh_account_has_zero_balance_and_no_transactions(): void
    {
        $account = $this->makeAccount(0);
        $this->assertSame(0, $account->balance);
        $this->assertSame(0, $account->computeBalanceFromLedger());
        $this->assertSame(0, $account->transactions()->count());
    }

    public function test_effective_amount_is_positive_for_credit_transaction(): void
    {
        $account = $this->makeAccount(0);
        $transaction = $this->accountService->credit($account, 7_500, 'Test');
        $this->assertSame(7_500, $transaction->effective_amount);
    }

    public function test_effective_amount_is_negative_for_debit_transaction(): void
    {
        $account = $this->makeAccount(10_000);
        $transaction = $this->accountService->debit($account, 7_500, 'Test');
        $this->assertSame(-7_500, $transaction->effective_amount);
    }

    // ── transferBetweenAccounts ───────────────────────────────────────────────

    public function test_transfer_debits_source_and_credits_destination(): void
    {
        $sourceAccount = $this->makeAccount(60_000);
        $destinationAccount = $this->makeAccount(0);
        $this->accountService->transferBetweenAccounts($sourceAccount, $destinationAccount, 25_000, 'Transfert');
        $sourceAccount->refresh();
        $destinationAccount->refresh();
        $this->assertSame(35_000, $sourceAccount->balance);
        $this->assertSame(25_000, $destinationAccount->balance);
    }

    public function test_transfer_does_not_change_total_account_balance(): void
    {
        $sourceAccount = $this->makeAccount(80_000);
        $destinationAccount = $this->makeAccount(20_000);
        $totalBefore = $sourceAccount->balance + $destinationAccount->balance;
        $this->accountService->transferBetweenAccounts($sourceAccount, $destinationAccount, 30_000, 'Transfert');
        $sourceAccount->refresh();
        $destinationAccount->refresh();
        $this->assertSame($totalBefore, $sourceAccount->balance + $destinationAccount->balance);
    }

    public function test_transfer_throws_when_source_has_insufficient_balance(): void
    {
        $sourceAccount = $this->makeAccount(10_000);
        $destinationAccount = $this->makeAccount(0);
        $this->expectException(InsufficientAccountBalanceException::class);
        $this->accountService->transferBetweenAccounts($sourceAccount, $destinationAccount, 10_001, 'Trop');
    }

    public function test_transfer_exactly_full_source_balance_zeroes_source(): void
    {
        $sourceAccount = $this->makeAccount(15_000);
        $destinationAccount = $this->makeAccount(0);
        $this->accountService->transferBetweenAccounts($sourceAccount, $destinationAccount, 15_000, 'Vidage');
        $sourceAccount->refresh();
        $destinationAccount->refresh();
        $this->assertSame(0, $sourceAccount->balance);
        $this->assertSame(15_000, $destinationAccount->balance);
    }

    // ── Paired caisse + account operations ───────────────────────────────────

    public function test_deposit_to_caisse_and_credit_account_updates_both_balances(): void
    {
        $caisse = $this->makeCaisse(0);
        $account = $this->makeAccount(0);
        $this->accountService->depositToCaisseAndCreditAccount(
            caisse: $caisse, account: $account, amount: 40_000,
            caisseLabel: 'Dépôt test', accountLabel: 'Crédit test',
        );
        $caisse->refresh();
        $account->refresh();
        $this->assertSame(40_000, $caisse->balance);
        $this->assertSame(40_000, $account->balance);
    }

    public function test_withdraw_from_caisse_and_debit_account_updates_both_balances(): void
    {
        $caisse = $this->makeCaisse(50_000);
        $account = $this->makeAccount(50_000);
        $this->accountService->withdrawFromCaisseAndDebitAccount(
            caisse: $caisse, account: $account, amount: 20_000,
            caisseLabel: 'Retrait test', accountLabel: 'Débit test',
        );
        $caisse->refresh();
        $account->refresh();
        $this->assertSame(30_000, $caisse->balance);
        $this->assertSame(30_000, $account->balance);
    }

    public function test_withdraw_throws_when_account_balance_insufficient_and_does_not_change_caisse(): void
    {
        $caisse = $this->makeCaisse(100_000);
        $account = $this->makeAccount(5_000);
        $this->expectException(InsufficientAccountBalanceException::class);
        $this->accountService->withdrawFromCaisseAndDebitAccount(
            caisse: $caisse, account: $account, amount: 10_000,
            caisseLabel: 'Retrait', accountLabel: 'Débit',
        );
    }

    public function test_deposit_and_withdraw_paired_operations_leave_total_unchanged(): void
    {
        $caisse = $this->makeCaisse(0);
        $account = $this->makeAccount(0);

        $this->accountService->depositToCaisseAndCreditAccount(
            caisse: $caisse, account: $account, amount: 100_000,
            caisseLabel: 'Dépôt', accountLabel: 'Crédit',
        );
        $this->accountService->withdrawFromCaisseAndDebitAccount(
            caisse: $caisse, account: $account, amount: 40_000,
            caisseLabel: 'Retrait', accountLabel: 'Débit',
        );

        $caisse->refresh();
        $account->refresh();
        $this->assertSame(60_000, $caisse->balance);
        $this->assertSame(60_000, $account->balance);
        $this->assertSame($account->balance, $account->computeBalanceFromLedger());
    }
}
