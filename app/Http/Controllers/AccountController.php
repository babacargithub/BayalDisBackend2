<?php

namespace App\Http\Controllers;

use App\Data\Account\AccountBalanceSummaryDTO;
use App\Enums\AccountType;
use App\Exceptions\InsufficientAccountBalanceException;
use App\Exceptions\InvariantException;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\TransferBetweenAccountsRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Models\Account;
use App\Models\Commercial;
use App\Models\Vehicle;
use App\Services\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function __construct(private readonly AccountService $accountService) {}

    public function index(): Response
    {
        $accounts = $this->accountService->getAllAccountsForDisplay();

        return Inertia::render('Account/Index', [
            'accounts' => $accounts->map(fn (Account $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'account_type' => $account->account_type->value,
                'account_type_label' => $account->account_type->label(),
                'balance' => $account->balance,
                'is_active' => $account->is_active,
                'vehicle_id' => $account->vehicle_id,
                'commercial_id' => $account->commercial_id,
                'linked_to' => $account->vehicle?->name ?? $account->commercial?->name,
                'updated_at' => $account->updated_at,
            ]),
            'totalBalance' => $accounts->sum('balance'),
            'balanceSummary' => AccountBalanceSummaryDTO::fromAccounts($accounts)->toArray(),
            'vehicles' => Vehicle::orderBy('name')->get(['id', 'name']),
            'commercials' => Commercial::orderBy('name')->get(['id', 'name']),
            'accountTypes' => collect(AccountType::cases())->map(fn (AccountType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'requires_vehicle' => $type->requiresVehicle(),
                'requires_commercial' => $type->requiresCommercial(),
            ]),
        ]);
    }

    public function store(StoreAccountRequest $request): RedirectResponse
    {
        $this->accountService->createAccount($request->validated());

        return back()->with('flash', ['success' => 'Compte créé avec succès.']);
    }

    public function update(UpdateAccountRequest $request, Account $account): RedirectResponse
    {
        $this->accountService->updateAccount($account, $request->validated());

        return back()->with('flash', ['success' => 'Compte mis à jour avec succès.']);
    }

    public function destroy(Account $account): RedirectResponse
    {
        try {
            $this->accountService->deleteAccount($account);
        } catch (\RuntimeException $exception) {
            return back()->with('flash', ['error' => $exception->getMessage()]);
        }

        return back()->with('flash', ['success' => 'Compte supprimé avec succès.']);
    }

    /**
     * Transfer an amount from one account to another.
     *
     * This is a pure account reallocation — no caisse is touched.
     * The global invariant SUM(account.balance) == SUM(caisse.balance) is preserved
     * because the debit and credit cancel out to a net zero change in total account balances.
     *
     * @throws \Throwable
     */
    public function transfer(TransferBetweenAccountsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $fromAccount = Account::findOrFail($validated['from_account_id']);
        $toAccount = Account::findOrFail($validated['to_account_id']);
        if ($fromAccount->id === $toAccount->id) {
            return back()->with('flash', ['error' => 'Impossible de faire un transfert sur le meme compte.']);

        }

        try {
            DB::transaction(function () use ($fromAccount, $toAccount, $validated) {
                $this->accountService->transferBetweenAccounts(
                    fromAccount: $fromAccount,
                    toAccount: $toAccount,
                    amount: $validated['amount'],
                    label: $validated['label'],
                    referenceType: 'INTER_ACCOUNT_TRANSFER',
                );

                $this->accountService->assertGlobalInvariantHolds();
            });
        } catch (InsufficientAccountBalanceException|InvariantException $exception) {
            return back()->with('flash', ['error' => $exception->getMessage()]);
        }

        return back()->with('flash', ['success' => 'Transfert entre comptes effectué avec succès.']);
    }

    /**
     * Return transactions for an account as JSON, with optional filters.
     * Called lazily when the transactions dialog opens — not on page load.
     *
     * Query params: date_from (Y-m-d), date_to (Y-m-d), type (CREDIT|DEBIT)
     */
    public function transactions(Request $request, Account $account): JsonResponse
    {
        $transactions = $this->accountService->getAccountTransactions(
            $account,
            $request->only(['date_from', 'date_to', 'type'])
        );

        return response()->json(['transactions' => $transactions]);
    }
}
