<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Models\Account;
use App\Models\Commercial;
use App\Models\Vehicle;
use App\Services\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
