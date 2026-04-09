<?php

namespace Tests\Feature\Account;

use App\Enums\AccountTransactionType;
use App\Enums\AccountType;
use App\Enums\CaisseType;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Caisse;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the inter-account transfer feature.
 *
 * An inter-account transfer reallocates funds between two Account records
 * without touching any Caisse. The global invariant
 * SUM(account.balance) == SUM(caisse.balance) must hold after every transfer.
 */
class InterAccountTransferTest extends TestCase
{
    use RefreshDatabase;

    private AccountService $accountService;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountService = app(AccountService::class);
        $this->user = User::factory()->create();
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

    /**
     * Create an account with the given initial balance and a matching CREDIT transaction,
     * so that updateBalanceFromLedger() recomputes correctly after subsequent operations.
     */
    private function makeAccountWithInitialBalance(string $name, AccountType $accountType, int $initialBalance): Account
    {
        $account = Account::create([
            'name' => $name,
            'account_type' => $accountType,
            'balance' => $initialBalance,
            'is_active' => true,
        ]);

        if ($initialBalance > 0) {
            AccountTransaction::create([
                'account_id' => $account->id,
                'amount' => $initialBalance,
                'transaction_type' => AccountTransactionType::Credit,
                'label' => 'Solde initial',
                'reference_type' => 'INITIAL',
            ]);
        }

        return $account;
    }

    private function makeMerchandiseSalesAccount(int $balance): Account
    {
        return $this->makeAccountWithInitialBalance('Vente marchandises', AccountType::MerchandiseSales, $balance);
    }

    private function makeVehicleDepreciationAccount(int $balance = 0): Account
    {
        return $this->makeAccountWithInitialBalance('Amortissement véhicule', AccountType::VehicleDepreciation, $balance);
    }

    private function makeMainCaisse(int $balance): Caisse
    {
        return Caisse::create([
            'name' => 'Caisse principale',
            'caisse_type' => CaisseType::Main,
            'balance' => $balance,
            'closed' => false,
        ]);
    }

    // ── HTTP endpoint tests ───────────────────────────────────────────────────

