<?php

namespace Tests\Feature\Caisse;

use App\Enums\AccountTransactionType;
use App\Enums\AccountType;
use App\Enums\CaisseType;
use App\Models\Account;
use App\Models\Caisse;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exhaustive tests for the "Entrée de caisse" (cash-in) feature.
 *
 * Financial invariant under test: SUM(caisse.balance) == SUM(account.balance)
 * must hold before and after every operation. Any violation means money was
 * created or destroyed in the ledger.
 *
 * Coverage:
 *   - Service layer: amounts, accumulation, ledger integrity, transactions
 *   - Service interplay: entrée + sortie, multi-caisse, multi-account
 *   - HTTP controller: validation rules, auth guard, success/error responses
 */
class EntreeDeCaisseTest extends TestCase
{
    use RefreshDatabase;

    private AccountService $accountService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountService = app(AccountService::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

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

    private function makeMainCaisse(string $name = 'Caisse principale'): Caisse
    {
        return Caisse::create([
            'name' => $name,
            'caisse_type' => CaisseType::Main,
            'balance' => 0,
            'closed' => false,
        ]);
    }

    private function makeAccount(string $name = 'Emprunt'): Account
    {
        return Account::create([
            'name' => $name,
            'account_type' => AccountType::Management,
            'balance' => 0,
            'is_active' => true,
        ]);
    }

    // =========================================================================
    // SERVICE LAYER — Basic behaviour
    // =========================================================================

    public function test_single_entree_increases_caisse_balance_by_exact_amount(): void
    {
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount();

        $this->accountService->processEntreeDeCaisse($caisse, $account, 376_998, 'Emprunt');

        $caisse->refresh();
        $this->assertSame(376_998, $caisse->balance);
    }

    public function test_single_entree_increases_account_balance_by_exact_amount(): void
    {
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount();

        $this->accountService->processEntreeDeCaisse($caisse, $account, 376_998, 'Emprunt');

        $account->refresh();
        $this->assertSame(376_998, $account->balance);
    }

    public function test_invariant_holds_after_single_entree(): void
    {
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount();

        $this->assertGlobalInvariantHolds();

        $this->accountService->processEntreeDeCaisse($caisse, $account, 100_000, 'Emprunt');

        $this->assertGlobalInvariantHolds();
    }

    // =========================================================================
    // SERVICE LAYER — Boundary amounts
    // =========================================================================

    public function test_minimum_valid_amount_of_one_franc_is_accepted(): void
    {
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount();

        $this->accountService->processEntreeDeCaisse($caisse, $account, 1, 'Un franc');

        $caisse->refresh();
        $account->refresh();

        $this->assertSame(1, $caisse->balance);
        $this->assertSame(1, $account->balance);
        $this->assertGlobalInvariantHolds();
    }

    public function test_large_amount_is_handled_correctly(): void
    {
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount();
        $largeAmount = 10_000_000;

        $this->accountService->processEntreeDeCaisse($caisse, $account, $largeAmount, 'Gros emprunt');

        $caisse->refresh();
        $account->refresh();

        $this->assertSame($largeAmount, $caisse->balance);
        $this->assertSame($largeAmount, $account->balance);
        $this->assertGlobalInvariantHolds();
    }

    public function test_zero_amount_is_rejected_with_invalid_argument_exception(): void
    {
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount();

        $this->expectException(\InvalidArgumentException::class);

        $this->accountService->processEntreeDeCaisse($caisse, $account, 0, 'Zéro');
    }

    public function test_negative_amount_is_rejected_with_invalid_argument_exception(): void
    {
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount();

        $this->expectException(\InvalidArgumentException::class);

        $this->accountService->processEntreeDeCaisse($caisse, $account, -5_000, 'Négatif');
    }

    public function test_caisse_and_account_are_unchanged_after_zero_amount_rejection(): void
    {
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount();

        try {
            $this->accountService->processEntreeDeCaisse($caisse, $account, 0, 'Zéro');
        } catch (\InvalidArgumentException) {
        }

        $caisse->refresh();
        $account->refresh();

        $this->assertSame(0, $caisse->balance);
        $this->assertSame(0, $account->balance);
        $this->assertGlobalInvariantHolds();
    }

    // =========================================================================
    // SERVICE LAYER — Accumulation
    // =========================================================================

    public function test_two_consecutive_entrees_accumulate_on_same_caisse_and_account(): void
    {
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount();

        $this->accountService->processEntreeDeCaisse($caisse, $account, 1_345_002, 'Balance initiale');
        $this->accountService->processEntreeDeCaisse($caisse, $account, 376_998, 'Emprunt complémentaire');

        $caisse->refresh();
        $account->refresh();

        $this->assertSame(1_722_000, $caisse->balance);
        $this->assertSame(1_722_000, $account->balance);
        $this->assertGlobalInvariantHolds();
    }

    public function test_three_consecutive_entrees_accumulate_correctly(): void
    {
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount();

        $this->accountService->processEntreeDeCaisse($caisse, $account, 100_000, 'Entrée 1');
        $this->accountService->processEntreeDeCaisse($caisse, $account, 200_000, 'Entrée 2');
        $this->accountService->processEntreeDeCaisse($caisse, $account, 50_000, 'Entrée 3');

        $caisse->refresh();
        $account->refresh();

        $this->assertSame(350_000, $caisse->balance);
        $this->assertSame(350_000, $account->balance);
        $this->assertGlobalInvariantHolds();
    }

    // =========================================================================
    // SERVICE LAYER — Transaction records
    // =========================================================================

    public function test_entree_creates_exactly_one_deposit_transaction_on_caisse(): void
    {
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount();

        $this->accountService->processEntreeDeCaisse($caisse, $account, 50_000, 'Test');

        $this->assertSame(1, $caisse->transactions()->count());
    }

    public function test_caisse_transaction_has_correct_type_and_amount(): void
    {
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount();

        $this->accountService->processEntreeDeCaisse($caisse, $account, 75_000, 'Dépôt test');

        $caisseTransaction = $caisse->transactions()->first();

        $this->assertSame(75_000, $caisseTransaction->amount);
        $this->assertSame('DEPOSIT', $caisseTransaction->transaction_type);
    }

    public function test_caisse_transaction_label_matches_provided_label(): void
    {
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount();
        $label = 'Emprunt partenaire pour paiement facture';

        $this->accountService->processEntreeDeCaisse($caisse, $account, 50_000, $label);

        $caisseTransaction = $caisse->transactions()->first();

        $this->assertSame($label, $caisseTransaction->label);
    }

    public function test_entree_creates_exactly_one_credit_transaction_on_account(): void
    {
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount();

        $this->accountService->processEntreeDeCaisse($caisse, $account, 50_000, 'Test');

        $this->assertSame(1, $account->transactions()->count());
    }

    public function test_account_transaction_has_type_credit(): void
    {
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount();

        $this->accountService->processEntreeDeCaisse($caisse, $account, 50_000, 'Test');

        $accountTransaction = $account->transactions()->first();

        $this->assertSame(AccountTransactionType::Credit, $accountTransaction->transaction_type);
    }

    public function test_account_transaction_has_correct_amount(): void
    {
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount();

        $this->accountService->processEntreeDeCaisse($caisse, $account, 80_000, 'Test');

        $accountTransaction = $account->transactions()->first();

        $this->assertSame(80_000, $accountTransaction->amount);
    }

    public function test_account_transaction_reference_type_is_entree_de_caisse(): void
    {
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount();

        $this->accountService->processEntreeDeCaisse($caisse, $account, 50_000, 'Test');

        $accountTransaction = $account->transactions()->first();

        $this->assertSame('ENTREE_DE_CAISSE', $accountTransaction->reference_type);
    }

    public function test_account_transaction_label_matches_provided_label(): void
    {
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount();
        $label = 'Emprunt partenaire';

        $this->accountService->processEntreeDeCaisse($caisse, $account, 50_000, $label);

        $accountTransaction = $account->transactions()->first();

        $this->assertSame($label, $accountTransaction->label);
    }

    public function test_three_entrees_create_three_caisse_transactions_and_three_account_transactions(): void
    {
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount();

        $this->accountService->processEntreeDeCaisse($caisse, $account, 10_000, 'E1');
        $this->accountService->processEntreeDeCaisse($caisse, $account, 20_000, 'E2');
        $this->accountService->processEntreeDeCaisse($caisse, $account, 30_000, 'E3');

        $this->assertSame(3, $caisse->transactions()->count());
        $this->assertSame(3, $account->transactions()->count());
    }

    // =========================================================================
    // SERVICE LAYER — Ledger integrity (balance == SUM of transactions)
    // =========================================================================

    public function test_caisse_balance_equals_sum_of_its_deposit_transactions(): void
    {
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount();

        $this->accountService->processEntreeDeCaisse($caisse, $account, 100_000, 'E1');
        $this->accountService->processEntreeDeCaisse($caisse, $account, 50_000, 'E2');
        $this->accountService->processEntreeDeCaisse($caisse, $account, 75_000, 'E3');

        $caisse->refresh();
        $sumOfDeposits = $caisse->transactions()->where('transaction_type', 'DEPOSIT')->sum('amount');

        $this->assertSame($caisse->balance, (int) $sumOfDeposits);
    }

    public function test_account_balance_equals_sum_of_its_credit_transactions(): void
    {
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount();

        $this->accountService->processEntreeDeCaisse($caisse, $account, 100_000, 'E1');
        $this->accountService->processEntreeDeCaisse($caisse, $account, 50_000, 'E2');

        $account->refresh();
        $sumOfCredits = $account->transactions()
            ->where('transaction_type', AccountTransactionType::Credit->value)
            ->sum('amount');

        $this->assertSame($account->balance, (int) $sumOfCredits);
    }

    public function test_caisse_computed_balance_from_ledger_matches_cached_balance(): void
    {
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount();

        $this->accountService->processEntreeDeCaisse($caisse, $account, 123_456, 'Test');

        $caisse->refresh();
        $this->assertSame($caisse->balance, $caisse->updateBalanceFromLedger()->balance);
    }

    public function test_account_computed_balance_from_ledger_matches_cached_balance(): void
    {
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount();

        $this->accountService->processEntreeDeCaisse($caisse, $account, 123_456, 'Test');

        $account->refresh();
        $this->assertSame($account->balance, $account->computeBalanceFromLedger());
    }

    // =========================================================================
    // SERVICE LAYER — Multi-entity scenarios
    // =========================================================================

    public function test_multiple_entrees_from_different_accounts_to_same_caisse(): void
    {
        $caisse = $this->makeMainCaisse();
        $empruntAccount = $this->makeAccount('Emprunt');
        $capitalAccount = $this->makeAccount('Capital');

        $this->accountService->processEntreeDeCaisse($caisse, $empruntAccount, 200_000, 'Emprunt');
        $this->accountService->processEntreeDeCaisse($caisse, $capitalAccount, 100_000, 'Capital');

        $caisse->refresh();
        $empruntAccount->refresh();
        $capitalAccount->refresh();

        $this->assertSame(300_000, $caisse->balance);
        $this->assertSame(200_000, $empruntAccount->balance);
        $this->assertSame(100_000, $capitalAccount->balance);
        $this->assertGlobalInvariantHolds();
    }

    public function test_same_account_credited_across_multiple_caisses(): void
    {
        $caisse1 = $this->makeMainCaisse('Caisse principale');
        $caisse2 = $this->makeMainCaisse('Caisse secondaire');
        $empruntAccount = $this->makeAccount('Emprunt');

        $this->accountService->processEntreeDeCaisse($caisse1, $empruntAccount, 150_000, 'Dépôt caisse 1');
        $this->accountService->processEntreeDeCaisse($caisse2, $empruntAccount, 100_000, 'Dépôt caisse 2');

        $caisse1->refresh();
        $caisse2->refresh();
        $empruntAccount->refresh();

        $this->assertSame(150_000, $caisse1->balance);
        $this->assertSame(100_000, $caisse2->balance);
        $this->assertSame(250_000, $empruntAccount->balance);
        $this->assertGlobalInvariantHolds();
    }

    public function test_invariant_holds_across_multiple_caisses_and_multiple_accounts(): void
    {
        $caisse1 = $this->makeMainCaisse('Caisse A');
        $caisse2 = $this->makeMainCaisse('Caisse B');
        $account1 = $this->makeAccount('Emprunt 1');
        $account2 = $this->makeAccount('Emprunt 2');

        $this->accountService->processEntreeDeCaisse($caisse1, $account1, 100_000, 'Op 1');
        $this->assertGlobalInvariantHolds();

        $this->accountService->processEntreeDeCaisse($caisse2, $account2, 50_000, 'Op 2');
        $this->assertGlobalInvariantHolds();

        $this->accountService->processEntreeDeCaisse($caisse1, $account2, 200_000, 'Op 3');
        $this->assertGlobalInvariantHolds();

        $this->accountService->processEntreeDeCaisse($caisse2, $account1, 75_000, 'Op 4');
        $this->assertGlobalInvariantHolds();
    }

    // =========================================================================
    // SERVICE LAYER — Interplay with sortie de caisse
    // =========================================================================

    public function test_entree_followed_by_sortie_de_caisse_invariant_always_holds(): void
    {
        $caisse = $this->makeMainCaisse();
        $empruntAccount = $this->makeAccount('Emprunt');
        $depenseAccount = $this->makeAccount('Dépenses');

        // First seed the depense account with a balance that can be debited by the sortie.
        $this->accountService->processEntreeDeCaisse($caisse, $depenseAccount, 500_000, 'Seed');

        $this->assertGlobalInvariantHolds();

        // Now borrow money (entrée) on top.
        $this->accountService->processEntreeDeCaisse($caisse, $empruntAccount, 376_998, 'Emprunt');

        $this->assertGlobalInvariantHolds();
        $caisse->refresh();
        $this->assertSame(876_998, $caisse->balance);

        // Pay an invoice using sortie de caisse.
        $this->accountService->processSortieDeCaisse(
            caisse: $caisse,
            amount: 876_998,
            label: 'Paiement facture fournisseur',
            orderedAccountIds: [$depenseAccount->id, $empruntAccount->id],
        );

        $caisse->refresh();
        $this->assertSame(0, $caisse->balance);
        $this->assertGlobalInvariantHolds();
    }

    public function test_entree_brings_caisse_to_sufficient_balance_to_cover_payment(): void
    {
        $caisse = $this->makeMainCaisse();
        $existingFundsAccount = $this->makeAccount('Ventes');
        $empruntAccount = $this->makeAccount('Emprunt');

        // Seed existing balance (representing prior payment collections).
        $this->accountService->processEntreeDeCaisse($caisse, $existingFundsAccount, 1_345_002, 'Encaissements');
        $caisse->refresh();
        $this->assertSame(1_345_002, $caisse->balance);

        // Invoice total = 1_722_000, which exceeds the current caisse balance.
        $invoiceTotal = 1_722_000;
        $shortfall = $invoiceTotal - $caisse->balance;
        $this->assertSame(376_998, $shortfall);

        // Borrow the shortfall.
        $this->accountService->processEntreeDeCaisse($caisse, $empruntAccount, $shortfall, 'Emprunt pour facture');
        $caisse->refresh();
        $this->assertSame($invoiceTotal, $caisse->balance);

        $this->assertGlobalInvariantHolds();
    }

    // =========================================================================
    // HTTP CONTROLLER — Success response
    // =========================================================================

    public function test_entree_de_caisse_endpoint_returns_redirect_on_success(): void
    {
        $user = User::factory()->create();
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount('Emprunt');

        $response = $this->actingAs($user)->post(route('caisses.entree-de-caisse'), [
            'caisse_id' => $caisse->id,
            'account_id' => $account->id,
            'amount' => 100_000,
            'label' => 'Test HTTP',
        ]);

        $response->assertRedirect();
    }

    public function test_entree_de_caisse_endpoint_flashes_success_message(): void
    {
        $user = User::factory()->create();
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount('Emprunt');

        $response = $this->actingAs($user)->post(route('caisses.entree-de-caisse'), [
            'caisse_id' => $caisse->id,
            'account_id' => $account->id,
            'amount' => 100_000,
            'label' => 'Test HTTP',
        ]);

        $response->assertSessionHas('success');
    }

    public function test_entree_de_caisse_endpoint_increases_caisse_balance(): void
    {
        $user = User::factory()->create();
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount('Emprunt');

        $this->actingAs($user)->post(route('caisses.entree-de-caisse'), [
            'caisse_id' => $caisse->id,
            'account_id' => $account->id,
            'amount' => 250_000,
            'label' => 'Emprunt HTTP',
        ]);

        $this->assertSame(250_000, $caisse->fresh()->balance);
    }

    public function test_entree_de_caisse_endpoint_credits_account(): void
    {
        $user = User::factory()->create();
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount('Emprunt');

        $this->actingAs($user)->post(route('caisses.entree-de-caisse'), [
            'caisse_id' => $caisse->id,
            'account_id' => $account->id,
            'amount' => 250_000,
            'label' => 'Emprunt HTTP',
        ]);

        $this->assertSame(250_000, $account->fresh()->balance);
    }

    public function test_entree_de_caisse_endpoint_preserves_invariant(): void
    {
        $user = User::factory()->create();
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount('Emprunt');

        $this->actingAs($user)->post(route('caisses.entree-de-caisse'), [
            'caisse_id' => $caisse->id,
            'account_id' => $account->id,
            'amount' => 300_000,
            'label' => 'Invariant HTTP',
        ]);

        $this->assertGlobalInvariantHolds();
    }

    // =========================================================================
    // HTTP CONTROLLER — Authentication guard
    // =========================================================================

    public function test_unauthenticated_request_is_redirected_to_login(): void
    {
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount('Emprunt');

        $response = $this->post(route('caisses.entree-de-caisse'), [
            'caisse_id' => $caisse->id,
            'account_id' => $account->id,
            'amount' => 100_000,
            'label' => 'Non authentifié',
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_unauthenticated_request_does_not_modify_balances(): void
    {
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount('Emprunt');

        $this->post(route('caisses.entree-de-caisse'), [
            'caisse_id' => $caisse->id,
            'account_id' => $account->id,
            'amount' => 100_000,
            'label' => 'Non authentifié',
        ]);

        $this->assertSame(0, $caisse->fresh()->balance);
        $this->assertSame(0, $account->fresh()->balance);
    }

    // =========================================================================
    // HTTP CONTROLLER — Validation: missing required fields
    // =========================================================================

    public function test_missing_caisse_id_returns_validation_error(): void
    {
        $user = User::factory()->create();
        $account = $this->makeAccount('Emprunt');

        $response = $this->actingAs($user)->post(route('caisses.entree-de-caisse'), [
            'account_id' => $account->id,
            'amount' => 100_000,
            'label' => 'Test',
        ]);

        $response->assertSessionHasErrors('caisse_id');
    }

    public function test_missing_account_id_returns_validation_error(): void
    {
        $user = User::factory()->create();
        $caisse = $this->makeMainCaisse();

        $response = $this->actingAs($user)->post(route('caisses.entree-de-caisse'), [
            'caisse_id' => $caisse->id,
            'amount' => 100_000,
            'label' => 'Test',
        ]);

        $response->assertSessionHasErrors('account_id');
    }

    public function test_missing_amount_returns_validation_error(): void
    {
        $user = User::factory()->create();
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount('Emprunt');

        $response = $this->actingAs($user)->post(route('caisses.entree-de-caisse'), [
            'caisse_id' => $caisse->id,
            'account_id' => $account->id,
            'label' => 'Test',
        ]);

        $response->assertSessionHasErrors('amount');
    }

    public function test_missing_label_returns_validation_error(): void
    {
        $user = User::factory()->create();
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount('Emprunt');

        $response = $this->actingAs($user)->post(route('caisses.entree-de-caisse'), [
            'caisse_id' => $caisse->id,
            'account_id' => $account->id,
            'amount' => 100_000,
        ]);

        $response->assertSessionHasErrors('label');
    }

    // =========================================================================
    // HTTP CONTROLLER — Validation: invalid field values
    // =========================================================================

    public function test_amount_of_zero_returns_validation_error(): void
    {
        $user = User::factory()->create();
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount('Emprunt');

        $response = $this->actingAs($user)->post(route('caisses.entree-de-caisse'), [
            'caisse_id' => $caisse->id,
            'account_id' => $account->id,
            'amount' => 0,
            'label' => 'Test',
        ]);

        $response->assertSessionHasErrors('amount');
    }

    public function test_negative_amount_returns_validation_error(): void
    {
        $user = User::factory()->create();
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount('Emprunt');

        $response = $this->actingAs($user)->post(route('caisses.entree-de-caisse'), [
            'caisse_id' => $caisse->id,
            'account_id' => $account->id,
            'amount' => -50_000,
            'label' => 'Test',
        ]);

        $response->assertSessionHasErrors('amount');
    }

    public function test_non_integer_amount_returns_validation_error(): void
    {
        $user = User::factory()->create();
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount('Emprunt');

        $response = $this->actingAs($user)->post(route('caisses.entree-de-caisse'), [
            'caisse_id' => $caisse->id,
            'account_id' => $account->id,
            'amount' => 'beaucoup',
            'label' => 'Test',
        ]);

        $response->assertSessionHasErrors('amount');
    }

    public function test_non_existent_caisse_id_returns_validation_error(): void
    {
        $user = User::factory()->create();
        $account = $this->makeAccount('Emprunt');

        $response = $this->actingAs($user)->post(route('caisses.entree-de-caisse'), [
            'caisse_id' => 99999,
            'account_id' => $account->id,
            'amount' => 100_000,
            'label' => 'Test',
        ]);

        $response->assertSessionHasErrors('caisse_id');
    }

    public function test_non_existent_account_id_returns_validation_error(): void
    {
        $user = User::factory()->create();
        $caisse = $this->makeMainCaisse();

        $response = $this->actingAs($user)->post(route('caisses.entree-de-caisse'), [
            'caisse_id' => $caisse->id,
            'account_id' => 99999,
            'amount' => 100_000,
            'label' => 'Test',
        ]);

        $response->assertSessionHasErrors('account_id');
    }

    public function test_validation_errors_do_not_modify_caisse_balance(): void
    {
        $user = User::factory()->create();
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount('Emprunt');

        $this->actingAs($user)->post(route('caisses.entree-de-caisse'), [
            'caisse_id' => $caisse->id,
            'account_id' => $account->id,
            'amount' => 0,
            'label' => 'Test',
        ]);

        $this->assertSame(0, $caisse->fresh()->balance);
    }

    public function test_validation_errors_do_not_modify_account_balance(): void
    {
        $user = User::factory()->create();
        $caisse = $this->makeMainCaisse();
        $account = $this->makeAccount('Emprunt');

        $this->actingAs($user)->post(route('caisses.entree-de-caisse'), [
            'caisse_id' => $caisse->id,
            'account_id' => $account->id,
            'amount' => -1,
            'label' => 'Test',
        ]);

        $this->assertSame(0, $account->fresh()->balance);
    }
}
