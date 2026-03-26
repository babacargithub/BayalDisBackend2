<?php

namespace Tests\Feature\Account;

use App\Enums\AccountType;
use App\Enums\CaisseType;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Caisse;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the company-wide financial invariant:
 * SUM(account.balance) == SUM(caisse.balance)
 *
 * This invariant must hold after every operation that touches caisses or accounts.
 * Any violation means money has been created or destroyed in the ledger.
 */
class AccountInvariantTest extends TestCase
{
    use RefreshDatabase;

    private AccountService $accountService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountService = app(AccountService::class);
    }

    private function assertGlobalInvariantHolds(): void
    {
        $totalAccountBalance = Account::sum('balance');
        $totalCaisseBalance = Caisse::sum('balance');
        $this->assertSame(
            $totalCaisseBalance,
            $totalAccountBalance,
            "Global invariant violated: SUM(account.balance)={$totalAccountBalance} ≠ SUM(caisse.balance)={$totalCaisseBalance}"
        );
    }

    private function makeMerchandiseSalesAccount(int $balance = 0): Account
    {
        return Account::create([
            'name' => 'Vente marchandises',
            'account_type' => AccountType::MerchandiseSales,
            'balance' => $balance,
            'is_active' => true,
        ]);
    }

    private function makeMainCaisse(int $balance = 0): Caisse
    {
        return Caisse::create(['name' => 'Caisse principale', 'caisse_type' => CaisseType::Main, 'balance' => $balance, 'closed' => false]);
    }

    public function test_invariant_holds_with_no_data(): void
    {
        $this->assertGlobalInvariantHolds();
    }

    public function test_invariant_holds_after_paired_deposit_and_credit(): void
    {
        $caisse = $this->makeMainCaisse(0);
        $account = $this->makeMerchandiseSalesAccount(0);

        $this->accountService->depositToCaisseAndCreditAccount(
            caisse: $caisse, account: $account, amount: 75_000,
            caisseLabel: 'Dépôt', accountLabel: 'Crédit',
        );

        $this->assertGlobalInvariantHolds();
    }

    public function test_invariant_holds_after_paired_withdraw_and_debit(): void
    {
        $caisse = $this->makeMainCaisse(100_000);
        $account = $this->makeMerchandiseSalesAccount(100_000);

        $this->accountService->withdrawFromCaisseAndDebitAccount(
            caisse: $caisse, account: $account, amount: 30_000,
            caisseLabel: 'Retrait', accountLabel: 'Débit',
        );

        $this->assertGlobalInvariantHolds();
    }

    public function test_invariant_holds_after_multiple_deposits_and_withdrawals(): void
    {
        $caisse = $this->makeMainCaisse(0);
        $account = $this->makeMerchandiseSalesAccount(0);

        $this->accountService->depositToCaisseAndCreditAccount(caisse: $caisse, account: $account, amount: 200_000, caisseLabel: 'D1', accountLabel: 'C1');
        $this->accountService->depositToCaisseAndCreditAccount(caisse: $caisse, account: $account, amount: 50_000, caisseLabel: 'D2', accountLabel: 'C2');
        $this->accountService->withdrawFromCaisseAndDebitAccount(caisse: $caisse, account: $account, amount: 80_000, caisseLabel: 'R1', accountLabel: 'D1');
        $this->accountService->depositToCaisseAndCreditAccount(caisse: $caisse, account: $account, amount: 30_000, caisseLabel: 'D3', accountLabel: 'C3');

        $this->assertGlobalInvariantHolds();
    }

    public function test_invariant_holds_after_internal_account_transfer_no_caisse_change(): void
    {
        $caisse = $this->makeMainCaisse(100_000);
        $merchandiseSalesAccount = $this->makeMerchandiseSalesAccount(100_000);
        $depreciationAccount = Account::create([
            'name' => 'Amortissement test',
            'account_type' => AccountType::VehicleDepreciation,
            'balance' => 0,
            'is_active' => true,
        ]);

        // Internal reallocation: no caisse change
        $this->accountService->transferBetweenAccounts($merchandiseSalesAccount, $depreciationAccount, 5_000, 'Distribution');

        $this->assertGlobalInvariantHolds();
    }

    public function test_invariant_holds_with_multiple_caisses_and_accounts(): void
    {
        $caisse1 = $this->makeMainCaisse(80_000);
        $caisse2 = Caisse::create(['name' => 'Caisse 2', 'caisse_type' => CaisseType::Main, 'balance' => 40_000, 'closed' => false]);

        $account1 = $this->makeMerchandiseSalesAccount(70_000);
        $account2 = Account::create(['name' => 'Commission test', 'account_type' => AccountType::CommercialCommission, 'balance' => 50_000, 'is_active' => true]);

        $this->assertGlobalInvariantHolds();
    }

    public function test_invariant_holds_after_seed_with_existing_caisse_balances(): void
    {
        // Simulate the RefactorOldData scenario: main caisse has existing balance,
        // seed MERCHANDISE_SALES with that balance to satisfy the invariant.
        $existingBalance = 500_000;
        $caisse = $this->makeMainCaisse($existingBalance);

        $merchandiseSalesAccount = Account::create([
            'name' => 'Vente marchandises',
            'account_type' => AccountType::MerchandiseSales,
            'balance' => 0,
            'is_active' => true,
        ]);

        // Seed the account to match existing caisse balance
        AccountTransaction::create([
            'account_id' => $merchandiseSalesAccount->id,
            'amount' => $existingBalance,
            'transaction_type' => 'CREDIT',
            'label' => 'Solde initial',
            'reference_type' => 'INITIAL',
        ]);
        $merchandiseSalesAccount->increment('balance', $existingBalance);

        $this->assertGlobalInvariantHolds();
    }

    public function test_invariant_total_accounts_computes_correctly(): void
    {
        Account::create(['name' => 'A1', 'account_type' => AccountType::MerchandiseSales, 'balance' => 30_000, 'is_active' => true]);
        Account::create(['name' => 'A2', 'account_type' => AccountType::VehicleDepreciation, 'balance' => 15_000, 'is_active' => true]);

        $this->assertSame(45_000, $this->accountService->computeTotalAccountBalance());
    }

    public function test_invariant_total_caisses_computes_correctly(): void
    {
        Caisse::create(['name' => 'C1', 'balance' => 60_000, 'closed' => false]);
        Caisse::create(['name' => 'C2', 'balance' => 25_000, 'closed' => false]);

        $this->assertSame(85_000, $this->accountService->computeTotalCaisseBalance());
    }

    public function test_is_global_invariant_satisfied_returns_true_when_balanced(): void
    {
        Caisse::create(['name' => 'C', 'balance' => 50_000, 'closed' => false]);
        Account::create(['name' => 'A', 'account_type' => AccountType::MerchandiseSales, 'balance' => 50_000, 'is_active' => true]);

        $this->assertTrue($this->accountService->isGlobalInvariantSatisfied());
    }

    public function test_is_global_invariant_satisfied_returns_false_when_unbalanced(): void
    {
        Caisse::create(['name' => 'C', 'balance' => 50_000, 'closed' => false]);
        Account::create(['name' => 'A', 'account_type' => AccountType::MerchandiseSales, 'balance' => 49_999, 'is_active' => true]);

        $this->assertFalse($this->accountService->isGlobalInvariantSatisfied());
    }
}