    public function test_transfer_succeeds_and_redirects_with_success_flash(): void
    {
        $sourceAccount = $this->makeMerchandiseSalesAccount(100_000);
        $destinationAccount = $this->makeVehicleDepreciationAccount(0);
        $this->makeMainCaisse(100_000);

        $response = $this->actingAs($this->user)->post(route('accounts.transfer'), [
            'from_account_id' => $sourceAccount->id,
            'to_account_id' => $destinationAccount->id,
            'amount' => 30_000,
            'label' => 'Réserve amortissement Q1',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('flash.success');
    }

    public function test_transfer_deducts_from_source_account_and_credits_destination_account(): void
    {
        $sourceAccount = $this->makeMerchandiseSalesAccount(200_000);
        $destinationAccount = $this->makeVehicleDepreciationAccount(0);
        $this->makeMainCaisse(200_000);

        $this->actingAs($this->user)->post(route('accounts.transfer'), [
            'from_account_id' => $sourceAccount->id,
            'to_account_id' => $destinationAccount->id,
            'amount' => 50_000,
            'label' => 'Dotation amortissement',
        ]);

        $this->assertSame(150_000, $sourceAccount->fresh()->balance);
        $this->assertSame(50_000, $destinationAccount->fresh()->balance);
    }

    public function test_transfer_creates_debit_transaction_on_source_account(): void
    {
        $sourceAccount = $this->makeMerchandiseSalesAccount(80_000);
        $destinationAccount = $this->makeVehicleDepreciationAccount(0);
        $this->makeMainCaisse(80_000);

        $this->actingAs($this->user)->post(route('accounts.transfer'), [
            'from_account_id' => $sourceAccount->id,
            'to_account_id' => $destinationAccount->id,
            'amount' => 20_000,
            'label' => 'Provision véhicule',
        ]);

        $this->assertDatabaseHas('account_transactions', [
            'account_id' => $sourceAccount->id,
            'amount' => 20_000,
            'transaction_type' => 'DEBIT',
            'label' => 'Provision véhicule',
            'reference_type' => 'INTER_ACCOUNT_TRANSFER',
        ]);
    }

    public function test_transfer_creates_credit_transaction_on_destination_account(): void
    {
        $sourceAccount = $this->makeMerchandiseSalesAccount(80_000);
        $destinationAccount = $this->makeVehicleDepreciationAccount(0);
        $this->makeMainCaisse(80_000);

        $this->actingAs($this->user)->post(route('accounts.transfer'), [
            'from_account_id' => $sourceAccount->id,
            'to_account_id' => $destinationAccount->id,
            'amount' => 20_000,
            'label' => 'Provision véhicule',
        ]);

        $this->assertDatabaseHas('account_transactions', [
            'account_id' => $destinationAccount->id,
            'amount' => 20_000,
            'transaction_type' => 'CREDIT',
            'label' => 'Provision véhicule',
            'reference_type' => 'INTER_ACCOUNT_TRANSFER',
        ]);
    }

    public function test_transfer_preserves_global_invariant(): void
    {
        $this->makeMainCaisse(300_000);
        $sourceAccount = $this->makeMerchandiseSalesAccount(300_000);
        $destinationAccount = $this->makeVehicleDepreciationAccount(0);

        $this->assertGlobalInvariantHolds();

        $this->actingAs($this->user)->post(route('accounts.transfer'), [
            'from_account_id' => $sourceAccount->id,
            'to_account_id' => $destinationAccount->id,
            'amount' => 75_000,
            'label' => 'Distribution coûts',
        ]);

        $this->assertGlobalInvariantHolds();
    }

    public function test_transfer_preserves_total_account_balance(): void
    {
        $sourceAccount = $this->makeMerchandiseSalesAccount(120_000);
        $destinationAccount = $this->makeVehicleDepreciationAccount(30_000);

        $totalBefore = Account::sum('balance');

        $this->actingAs($this->user)->post(route('accounts.transfer'), [
            'from_account_id' => $sourceAccount->id,
            'to_account_id' => $destinationAccount->id,
            'amount' => 40_000,
            'label' => 'Réaffectation',
        ]);

        $totalAfter = Account::sum('balance');
        $this->assertSame($totalBefore, $totalAfter);
    }

    // ── Validation failures ───────────────────────────────────────────────────

    public function test_transfer_fails_validation_when_source_and_destination_are_the_same_account(): void
    {
        $account = $this->makeMerchandiseSalesAccount(50_000);

        $response = $this->actingAs($this->user)->post(route('accounts.transfer'), [
            'from_account_id' => $account->id,
            'to_account_id' => $account->id,
            'amount' => 10_000,
            'label' => 'Transfert même compte',
        ]);

        $response->assertSessionHasErrors('to_account_id');
    }

    public function test_transfer_fails_validation_when_amount_is_zero(): void
    {
        $sourceAccount = $this->makeMerchandiseSalesAccount(50_000);
        $destinationAccount = $this->makeVehicleDepreciationAccount(0);

        $response = $this->actingAs($this->user)->post(route('accounts.transfer'), [
            'from_account_id' => $sourceAccount->id,
            'to_account_id' => $destinationAccount->id,
            'amount' => 0,
            'label' => 'Montant zéro',
        ]);

        $response->assertSessionHasErrors('amount');
    }

    public function test_transfer_fails_validation_when_amount_is_negative(): void
    {
        $sourceAccount = $this->makeMerchandiseSalesAccount(50_000);
        $destinationAccount = $this->makeVehicleDepreciationAccount(0);

        $response = $this->actingAs($this->user)->post(route('accounts.transfer'), [
            'from_account_id' => $sourceAccount->id,
            'to_account_id' => $destinationAccount->id,
            'amount' => -5_000,
            'label' => 'Montant négatif',
        ]);

        $response->assertSessionHasErrors('amount');
    }

    public function test_transfer_fails_validation_when_label_is_missing(): void
    {
        $sourceAccount = $this->makeMerchandiseSalesAccount(50_000);
        $destinationAccount = $this->makeVehicleDepreciationAccount(0);

        $response = $this->actingAs($this->user)->post(route('accounts.transfer'), [
            'from_account_id' => $sourceAccount->id,
            'to_account_id' => $destinationAccount->id,
            'amount' => 10_000,
        ]);

        $response->assertSessionHasErrors('label');
    }

    public function test_transfer_fails_validation_when_source_account_does_not_exist(): void
    {
        $destinationAccount = $this->makeVehicleDepreciationAccount(0);

        $response = $this->actingAs($this->user)->post(route('accounts.transfer'), [
            'from_account_id' => 99999,
            'to_account_id' => $destinationAccount->id,
            'amount' => 10_000,
            'label' => 'Compte inexistant',
        ]);

        $response->assertSessionHasErrors('from_account_id');
    }

    // ── Insufficient balance ──────────────────────────────────────────────────

    public function test_transfer_fails_with_error_flash_when_source_account_has_insufficient_balance(): void
    {
        $sourceAccount = $this->makeMerchandiseSalesAccount(5_000);
        $destinationAccount = $this->makeVehicleDepreciationAccount(0);

        $response = $this->actingAs($this->user)->post(route('accounts.transfer'), [
            'from_account_id' => $sourceAccount->id,
            'to_account_id' => $destinationAccount->id,
            'amount' => 10_000,
            'label' => 'Solde insuffisant',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('flash.error');
    }

    public function test_transfer_does_not_alter_balances_when_source_has_insufficient_balance(): void
    {
        $sourceAccount = $this->makeMerchandiseSalesAccount(5_000);
        $destinationAccount = $this->makeVehicleDepreciationAccount(0);

        $this->actingAs($this->user)->post(route('accounts.transfer'), [
            'from_account_id' => $sourceAccount->id,
            'to_account_id' => $destinationAccount->id,
            'amount' => 10_000,
            'label' => 'Solde insuffisant',
        ]);

        $this->assertSame(5_000, $sourceAccount->fresh()->balance);
        $this->assertSame(0, $destinationAccount->fresh()->balance);
    }

    // ── Boundary cases ────────────────────────────────────────────────────────

    public function test_transfer_of_entire_source_balance_leaves_source_at_zero(): void
    {
        $sourceAccount = $this->makeMerchandiseSalesAccount(40_000);
        $destinationAccount = $this->makeVehicleDepreciationAccount(0);
        $this->makeMainCaisse(40_000);

        $this->actingAs($this->user)->post(route('accounts.transfer'), [
            'from_account_id' => $sourceAccount->id,
            'to_account_id' => $destinationAccount->id,
            'amount' => 40_000,
            'label' => 'Vider le compte',
        ]);

        $this->assertSame(0, $sourceAccount->fresh()->balance);
        $this->assertSame(40_000, $destinationAccount->fresh()->balance);
    }

    public function test_multiple_sequential_transfers_update_balances_correctly(): void
    {
        $merchandiseSalesAccount = $this->makeMerchandiseSalesAccount(300_000);
        $depreciationAccount = $this->makeVehicleDepreciationAccount(0);
        $fuelAccount = $this->makeAccountWithInitialBalance('Carburant', AccountType::VehicleFuel, 0);
        $this->makeMainCaisse(300_000);

        $this->actingAs($this->user)->post(route('accounts.transfer'), [
            'from_account_id' => $merchandiseSalesAccount->id,
            'to_account_id' => $depreciationAccount->id,
            'amount' => 50_000,
            'label' => 'Amortissement',
        ]);

        $this->actingAs($this->user)->post(route('accounts.transfer'), [
            'from_account_id' => $merchandiseSalesAccount->id,
            'to_account_id' => $fuelAccount->id,
            'amount' => 30_000,
            'label' => 'Carburant semaine',
        ]);

        $this->assertSame(220_000, $merchandiseSalesAccount->fresh()->balance);
        $this->assertSame(50_000, $depreciationAccount->fresh()->balance);
        $this->assertSame(30_000, $fuelAccount->fresh()->balance);
        $this->assertGlobalInvariantHolds();
    }

    // ── Invariant violation guard ─────────────────────────────────────────────

    public function test_transfer_fails_and_rolls_back_when_initial_state_violates_invariant(): void
    {
        // Deliberately unbalanced: accounts total 50000, caisses total 0.
        // Any transfer attempt should be blocked and rolled back.
        $sourceAccount = $this->makeMerchandiseSalesAccount(50_000);
        $destinationAccount = $this->makeVehicleDepreciationAccount(0);

        $response = $this->actingAs($this->user)->post(route('accounts.transfer'), [
            'from_account_id' => $sourceAccount->id,
            'to_account_id' => $destinationAccount->id,
            'amount' => 10_000,
            'label' => 'État initial non balancé',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('flash.error');

        // Balances unchanged because the transaction was rolled back.
        $this->assertSame(50_000, $sourceAccount->fresh()->balance);
        $this->assertSame(0, $destinationAccount->fresh()->balance);
    }

    // ── Unauthenticated access ────────────────────────────────────────────────

    public function test_transfer_endpoint_requires_authentication(): void
    {
        $sourceAccount = $this->makeMerchandiseSalesAccount(50_000);
        $destinationAccount = $this->makeVehicleDepreciationAccount(0);

        $response = $this->post(route('accounts.transfer'), [
            'from_account_id' => $sourceAccount->id,
            'to_account_id' => $destinationAccount->id,
            'amount' => 10_000,
            'label' => 'Non authentifié',
        ]);

        $response->assertRedirect(route('login'));
    }
}
