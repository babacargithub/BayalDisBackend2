<?php

namespace App\Services;

use App\Enums\AccountTransactionType;
use App\Enums\AccountType;
use App\Exceptions\InsufficientAccountBalanceException;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Caisse;
use App\Models\CaisseTransaction;
use App\Models\Commercial;
use App\Models\Vehicle;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class AccountService
{
    // ── Display / management ─────────────────────────────────────────────────

    /**
     * Return all accounts with their vehicle/commercial relation names resolved.
     * Uses a single eager-loaded query — no N+1.
     */
    public function getAllAccountsForDisplay(): Collection
    {
        return Account::with(['vehicle:id,name', 'commercial:id,name'])
            ->orderBy('account_type')
            ->orderBy('name')
            ->get();
    }

    /**
     * Return transactions for an account, ordered newest-first.
     * Optionally filter by date range (date_from / date_to) and transaction type (CREDIT / DEBIT).
     * Transactions are NOT loaded on the index page — they are fetched lazily when the dialog opens.
     */
    public function getAccountTransactions(Account $account, array $filters = []): Collection
    {
        $query = $account->transactions()->orderByDesc('created_at');

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['type']) && in_array($filters['type'], ['CREDIT', 'DEBIT'], strict: true)) {
            $query->where('transaction_type', $filters['type']);
        }

        return $query->get();
    }

    /**
     * Create a new account with the given attributes (balance defaults to 0).
     */
    public function createAccount(array $attributes): Account
    {
        return Account::create(array_merge(['balance' => 0], $attributes));
    }

    /**
     * Update the editable fields of an account (name and is_active only).
     * Balance can never be changed directly — use credit/debit instead.
     */
    public function updateAccount(Account $account, array $attributes): Account
    {
        $account->update($attributes);

        return $account;
    }

    /**
     * Delete an account.
     *
     * @throws \RuntimeException if the account balance is non-zero, to prevent orphaned funds
     */
    public function deleteAccount(Account $account): void
    {
        if ($account->balance !== 0) {
            throw new \RuntimeException(
                "Impossible de supprimer le compte «{$account->name}» : solde non nul ({$account->balance} F). "
                .'Videz le solde avant de supprimer ce compte.'
            );
        }

        $account->delete();
    }

    // ── Pure account operations (no caisse change) ──────────────────────────

    /**
     * Credit an account — money flows IN, balance increases.
     *
     * Use this for internal reallocations (e.g., daily cost distribution,
     * allocating commission proceeds after a versement).
     * Does NOT touch any caisse balance.
     *
     * @throws InvalidArgumentException if amount is not positive
     */
    public function credit(
        Account $account,
        int $amount,
        string $label,
        string $referenceType = 'MANUAL',
        ?int $referenceId = null,
    ): AccountTransaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException(
                "Le montant à créditer doit être un entier strictement positif. Reçu : {$amount}."
            );
        }

        $accountTransaction = $account->transactions()->create([
            'amount' => $amount,
            'transaction_type' => AccountTransactionType::Credit,
            'label' => $label,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);

        $account->increment('balance', $amount);

        return $accountTransaction;
    }

    /**
     * Debit an account — money flows OUT, balance decreases.
     *
     * Use this for internal reallocations (e.g., daily cost distribution
     * drawing from the MERCHANDISE_SALES pool).
     * Does NOT touch any caisse balance.
     *
     * @throws InvalidArgumentException if amount is not positive
     * @throws InsufficientAccountBalanceException if the debit would make balance negative
     */
    public function debit(
        Account $account,
        int $amount,
        string $label,
        string $referenceType = 'MANUAL',
        ?int $referenceId = null,
    ): AccountTransaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException(
                "Le montant à débiter doit être un entier strictement positif. Reçu : {$amount}."
            );
        }

        // Reload to get the latest cached balance before comparing.
        $account->refresh();

        if ($account->balance < $amount) {
            throw new InsufficientAccountBalanceException(
                "Solde insuffisant pour le compte «{$account->name}». "
                ."Disponible : {$account->balance} F — Requis : {$amount} F."
            );
        }

        $accountTransaction = $account->transactions()->create([
            'amount' => $amount,
            'transaction_type' => AccountTransactionType::Debit,
            'label' => $label,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);

        $account->decrement('balance', $amount);

        return $accountTransaction;
    }

    /**
     * Transfer an amount from one account to another without touching any caisse.
     *
     * The total of all account balances does not change.
     * Typical use: daily cost distribution (MERCHANDISE_SALES → vehicle accounts).
     *
     * @return array{debit: AccountTransaction, credit: AccountTransaction}
     *
     * @throws InsufficientAccountBalanceException if the source account has insufficient balance
     */
    public function transferBetweenAccounts(
        Account $fromAccount,
        Account $toAccount,
        int $amount,
        string $label,
        string $referenceType = 'MANUAL',
        ?int $referenceId = null,
    ): array {
        $debitTransaction = $this->debit($fromAccount, $amount, $label, $referenceType, $referenceId);
        $creditTransaction = $this->credit($toAccount, $amount, $label, $referenceType, $referenceId);

        return [
            'debit' => $debitTransaction,
            'credit' => $creditTransaction,
        ];
    }

    // ── Paired caisse + account operations ──────────────────────────────────

    /**
     * Deposit into a caisse AND simultaneously credit an account.
     *
     * These two operations MUST always be performed together to preserve the
     * company-wide invariant: SUM(account balances) == SUM(caisse balances).
     *
     * @return array{caisse_transaction: CaisseTransaction, account_transaction: AccountTransaction}
     */
    public function depositToCaisseAndCreditAccount(
        Caisse $caisse,
        Account $account,
        int $amount,
        string $caisseLabel,
        string $accountLabel,
        string $referenceType = 'MANUAL',
        ?int $referenceId = null,
    ): array {
        if ($amount <= 0) {
            throw new InvalidArgumentException(
                "Le montant doit être un entier strictement positif. Reçu : {$amount}."
            );
        }

        $caisseTransaction = $caisse->transactions()->create([
            'amount' => $amount,
            'transaction_type' => Caisse::TRANSACTION_TYPE_DEPOSIT,
            'label' => $caisseLabel,
        ]);
        $caisse->increment('balance', $amount);

        $accountTransaction = $this->credit($account, $amount, $accountLabel, $referenceType, $referenceId);

        return [
            'caisse_transaction' => $caisseTransaction,
            'account_transaction' => $accountTransaction,
        ];
    }

    /**
     * Withdraw from a caisse AND simultaneously debit an account.
     *
     * These two operations MUST always be performed together to preserve the
     * company-wide invariant: SUM(account balances) == SUM(caisse balances).
     *
     * @return array{caisse_transaction: CaisseTransaction, account_transaction: AccountTransaction}
     *
     * @throws InsufficientAccountBalanceException if the account has insufficient balance
     */
    public function withdrawFromCaisseAndDebitAccount(
        Caisse $caisse,
        Account $account,
        int $amount,
        string $caisseLabel,
        string $accountLabel,
        string $referenceType = 'MANUAL',
        ?int $referenceId = null,
    ): array {
        if ($amount <= 0) {
            throw new InvalidArgumentException(
                "Le montant doit être un entier strictement positif. Reçu : {$amount}."
            );
        }

        $accountTransaction = $this->debit($account, $amount, $accountLabel, $referenceType, $referenceId);

        $caisseTransaction = $caisse->transactions()->create([
            'amount' => $amount,
            'transaction_type' => Caisse::TRANSACTION_TYPE_WITHDRAW,
            'label' => $caisseLabel,
        ]);
        $caisse->decrement('balance', $amount);

        return [
            'caisse_transaction' => $caisseTransaction,
            'account_transaction' => $accountTransaction,
        ];
    }

    // ── Invariant verification ───────────────────────────────────────────────

    /**
     * Returns the sum of all cached account balances across the company.
     * Must always equal computeTotalCaisseBalance().
     */
    public function computeTotalAccountBalance(): int
    {
        return Account::sum('balance');
    }

    /**
     * Returns the sum of all caisse balances across the company.
     */
    public function computeTotalCaisseBalance(): int
    {
        return Caisse::sum('balance');
    }

    /**
     * Returns true when the company-wide invariant holds:
     * SUM(account.balance) == SUM(caisse.balance)
     */
    public function isGlobalInvariantSatisfied(): bool
    {
        return $this->computeTotalAccountBalance() === $this->computeTotalCaisseBalance();
    }

    // ── Account lookup / provisioning ───────────────────────────────────────

    /**
     * Returns the single MERCHANDISE_SALES account.
     *
     * @throws \RuntimeException if the account does not exist
     */
    public function getMerchandiseSalesAccount(): Account
    {
        $merchandiseSalesAccount = Account::where('account_type', AccountType::MerchandiseSales)->first();

        if ($merchandiseSalesAccount === null) {
            throw new \RuntimeException(
                'Le compte "Vente marchandises" est introuvable. '
                .'Exécutez `php artisan bayal:refactor-old-data` pour initialiser les comptes.'
            );
        }

        return $merchandiseSalesAccount;
    }

    /**
     * Get or create the COMMERCIAL_COMMISSION account for a commercial.
     */
    public function getOrCreateCommercialCommissionAccount(Commercial $commercial): Account
    {
        return Account::firstOrCreate(
            [
                'account_type' => AccountType::CommercialCommission->value,
                'commercial_id' => $commercial->id,
            ],
            [
                'name' => "Commission — {$commercial->name}",
                'balance' => 0,
                'is_active' => true,
            ]
        );
    }

    /**
     * Get or create the COMMERCIAL_COLLECTED account for a commercial.
     */
    public function getOrCreateCommercialCollectedAccount(Commercial $commercial): Account
    {
        return Account::firstOrCreate(
            [
                'account_type' => AccountType::CommercialCollected->value,
                'commercial_id' => $commercial->id,
            ],
            [
                'name' => "Encaissements — {$commercial->name}",
                'balance' => 0,
                'is_active' => true,
            ]
        );
    }

    /**
     * Get or create a vehicle cost account by AccountType.
     *
     * @throws InvalidArgumentException if the type does not require a vehicle
     */
    public function getOrCreateVehicleAccount(Vehicle $vehicle, AccountType $accountType): Account
    {
        if (! $accountType->requiresVehicle()) {
            throw new InvalidArgumentException(
                "Le type de compte [{$accountType->value}] ne correspond pas à un compte véhicule."
            );
        }

        return Account::firstOrCreate(
            [
                'account_type' => $accountType->value,
                'vehicle_id' => $vehicle->id,
            ],
            [
                'name' => "{$accountType->label()} — {$vehicle->name}",
                'balance' => 0,
                'is_active' => true,
            ]
        );
    }

    /**
     * Get or create a FIXED_COST account identified by its label and sub-category.
     * Fixed cost accounts are not tied to a vehicle or commercial.
     */
    public function getOrCreateFixedCostAccount(string $label): Account
    {
        return Account::firstOrCreate(
            [
                'account_type' => AccountType::FixedCost->value,
                'name' => $label,
            ],
            [
                'balance' => 0,
                'is_active' => true,
            ]
        );
    }
}
